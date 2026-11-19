<?php

namespace App\Workforce\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Workforce\Models\Advance;
use App\Workforce\Models\Attendance;
use App\Workforce\Models\AttendanceCorrection;
use App\Workforce\Models\Employee;
use App\Workforce\Models\LeaveRequest;
use App\Workforce\Models\LeaveType;
use App\Workforce\Models\PayrollRun;
use App\Workforce\Models\Payslip;
use App\Workforce\Support\Access;
use App\Workforce\Support\AttendanceRules;
use App\Workforce\Support\LeaveBook;
use App\Workforce\Support\Money;
use App\Workforce\Support\Period;
use App\Workforce\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** The day at a glance for those who look after staff, and each person's own record for themself. */
class OverviewController extends Controller
{
    use Helpers;

    /** Who is in, out, on leave and late on a day, and what is waiting for someone to decide. Within the person's reach. */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:reports:view');
        $date = CarbonImmutable::parse($request->validate(['date' => ['nullable', 'date']])['date'] ?? now())->toDateString();
        $staff = Access::employees($user, 'wf:reports:view')->with('department:id,name')
            ->where(fn ($q) => $q->whereNull('joined_on')->orWhereDate('joined_on', '<=', $date))
            ->where(fn ($q) => $q->where('status', '!=', 'inactive')->orWhereDate('separated_on', '>=', $date))->orderBy('name')->get();
        $days = AttendanceRules::days($staff, $date, $date);
        $ids = $staff->pluck('id');
        $list = fn (array $statuses, ?callable $also = null) => $staff->filter(fn (Employee $e) => in_array($days[$e->id][$date]['status'], $statuses, true) && (! $also || $also($days[$e->id][$date])))
            ->map(fn (Employee $e) => $this->brief($e) + ['late_minutes' => $days[$e->id][$date]['late_minutes'], 'leave_type' => $days[$e->id][$date]['leave_type']])->values();
        $working = ['present', 'half_day', 'half_leave', 'open'];

