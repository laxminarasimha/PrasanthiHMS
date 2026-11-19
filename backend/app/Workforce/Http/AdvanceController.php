<?php

namespace App\Workforce\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Workforce\Models\Advance;
use App\Workforce\Models\AdvanceInstalment;
use App\Workforce\Models\AdvanceLedgerLine;
use App\Workforce\Models\Employee;
use App\Workforce\Support\Access;
use App\Workforce\Support\AdvanceBook;
use App\Workforce\Support\Audit;
use App\Workforce\Support\Money;
use App\Workforce\Support\Payroll;
use App\Workforce\Support\Period;
use App\Workforce\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Advances and loans to staff: asked for, approved, given, and taken back month by month through payroll. */
class AdvanceController extends Controller
{
    use Helpers;

    private const STATUSES = ['requested', 'approved', 'rejected', 'issued', 'closed', 'written_off'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:advances:view');
        $filters = $request->validate(['status' => ['nullable', Rule::in([...self::STATUSES, 'open', 'all'])], 'employee_id' => ['nullable', 'integer']]);
        $status = $filters['status'] ?? 'open';
        $rows = Access::narrow(Advance::query(), $user, 'wf:advances:view')->with('employee.department:id,name')
            ->when($status === 'open', fn ($q) => $q->whereIn('status', ['requested', 'approved', 'issued']))
            ->when(! in_array($status, ['open', 'all'], true), fn ($q) => $q->where('status', $status))
            ->when($filters['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->orderByDesc('id')->limit(400)->get();
        $owed = $this->owed($rows->pluck('id'));
        // What is owed across everything within reach, whatever the list is filtered to.
        $issued = Access::narrow(Advance::query(), $user, 'wf:advances:view')->where('status', 'issued')->pluck('id');

        return response()->json([
            'can' => ['create' => $user->allows('wf:advances:create'), 'approve' => $user->allows('wf:advances:approve'), 'let_go' => $user->allows('wf:advances:reverse'), 'export' => $user->allows('wf:advances:export')],
            'owed' => ['advances' => $issued->count(), 'amount' => Money::text($this->owed($issued)->sum())],
            'waiting' => Access::narrow(Advance::query(), $user, 'wf:advances:view')->where('status', 'requested')->count(),
            'rows' => $rows->map(fn (Advance $a) => $this->present($a, $owed)),
        ]);
    }

    public function show(Request $request, int $advance): JsonResponse
    {
        $user = $request->user();
        $advance = Advance::query()->with(['employee.department:id,name', 'schedule', 'ledger'])->findOrFail($advance);
        Access::requireFor($user, 'wf:advances:view', $advance->employee);
        $names = User::query()->whereIn('id', $advance->ledger->pluck('created_by')->merge([$advance->requested_by, $advance->approved_by])->filter())->pluck('name', 'id');
        $own = $this->isOwn($user, $advance);

        return response()->json($this->present($advance, $this->owed(collect([$advance->id]))) + [
            'requested_by' => $names->get($advance->requested_by), 'approved_by' => $names->get($advance->approved_by), 'approved_at' => $advance->approved_at?->format('Y-m-d H:i'),
            'payment_mode' => $advance->payment_mode, 'payment_reference' => $advance->payment_reference,
            'schedule' => $advance->schedule->map(fn (AdvanceInstalment $i) => ['id' => $i->id, 'period' => $i->period, 'period_name' => Period::name($i->period), 'amount' => $i->amount, 'status' => $i->status]),
            'ledger' => $advance->ledger->map(fn (AdvanceLedgerLine $l) => ['id' => $l->id, 'date' => $l->entry_date->format('Y-m-d'), 'kind' => $l->kind, 'amount' => $l->amount, 'balance_after' => $l->balance_after, 'reason' => $l->reason, 'by' => $names->get($l->created_by)]),
            'can_decide' => $advance->status === 'requested' && $user->allows('wf:advances:approve') && ! $own,
            'can_issue' => $advance->status === 'approved' && $user->allows('wf:advances:approve') && ! $own,
            'can_take_back' => $advance->status === 'issued' && $user->allows('wf:advances:approve'),
            'can_let_go' => $advance->status === 'issued' && $user->allows('wf:advances:reverse') && ! $own,
        ]);
    }

    /** A request put in for a member of staff. Nothing is given, and nothing is owed, until it is approved and issued. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'employee_id' => ['required', 'integer'], 'kind' => ['required', Rule::in(['advance', 'loan'])], 'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'], 'reason' => ['required', 'string', 'max:500'],
            'instalments' => ['required', 'integer', 'between:1,120'], 'first_period' => ['required', 'date_format:Y-m'],
        ], ['amount.gt' => 'Enter an amount above nothing.', 'reason.required' => 'Say what it is for.']);
        $employee = Employee::query()->findOrFail($data['employee_id']);
        Access::requireFor($user, 'wf:advances:create', $employee);
        $advance = Advance::create($data + ['advance_no' => $this->nextNumber(), 'status' => 'requested', 'requested_by' => $user->id]);
        Audit::record('advance.requested', $advance, null, ['kind' => $advance->kind, 'amount' => $advance->amount, 'instalments' => $advance->instalments, 'first_period' => $advance->first_period], $data['reason'], $employee->id);

        return response()->json($this->present($advance->load('employee.department'), collect()), 201);
    }

    /** Approves a request (the plan may be changed in approving it) or refuses it. Nobody decides their own. */
    public function decide(Request $request, int $advance): JsonResponse
    {
        $user = $request->user();
        $advance = Advance::query()->with('employee.department:id,name')->findOrFail($advance);
        Access::requireFor($user, 'wf:advances:approve', $advance->employee);
        abort_if($this->isOwn($user, $advance), 403, 'Your own advance must be decided by someone else.');
        $data = $request->validate([
            'approve' => ['required', 'boolean'], 'note' => ['required_if:approve,false', 'nullable', 'string', 'max:500'], 'amount' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'instalments' => ['nullable', 'integer', 'between:1,120'], 'first_period' => ['nullable', 'date_format:Y-m'],
        ], ['note.required_if' => 'Say why it is refused.']);
        if ($advance->status !== 'requested') {
            throw ValidationException::withMessages(['approve' => 'This request has already been '.str_replace('_', ' ', $advance->status).'.']);
        }
        $was = $advance->only(['amount', 'instalments', 'first_period']);
        $advance->update($data['approve']
            ? ['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'amount' => $data['amount'] ?? $advance->amount, 'instalments' => $data['instalments'] ?? $advance->instalments, 'first_period' => $data['first_period'] ?? $advance->first_period]
            : ['status' => 'rejected', 'approved_by' => $user->id, 'approved_at' => now()]);
        Audit::record($data['approve'] ? 'advance.approved' : 'advance.refused', $advance, $was, $advance->only(['amount', 'instalments', 'first_period']), $data['note'] ?? null, $advance->employee_id);

        return response()->json($this->present($advance, collect()));
    }

    /** Writes down that the money was given: the date, how, and the reference. From here it is owed. No money is moved from here. */
    public function issue(Request $request, int $advance): JsonResponse
    {
        $user = $request->user();
        $advance = Advance::query()->with('employee.department:id,name')->findOrFail($advance);
        Access::requireFor($user, 'wf:advances:approve', $advance->employee);
        abort_if($this->isOwn($user, $advance), 403, 'Your own advance must be given by someone else.');
        $data = $request->validate(['issued_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'payment_mode' => ['required', Rule::in(Payroll::MODES)], 'payment_reference' => ['nullable', 'string', 'max:120']], ['issued_on.before_or_equal' => 'The date cannot be in the future.']);
        $advance = AdvanceBook::issue($advance, $data['issued_on'], $data['payment_mode'], $data['payment_reference'] ?? null);
        Audit::record('advance.issued', $advance, null, ['amount' => $advance->amount, 'issued_on' => $data['issued_on'], 'mode' => $data['payment_mode'], 'reference' => $data['payment_reference'] ?? null], null, $advance->employee_id);

        return response()->json($this->present($advance->load('employee.department'), $this->owed(collect([$advance->id]))));
    }

    /** Money the member of staff handed back themself, outside payroll. */
    public function repay(Request $request, int $advance): JsonResponse
    {
        $advance = Advance::query()->with('employee.department:id,name')->findOrFail($advance);
        Access::requireFor($request->user(), 'wf:advances:approve', $advance->employee);
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'note' => ['required', 'string', 'max:500']], ['note.required' => 'Say how it was paid back: cash, a transfer, the receipt number.']);
        if ($advance->status !== 'issued') {
            throw ValidationException::withMessages(['amount' => 'Nothing is owed on this.']);
        }
        AdvanceBook::line($advance, 'repayment', Money::of($data['amount'])->negated(), $data['paid_on'], $data['note']);
        $advance = AdvanceBook::settle($advance);
        Audit::record('advance.repaid', $advance, null, ['amount' => Money::text($data['amount']), 'paid_on' => $data['paid_on']], $data['note'], $advance->employee_id);

        return response()->json($this->present($advance->load('employee.department'), $this->owed(collect([$advance->id]))));
    }

    /** Leaves one month's instalment untaken and adds it to the end of the plan. */
    public function skip(Request $request, int $advance): JsonResponse
    {
        $advance = Advance::query()->with('employee.department:id,name')->findOrFail($advance);
        Access::requireFor($request->user(), 'wf:advances:approve', $advance->employee);
        abort_if($this->isOwn($request->user(), $advance), 403, 'Your own advance must be changed by someone else.');
        $data = $request->validate(['period' => ['required', 'date_format:Y-m'], 'reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Say why this month is skipped.']);
        AdvanceBook::skip($advance, $data['period']);
        Audit::record('advance.month_skipped', $advance, null, ['period' => $data['period']], $data['reason'], $advance->employee_id);

        return response()->json($this->present($advance->fresh('employee.department'), $this->owed(collect([$advance->id]))));
    }

    /** Waives part of what is owed, or writes off all of it. Behind its own permission, a reason and the password step. */
    public function letGo(Request $request, int $advance): JsonResponse
    {
        $user = $request->user();
        $advance = Advance::query()->with('employee.department:id,name')->findOrFail($advance);
        Access::requireFor($user, 'wf:advances:reverse', $advance->employee);
        abort_if($this->isOwn($user, $advance), 403, 'Your own advance must be decided by someone else.');
        $data = $request->validate(['kind' => ['required', Rule::in(['waiver', 'write_off'])], 'amount' => ['required_if:kind,waiver', 'nullable', 'numeric', 'gt:0'], 'reason' => ['required', 'string', 'min:5', 'max:500']], ['reason.required' => 'Say why this is let go.', 'reason.min' => 'Say why this is let go.', 'amount.required_if' => 'Enter how much is waived.']);
        $owed = (string) AdvanceBook::balance($advance);
        $advance = AdvanceBook::letGo($advance, $data['kind'], $data['amount'] ?? null, $data['reason']);
        Audit::record($data['kind'] === 'waiver' ? 'advance.waived' : 'advance.written_off', $advance, ['owed' => $owed], ['owed' => (string) AdvanceBook::balance($advance)], $data['reason'], $advance->employee_id);

        return response()->json($this->present($advance->load('employee.department'), $this->owed(collect([$advance->id]))));
    }

    /** Behind the password step (see the route). */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:advances:export');
        $rows = Access::narrow(Advance::query(), $user, 'wf:advances:view')->with('employee:id,employee_no,name')->orderBy('id')->get();
        $owed = $this->owed($rows->pluck('id'));
        Audit::record('advances.exported', null, null, ['rows' => $rows->count()]);

        return $this->csv('advances-and-loans.csv', ['No.', 'Employee no.', 'Name', 'Kind', 'Amount', 'Status', 'Given on', 'Instalments', 'First salary month', 'Still owed', 'Reason'],
            $rows->map(fn (Advance $a) => [$a->advance_no, $a->employee?->employee_no, $a->employee?->name, $a->kind, $a->amount, $a->status, $a->issued_on?->format('Y-m-d'), $a->instalments, $a->first_period, Money::text($owed->get($a->id, 0)), $a->reason]));
    }

    // ---- Shared ----

    /**
     * An advance as shown in lists.
     *
     * @return array<string, mixed>
     */
    public function present(Advance $a, Collection $owed): array
    {
        return [
            'id' => $a->id, 'advance_no' => $a->advance_no, 'kind' => $a->kind, 'amount' => $a->amount, 'reason' => $a->reason, 'status' => $a->status,
            'issued_on' => $a->issued_on?->format('Y-m-d'), 'instalments' => (int) $a->instalments, 'first_period' => $a->first_period,
            'owed' => in_array($a->status, ['issued', 'closed', 'written_off'], true) ? Money::text($owed->get($a->id, 0)) : null,
            'employee' => $a->relationLoaded('employee') && $a->employee ? $this->brief($a->employee) : null,
        ];
    }

    /** What is still owed on each of these, in one read. */
    public function owed(Collection $ids): Collection
    {
        return AdvanceLedgerLine::query()->whereIn('advance_id', $ids)->selectRaw('advance_id, SUM(amount) as owed')->groupBy('advance_id')->pluck('owed', 'advance_id');
    }

    private function isOwn(User $user, Advance $advance): bool
    {
        return $user->role->value !== 'admin' && $advance->employee?->user_id === $user->id;
    }

    private function nextNumber(): string
    {
        $prefix = Settings::get('numbering')['advance_prefix'];
        $last = Advance::query()->where('advance_no', 'like', $prefix.'%')->pluck('advance_no')->map(fn (string $no) => (int) preg_replace('/\D/', '', substr($no, strlen($prefix))))->max() ?? 0;

        return $prefix.str_pad((string) ($last + 1), 5, '0', STR_PAD_LEFT);
    }
}
