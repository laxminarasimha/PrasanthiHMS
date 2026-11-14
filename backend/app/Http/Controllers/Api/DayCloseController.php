<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DayClose;
use App\Support\DayBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The manager's end-of-day screen: what is unfinished in every department, the money by
 * department and mode, the cash count, and the closing itself. A closed day's money records
 * are locked (see DayClose::lockMoney) until an admin reopens the day.
 */
class DayCloseController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->guard($request);
        $day = $this->day($request);

        return response()->json($this->present($request, $day));
    }

    /** Closes the day. Unfinished work must be settled first, or the person closing must say why it is left. */
    public function close(Request $request): JsonResponse
    {
        $this->guard($request);
        abort_unless($request->user()->allows('canCloseDay'), 403, 'You can see the day close, but you are not allowed to close a day.');
        $day = $this->day($request);
        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'variance_note' => ['nullable', 'string', 'max:1000'],
            'override_reason' => ['nullable', 'string', 'max:1000'],
        ], ['counted_cash.required' => 'Enter the cash counted in the drawer.']);

        $close = DB::transaction(function () use ($request, $day, $data) {
            if (DayClose::whereDate('day', $day->toDateString())->whereNull('reopened_at')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['day' => 'This day is already closed.']);
            }

            // The lock goes on first and the counting comes after, all in this one step. A bill that was
            // being saved finishes before the next line returns and is counted below; one that arrives
            // later waits for this step and is then refused. If a reason is missing, the step is undone.
            $close = DayClose::create([
                'day' => $day->toDateString(),
                'closed_by' => $request->user()->id, 'closed_by_name' => $request->user()->name, 'closed_at' => now(),
                'expected_cash' => 0, 'counted_cash' => round((float) $data['counted_cash'], 2), 'variance' => 0, 'snapshot' => [],
            ]);

            $book = DayBook::for($day);
            $variance = round((float) $data['counted_cash'] - $book['expected_cash'], 2);
            $errors = [];
            if (abs($variance) >= 0.01 && blank($data['variance_note'] ?? null)) {
                $errors['variance_note'] = 'The cash counted does not match the cash expected. Say why before closing.';
            }
            if ($book['blocking'] > 0 && mb_strlen(trim((string) ($data['override_reason'] ?? ''))) < 5) {
                $errors['override_reason'] = 'Some work is still unfinished. Settle it, or say why the day is being closed with it left open.';
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $close->update([
                'expected_cash' => $book['expected_cash'], 'variance' => $variance,
                'variance_note' => abs($variance) >= 0.01 ? trim($data['variance_note']) : null,
                'exceptions' => $book['blocking'],
                'override_reason' => $book['blocking'] > 0 ? trim($data['override_reason']) : null,
                'snapshot' => $book,
            ]);

            return $close;
        });

        AuditLog::record('day.closed', null, $close, $day->format('j M Y')
            .' · cash expected ₹'.number_format($close->expected_cash, 2).', counted ₹'.number_format($close->counted_cash, 2)
            .(abs($close->variance) >= 0.01 ? ', difference ₹'.number_format($close->variance, 2).' ('.$close->variance_note.')' : '')
            .($close->exceptions > 0 ? " · closed with {$close->exceptions} unfinished: {$close->override_reason}" : ''));

        return response()->json($this->present($request, $day), 201);
    }

    /** Opens a closed day again so its records can be corrected. The closing stays on record. */
    public function reopen(Request $request, DayClose $close): JsonResponse
    {
        abort_unless($request->user()->role === Role::Admin, 403, 'Only an admin can reopen a closed day.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']], [
            'reason.required' => 'Say why the day is being reopened.', 'reason.min' => 'Say why the day is being reopened.',
        ]);
        if ($close->reopened_at) {
            throw ValidationException::withMessages(['reason' => 'This day is already open.']);
        }

        $close->update([
            'reopened_by' => $request->user()->id, 'reopened_by_name' => $request->user()->name,
            'reopened_at' => now(), 'reopen_reason' => trim($data['reason']),
        ]);
        AuditLog::record('day.reopened', null, $close, $close->day->format('j M Y').' · '.$close->reopen_reason);

        return response()->json($this->present($request, $close->day));
    }

    private function present(Request $request, Carbon $day): array
    {
        $history = DayClose::whereDate('day', $day->toDateString())->latest('id')->get()
            // toArray, so the times go out as the hospital's clock reads them rather than as world time.
            ->map(fn (DayClose $c) => collect($c->toArray())->only([
                'id', 'closed_by_name', 'closed_at', 'expected_cash', 'counted_cash', 'variance', 'variance_note', 'exceptions',
                'override_reason', 'reopened_by_name', 'reopened_at', 'reopen_reason',
            ])->all());
        $closed = $history->first(fn ($c) => $c['reopened_at'] === null);

        // The last week at a glance, so a day nobody closed does not go unnoticed.
        $week = collect(range(1, 7))->map(fn ($back) => today()->subDays($back)->toDateString());
        $closedDays = DayClose::whereNull('reopened_at')->whereDate('day', '>=', $week->last())->get(['day'])->map(fn (DayClose $c) => $c->day->toDateString());

        return DayBook::for($day) + [
            'closed' => $closed,
            'history' => $history->values(),
            // The hospital's own date, so a computer set to another time zone still closes the right day.
            'today' => today()->toDateString(),
            'may_close' => $request->user()->allows('canCloseDay'),
            'can_close' => $closed === null && $request->user()->allows('canCloseDay'),
            'can_reopen' => $closed !== null && $request->user()->role === Role::Admin,
            'recent' => $week->map(fn ($d) => ['day' => $d, 'closed' => $closedDays->contains($d)])->values(),
        ];
    }

    private function day(Request $request): Carbon
    {
        $data = $request->validate(['day' => ['nullable', 'date', 'before_or_equal:today']], ['day.before_or_equal' => 'A day that has not come yet cannot be closed.']);

        return Carbon::parse($data['day'] ?? today())->startOfDay();
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()->allows('canViewReports'), 403, 'You are not allowed to see the day close.');
    }
}