        return response()->json([
            'date' => $date, 'is_today' => $date === now()->toDateString(), 'staff' => $staff->count(),
            'in' => $list($working), 'not_in' => $list(['not_in', 'absent']), 'on_leave' => $list(['on_leave', 'half_leave'], fn ($d) => $d['leave'] > 0),
            'late' => $list($working, fn ($d) => $d['late_minutes'] > 0), 'off' => $list(['weekly_off', 'holiday'])->count(),
            // Left open on an earlier day: someone forgot to check out, or was not checked out.
            'missing_check_outs' => Attendance::query()->whereIn('employee_id', $ids)->where('state', 'open')->whereDate('work_date', '<', now()->toDateString())->with('employee:id,name,employee_no')->orderBy('work_date')->limit(50)->get()
                ->map(fn (Attendance $a) => ['id' => $a->id, 'employee' => ['id' => $a->employee->id, 'name' => $a->employee->name], 'work_date' => $a->work_date->format('Y-m-d'), 'check_in' => $a->check_in?->format('Y-m-d H:i')]),
            'corrections_waiting' => $user->allows('wf:attendance:view') ? Access::narrow(AttendanceCorrection::query(), $user, 'wf:attendance:view')->where('status', 'pending')->count() : null,
            'leave_waiting' => $user->allows('wf:leave:view') ? Access::narrow(LeaveRequest::query(), $user, 'wf:leave:view')->where('status', 'pending')->count() : null,
            'pay' => $this->pay($user),
        ]);
    }

    /** A person's own record: today, this month, their leave and what they have asked for. Nothing of anyone else's. */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:self:view');
        $employee = Employee::query()->with(['department:id,name', 'shift'])->where('user_id', $user->id)->first();
        if (! $employee) {
            // A login that no staff record is joined to: say so plainly instead of showing an empty record.
            return response()->json(['employee' => null]);
        }
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');
        $start = CarbonImmutable::parse($month.'-01');
        $days = AttendanceRules::days(collect([$employee]), $start->toDateString(), $start->endOfMonth()->toDateString())[$employee->id];
        $year = LeaveBook::yearOf(now()->toDateString());
        $open = Attendance::query()->where('employee_id', $employee->id)->where('state', 'open')->latest('check_in')->first();
        $records = Attendance::query()->where('employee_id', $employee->id)->whereBetween('work_date', [$start->toDateString(), $start->endOfMonth()->toDateString()])->orderBy('check_in')->get();
        $attendance = app(AttendanceController::class);
        $leave = app(LeaveController::class);

        return response()->json([
            'employee' => $this->brief($employee) + ['shift' => $employee->shift ? ['name' => $employee->shift->name, 'starts_at' => substr((string) $employee->shift->starts_at, 0, 5), 'ends_at' => substr((string) $employee->shift->ends_at, 0, 5)] : null, 'joined_on' => $employee->joined_on?->format('Y-m-d')],
            'month' => $month, 'days' => array_values($days), 'totals' => AttendanceRules::totals($days),
            'records' => $records->map(fn (Attendance $a) => $attendance->record($a)),
            'open' => $open ? $attendance->record($open) : null,
            'self_check_in' => (bool) Settings::get('attendance')['self_check_in'],
            'leave_year' => $year,
            'leave_types' => LeaveType::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'is_paid', 'allow_half_day'])
                ->map(fn (LeaveType $t) => $t->only(['id', 'code', 'name', 'is_paid', 'allow_half_day']) + ['left' => LeaveBook::balance($employee->id, $t->id, $year)]),
            'leave' => LeaveRequest::query()->where('employee_id', $employee->id)->with('type:id,code,name,is_paid')->orderByDesc('from_date')->limit(30)->get()->map(fn (LeaveRequest $r) => $leave->present($r, collect())),
            'corrections' => AttendanceCorrection::query()->where('employee_id', $employee->id)->latest('id')->limit(20)->get()->map(fn (AttendanceCorrection $c) => $attendance->correction($c, collect(), collect())),
            // A person's own pay: payslips once their payroll is finalised (never a draft), and what they still owe on an advance.
            'payslips' => $this->finalPayslips($employee->id)->limit(24)->get()->map(fn (Payslip $s) => ['id' => $s->id, 'period' => $s->period, 'period_name' => Period::name($s->period), 'slip_no' => $s->slip_no, 'net' => $s->net, 'payment_status' => $s->payment_status, 'paid_on' => $s->paid_on?->format('Y-m-d')]),
            'advances' => $this->myAdvances($employee->id),
        ]);
    }

    /** A person's own payslip in full. Only one of a finalised payroll that still stands. */
    public function myPayslip(Request $request, int $slip): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:self:view');
        $employee = Employee::query()->where('user_id', $user->id)->firstOrFail();
        $slip = $this->finalPayslips($employee->id)->with(['lines', 'run', 'employee.department:id,name'])->findOrFail($slip);

        return response()->json(app(PayrollController::class)->slip($slip));
    }

    private function finalPayslips(int $employeeId): Builder
    {
        return Payslip::query()->where('employee_id', $employeeId)->whereNotNull('live_period')->whereHas('run', fn ($q) => $q->whereIn('status', ['finalised', 'paid']))->orderByDesc('period');
    }

    /** @return Collection<int, array<string, mixed>> */
    private function myAdvances(int $employeeId): Collection
    {
        $advances = app(AdvanceController::class);
        $rows = Advance::query()->where('employee_id', $employeeId)->whereIn('status', ['requested', 'approved', 'issued'])->with('schedule')->orderByDesc('id')->get();
        $owed = $advances->owed($rows->pluck('id'));

        return $rows->map(fn (Advance $a) => $advances->present($a, $owed) + ['next' => $a->status === 'issued' ? $a->schedule->firstWhere('status', 'due')?->only(['period', 'amount']) : null]);
    }

    /** A person asks for leave for themself. */
    public function askLeave(Request $request): JsonResponse
    {
        $employee = $this->mine($request->user());
        $leave = app(LeaveController::class);

        return response()->json($leave->ask($employee, $leave->checked($request), $request->user()), 201);
    }

    /** A person withdraws their own leave. Once it has started, only someone who approves leave can cancel it. */
    public function cancelLeave(Request $request, int $leave): JsonResponse
    {
        $employee = $this->mine($request->user());
        $leave = LeaveRequest::query()->where('employee_id', $employee->id)->findOrFail($leave);
        if ($leave->status === 'approved' && $leave->from_date->format('Y-m-d') <= now()->toDateString()) {
            throw ValidationException::withMessages(['leave' => 'This leave has already started. Ask whoever approves leave to cancel it.']);
        }
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? 'Withdrawn by the member of staff';

        return response()->json(app(LeaveController::class)->present(LeaveBook::cancel($leave, $reason)->fresh(['type', 'employee.department']), collect()));
    }

    /** A person asks for their own attendance to be corrected. Someone else decides it. */
    public function askCorrection(Request $request): JsonResponse
    {
        $employee = $this->mine($request->user());
        $attendance = app(AttendanceController::class);
        $data = $attendance->times($request, ['attendance_id' => ['nullable', 'integer'], 'reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Say what is wrong and why.']);

        return response()->json($attendance->ask($employee, $data, $request->user()), 201);
    }

    /** A person marks themself in or out, only where the hospital has switched that on. */
    public function punch(Request $request): JsonResponse
    {
        $user = $request->user();
        $employee = $this->mine($user);
        abort_unless(Settings::get('attendance')['self_check_in'], 403, 'Marking your own attendance is not switched on. Ask whoever keeps the attendance.');
        $attendance = app(AttendanceController::class);
        $open = Attendance::query()->where('employee_id', $employee->id)->where('state', 'open')->latest('check_in')->first();
        $record = $open ? $attendance->close($open, now()->format('Y-m-d H:i:s'), $user) : $attendance->open($employee, now()->format('Y-m-d H:i:s'), 'self', null, $user);

        return response()->json($attendance->record($record));
    }

    /**
     * Money still to go out or come back, for those who may see it: salaries finalised and not yet paid, and what
     * staff owe on advances and loans. Null for anyone who may see neither.
     *
     * @return array<string, mixed>|null
     */
    private function pay(User $user): ?array
    {
        $payroll = $user->allows('wf:payroll:view');
        $advances = $user->allows('wf:advances:view');
        if (! $payroll && ! $advances) {
            return null;
        }
        $out = [];
        if ($payroll) {
            $unpaid = Payslip::query()->where('payment_status', '!=', 'paid')->whereNotNull('live_period')->whereHas('run', fn ($q) => $q->whereIn('status', ['finalised', 'paid']))->selectRaw('COUNT(*) as slips, COALESCE(SUM(net), 0) as net')->first();
            $out['unpaid_salaries'] = ['slips' => (int) $unpaid->slips, 'net' => Money::text($unpaid->net)];
            $out['runs_in_hand'] = PayrollRun::query()->whereIn('status', ['draft', 'reviewed', 'approved'])->count();
        }
        if ($advances) {
            $issued = Access::narrow(Advance::query(), $user, 'wf:advances:view')->where('status', 'issued')->pluck('id');
            $out['advances'] = ['count' => $issued->count(), 'owed' => Money::text(app(AdvanceController::class)->owed($issued)->sum()), 'waiting' => Access::narrow(Advance::query(), $user, 'wf:advances:view')->where('status', 'requested')->count()];
        }

        return $out;
    }

    private function mine(User $user): Employee
    {
        Access::require($user, 'wf:self:create');
        $employee = Employee::query()->where('user_id', $user->id)->first();
        abort_unless($employee, 422, 'Your login is not joined to a staff record yet. Ask whoever keeps the staff directory.');

        return $employee;
    }
}
