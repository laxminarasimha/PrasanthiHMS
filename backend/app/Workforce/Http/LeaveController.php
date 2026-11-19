<?php

namespace App\Workforce\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Workforce\Models\Employee;
use App\Workforce\Models\LeaveLedgerLine;
use App\Workforce\Models\LeaveRequest;
use App\Workforce\Models\LeaveType;
use App\Workforce\Support\Access;
use App\Workforce\Support\Audit;
use App\Workforce\Support\LeaveBook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Leave: requests and their decisions, each person's account, and the steps that earn, carry and lapse leave. */
class LeaveController extends Controller
{
    use Helpers;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:leave:view');
        $filters = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'cancelled', 'all'])], 'employee_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $status = $filters['status'] ?? 'pending';
        $rows = Access::narrow(LeaveRequest::query(), $user, 'wf:leave:view')->with(['type:id,code,name,is_paid', 'employee.department:id,name'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($filters['employee_id'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('to_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('from_date', '<=', $d))
            ->orderByDesc('from_date')->limit(300)->get();
        $names = User::query()->whereIn('id', $rows->pluck('requested_by')->merge($rows->pluck('decided_by'))->filter())->pluck('name', 'id');
        $own = Employee::query()->where('user_id', $user->id)->value('id');

        return response()->json($rows->map(fn (LeaveRequest $r) => $this->present($r, $names) + [
            'can_decide' => $r->status === 'pending' && $user->allows('wf:leave:approve') && Access::reaches($user, 'wf:leave:approve', $r->employee) && ($user->role->value === 'admin' || $r->employee_id !== $own),
        ]));
    }

    public function types(): JsonResponse
    {
        return response()->json(LeaveType::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'is_paid', 'allow_half_day', 'annual_allowance']));
    }

    /** A request entered for a member of staff by someone who may. A person's own request goes through "my record". */
    public function store(Request $request): JsonResponse
    {
        $data = $this->checked($request, ['employee_id' => ['required', 'integer']]);
        $employee = Employee::query()->findOrFail($data['employee_id']);
        Access::requireFor($request->user(), 'wf:leave:create', $employee);

        return response()->json($this->ask($employee, $data, $request->user()), 201);
    }

    /** Approves a request, taking the days from the account, or refuses it. Nobody decides their own leave. */
    public function decide(Request $request, int $leave): JsonResponse
    {
        $user = $request->user();
        $leave = LeaveRequest::query()->with('type')->findOrFail($leave);
        Access::requireFor($user, 'wf:leave:approve', $leave->employee);
        abort_if($user->role->value !== 'admin' && Employee::query()->where('user_id', $user->id)->whereKey($leave->employee_id)->exists(), 403, 'Your own leave must be decided by someone else.');
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'required_if:approve,false', 'string', 'max:500']], ['note.required_if' => 'Say why it is refused.']);

        if ($data['approve']) {
            $leave = LeaveBook::approve($leave, $data['note'] ?? null);
        } else {
            if ($leave->status !== 'pending') {
                throw ValidationException::withMessages(['approve' => 'This request has already been '.$leave->status.'.']);
            }
            $leave->update(['status' => 'rejected', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $data['note']]);
        }
        Audit::record($data['approve'] ? 'leave.approved' : 'leave.refused', $leave, null, ['days' => $leave->days, 'from' => $leave->from_date->format('Y-m-d'), 'to' => $leave->to_date->format('Y-m-d')], $data['note'] ?? null, $leave->employee_id);

        return response()->json($this->present($leave->fresh(['type', 'employee.department']), collect()));
    }

    /** Withdraws a request, by someone who may decide it. If it was approved, the days go back into the account. */
    public function cancel(Request $request, int $leave): JsonResponse
    {
        $leave = LeaveRequest::query()->findOrFail($leave);
        Access::requireFor($request->user(), 'wf:leave:approve', $leave->employee);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Say why the leave is cancelled.'])['reason'];
        $leave = LeaveBook::cancel($leave, $reason);
        Audit::record('leave.cancelled', $leave, null, ['days' => $leave->days], $reason, $leave->employee_id);

        return response()->json($this->present($leave->fresh(['type', 'employee.department']), collect()));
    }

    /** Every person's balance of every kind of leave for a leave year. */
    public function balances(Request $request): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:leave:view');
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']])['year'] ?? LeaveBook::yearOf(now()->toDateString()));
        $staff = Access::employees($user, 'wf:leave:view')->where('status', '!=', 'inactive')->with('department:id,name')->orderBy('name')->get();
        $types = LeaveType::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'is_paid']);
        $sums = LeaveLedgerLine::query()->whereIn('employee_id', $staff->pluck('id'))->where('leave_year', $year)
            ->selectRaw('employee_id, leave_type_id, SUM(days) as balance, SUM(CASE WHEN kind IN (\'taken\', \'reversal\') THEN days ELSE 0 END) as taken')->groupBy('employee_id', 'leave_type_id')->get()
            ->keyBy(fn ($row) => $row->employee_id.'|'.$row->leave_type_id);
        [$start, $end] = LeaveBook::span($year);

        return response()->json([
            'year' => $year, 'from' => $start->toDateString(), 'to' => $end->toDateString(), 'types' => $types,
            'rows' => $staff->map(fn (Employee $e) => $this->brief($e) + ['balances' => $types->mapWithKeys(fn (LeaveType $t) => [$t->id => [
                'left' => round((float) ($sums[$e->id.'|'.$t->id]->balance ?? 0), 2), 'taken' => round(-(float) ($sums[$e->id.'|'.$t->id]->taken ?? 0), 2),
            ]])]),
        ]);
    }

    /** One person's leave account for a year, line by line. */
    public function ledger(Request $request, int $employee): JsonResponse
    {
        $employee = Employee::query()->findOrFail($employee);
        Access::requireFor($request->user(), 'wf:leave:view', $employee);
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']])['year'] ?? LeaveBook::yearOf(now()->toDateString()));

        return response()->json($this->lines($employee->id, $year));
    }

    /** A correcting line on someone's account: an opening balance, or putting a mistake right. Always with the reason. */
    public function adjust(Request $request): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:leave:edit');
        $data = $request->validate([
            'employee_id' => ['required', 'integer'], 'leave_type_id' => ['required', 'integer'], 'year' => ['required', 'integer', 'between:2000,2100'],
            'days' => ['required', 'numeric', 'between:-366,366', 'not_in:0'], 'kind' => ['required', Rule::in(['opening', 'adjustment'])], 'reason' => ['required', 'string', 'max:500'],
        ], ['reason.required' => 'Say why the balance is being changed.', 'days.not_in' => 'Enter how many days to add or take away.']);
        $employee = Employee::query()->findOrFail($data['employee_id']);
        Access::requireFor($user, 'wf:leave:edit', $employee);
        $type = $this->owned(LeaveType::class, $data['leave_type_id'], 'leave_type_id');
        $before = LeaveBook::balance($employee->id, $type->id, $data['year']);
        LeaveBook::line($employee->id, $type->id, $data['year'], $data['kind'], (float) $data['days'], now()->toDateString(), null, $data['reason']);
        Audit::record('leave.balance.adjusted', $employee, ['balance' => $before], ['balance' => LeaveBook::balance($employee->id, $type->id, $data['year']), 'type' => $type->code], $data['reason'], $employee->id);

        return response()->json($this->lines($employee->id, $data['year']));
    }

    /** The yearly housekeeping: give out what is earned, close a year, lapse carried leave. Each is safe to run again. */
    public function housekeeping(Request $request): JsonResponse
    {
        Access::require($request->user(), 'wf:leave:edit');
        $data = $request->validate(['step' => ['required', Rule::in(['accrue', 'close_year', 'expire_carried'])], 'year' => ['required', 'integer', 'between:2000,2100'], 'upto_month' => ['nullable', 'integer', 'between:1,12']]);
        $lines = match ($data['step']) {
            'accrue' => LeaveBook::accrue($data['year'], $data['upto_month'] ?? null),
            'close_year' => LeaveBook::closeYear($data['year']),
            'expire_carried' => LeaveBook::expireCarried($data['year']),
        };
        Audit::record("leave.{$data['step']}", null, null, ['year' => $data['year'], 'lines_written' => $lines]);

        return response()->json(['lines_written' => $lines]);
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:leave:export');
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']])['year'] ?? LeaveBook::yearOf(now()->toDateString()));
        [$start, $end] = LeaveBook::span($year);
        $rows = Access::narrow(LeaveRequest::query(), $user, 'wf:leave:view')->with(['type:id,code,name', 'employee:id,employee_no,name'])->whereDate('from_date', '<=', $end)->whereDate('to_date', '>=', $start)->orderBy('from_date')->get();
        Audit::record('leave.exported', null, null, ['year' => $year, 'rows' => $rows->count()]);

        return $this->csv("leave-{$year}.csv", ['Employee no.', 'Name', 'Leave', 'From', 'To', 'Days', 'Status', 'Reason'],
            $rows->map(fn (LeaveRequest $r) => [$r->employee?->employee_no, $r->employee?->name, $r->type?->name, $r->from_date->format('Y-m-d'), $r->to_date->format('Y-m-d'), $r->days, $r->status, $r->reason]));
    }

    // ---- Shared with a person's own requests ----

    /** @return array<string, mixed> */
    public function checked(Request $request, array $more = []): array
    {
        return $request->validate($more + [
            'leave_type_id' => ['required', 'integer'], 'from_date' => ['required', 'date_format:Y-m-d'], 'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'from_part' => ['nullable', Rule::in(['full', 'first_half', 'second_half'])], 'to_part' => ['nullable', Rule::in(['full', 'first_half', 'second_half'])], 'reason' => ['nullable', 'string', 'max:500'],
        ], ['to_date.after_or_equal' => 'The last day cannot be before the first day.']);
    }

    /** Writes a leave request after checking it against the kind of leave, the working days and what is already booked. */
    public function ask(Employee $employee, array $data, User $by): array
    {
        $type = LeaveType::query()->where('is_active', true)->find($data['leave_type_id']);
        if (! $type) {
            throw ValidationException::withMessages(['leave_type_id' => 'Choose a kind of leave from the list.']);
        }
        $fromPart = $data['from_part'] ?? 'full';
        $toPart = $data['to_part'] ?? 'full';
        if (! $type->allow_half_day && ($fromPart !== 'full' || $toPart !== 'full')) {
            throw ValidationException::withMessages(['from_part' => "{$type->name} cannot be taken as a half day."]);
        }
        if ($data['from_date'] === $data['to_date']) {
            $toPart = $fromPart; // one day: either the whole of it or one half
        }
        if (LeaveBook::yearOf($data['from_date']) !== LeaveBook::yearOf($data['to_date'])) {
            throw ValidationException::withMessages(['to_date' => 'This leave runs across two leave years. Enter it as two requests, one in each year.']);
        }
        $days = LeaveBook::daysBetween($employee, $data['from_date'], $data['to_date'], $fromPart, $toPart);
        if ($days <= 0) {
            throw ValidationException::withMessages(['from_date' => 'There is no working day in these dates: they are weekly offs or holidays.']);
        }
        if (LeaveRequest::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])->whereDate('from_date', '<=', $data['to_date'])->whereDate('to_date', '>=', $data['from_date'])->exists()) {
            throw ValidationException::withMessages(['from_date' => "{$employee->name} already has leave asked for or approved in these dates."]);
        }
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'from_date' => $data['from_date'], 'to_date' => $data['to_date'], 'from_part' => $fromPart, 'to_part' => $toPart,
            'days' => $days, 'reason' => $data['reason'] ?? null, 'status' => 'pending', 'requested_by' => $by->id,
        ]);
        Audit::record('leave.asked', $leave, null, ['type' => $type->code, 'from' => $data['from_date'], 'to' => $data['to_date'], 'days' => $days], $data['reason'] ?? null, $employee->id);
        // A kind of leave that needs no approval is granted at once, if the account holds the days.
        if (! $type->needs_approval) {
            $leave = LeaveBook::approve($leave->load('type'), 'No approval needed for this kind of leave.');
        }

        return $this->present($leave->fresh(['type', 'employee.department']), collect());
    }

    /** @return array<string, mixed> */
    public function present(LeaveRequest $r, $names): array
    {
        return [
            'id' => $r->id, 'employee' => $r->employee ? $this->brief($r->employee) : null, 'type' => $r->type ? ['id' => $r->type->id, 'code' => $r->type->code, 'name' => $r->type->name, 'is_paid' => $r->type->is_paid] : null,
            'from_date' => $r->from_date->format('Y-m-d'), 'to_date' => $r->to_date->format('Y-m-d'), 'from_part' => $r->from_part, 'to_part' => $r->to_part, 'days' => $r->days, 'reason' => $r->reason,
            'status' => $r->status, 'asked_by' => $names[$r->requested_by] ?? null, 'asked_at' => $r->created_at?->format('Y-m-d H:i'), 'decided_by' => $names[$r->decided_by] ?? null,
            'decided_at' => $r->decided_at?->format('Y-m-d H:i'), 'decision_note' => $r->decision_note,
        ];
    }

    /** @return array<string, mixed> */
    public function lines(int $employeeId, int $year): array
    {
        $lines = LeaveLedgerLine::query()->where(['employee_id' => $employeeId, 'leave_year' => $year])->orderBy('entry_date')->orderBy('id')->get();
        $types = LeaveType::query()->whereIn('id', $lines->pluck('leave_type_id'))->get(['id', 'code', 'name'])->keyBy('id');
        $running = [];

        return [
            'year' => $year,
            'lines' => $lines->map(function (LeaveLedgerLine $l) use ($types, &$running) {
                $running[$l->leave_type_id] = round(($running[$l->leave_type_id] ?? 0) + $l->days, 2);

                return ['id' => $l->id, 'date' => $l->entry_date->format('Y-m-d'), 'type' => $types[$l->leave_type_id]->code ?? '', 'kind' => $l->kind, 'days' => $l->days, 'balance' => $running[$l->leave_type_id], 'note' => $l->note];
            }),
            'balances' => collect($running)->mapWithKeys(fn ($balance, $typeId) => [$types[$typeId]->code ?? $typeId => $balance]),
        ];
    }
}
