<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Expense;
use App\Models\Payment;
use App\Support\PaidOut;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The hospital's day-to-day expenses. Whoever keeps the cash writes down each payment out as it is made, so the
 * drawer agrees with the books at the end of the day. An entry is never erased: a wrong one is cancelled with a reason.
 */
class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $range = $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'category' => ['nullable', 'string', 'max:60'], 'q' => ['nullable', 'string', 'max:100'],
        ]);
        $from = Carbon::parse($range['from'] ?? today())->startOfDay();
        $to = Carbon::parse($range['to'] ?? $from)->endOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Choose a range of one year or less.');
        $term = trim((string) ($range['q'] ?? ''));

        $rows = Expense::whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])
            ->when($range['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('paid_to', 'like', "%{$term}%")->orWhere('description', 'like', "%{$term}%")->orWhere('expense_no', 'like', "{$term}%")))
            ->with(['creator:id,name', 'canceller:id,name'])
            ->orderByDesc('spent_on')->orderByDesc('id')->limit(2000)->get();

        return response()->json([
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'rows' => $rows,
            'paid_out' => PaidOut::between($from, $to),
            'categories' => $this->categories(),
            'modes' => Payment::MODES,
            'today' => today()->toDateString(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $expense = Expense::create($data + ['branch_id' => $request->user()->branch_id, 'created_by' => $request->user()->id]);
        AuditLog::record('expense.added', null, $expense, $this->words($expense->fresh()));

        return response()->json($expense->fresh()->load('creator:id,name'), 201);
    }

    /** Corrects an entry: the wrong amount, heading or mode. What it said before goes in the record. */
    public function update(Request $request, Expense $expense): JsonResponse
    {
        $this->refuseIfCancelled($expense);
        $before = $this->words($expense);
        $expense->update($this->validated($request));
        if (($after = $this->words($expense)) !== $before) {
            AuditLog::record('expense.changed', null, $expense, "Was: {$before}. Now: {$after}");
        }

        return response()->json($expense->load('creator:id,name'));
    }

    /** Cancels an entry made by mistake. It stays in the list, struck out, and no longer counts. */
    public function cancel(Request $request, Expense $expense): JsonResponse
    {
        $this->refuseIfCancelled($expense);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], ['reason.required' => 'Say why this entry is being cancelled.']);
        $expense->update(['cancelled_at' => now(), 'cancelled_by' => $request->user()->id, 'cancel_reason' => $data['reason']]);
        AuditLog::record('expense.cancelled', null, $expense, $this->words($expense).'. Reason: '.$data['reason']);

        return response()->json($expense->load(['creator:id,name', 'canceller:id,name']));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
            'category' => ['required', 'string', 'max:60'],
            'paid_to' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'mode' => ['required', Rule::in(Payment::MODES)],
            'reference' => ['nullable', 'string', 'max:255'],
        ], ['spent_on.before_or_equal' => 'An expense cannot be dated in the future.', 'category.required' => 'Say what the money was for.']);
    }

    /** The headings to offer: the standard ones first, then any the hospital has used of its own. */
    private function categories(): array
    {
        $own = Expense::query()->distinct()->orderBy('category')->pluck('category')->all();

        return array_values(array_unique(array_merge(Expense::CATEGORIES, $own)));
    }

    private function refuseIfCancelled(Expense $expense): void
    {
        if ($expense->cancelled_at) {
            throw ValidationException::withMessages(['reason' => 'This entry has already been cancelled.']);
        }
    }

    private function words(Expense $expense): string
    {
        return $expense->expense_no.' · '.$expense->category.($expense->paid_to ? ' to '.$expense->paid_to : '')
            .' · ₹'.(float) $expense->amount.' by '.str_replace('_', ' ', $expense->mode).' on '.$expense->spent_on->format('j M Y');
    }
}
