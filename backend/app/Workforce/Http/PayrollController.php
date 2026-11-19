<?php

namespace App\Workforce\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Workforce\Models\Employee;
use App\Workforce\Models\PayrollRun;
use App\Workforce\Models\Payslip;
use App\Workforce\Models\PayslipLine;
use App\Workforce\Support\Access;
use App\Workforce\Support\Audit;
use App\Workforce\Support\Money;
use App\Workforce\Support\Payroll;
use App\Workforce\Support\Period;
use App\Workforce\Support\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Payroll runs and their payslips. Seeing that a run exists and what it totals needs "see payroll runs"; seeing what
 * any one person is paid needs "see salaries" as well, and without it the amounts are simply not sent.
 */
class PayrollController extends Controller
{
    use Helpers;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:payroll:view');
        $year = $request->validate(['year' => ['nullable', 'integer', 'between:2000,2100']])['year'] ?? null;
        $runs = PayrollRun::query()->withCount('payslips')->when($year, fn ($q) => $q->where('period', 'like', $year.'-%'))->orderByDesc('period')->orderByDesc('id')->limit(120)->get();
        $unpaid = Payslip::query()->whereIn('run_id', $runs->pluck('id'))->where('payment_status', '!=', 'paid')->selectRaw('run_id, COUNT(*) as slips, SUM(net) as net')->groupBy('run_id')->get()->keyBy('run_id');

        return response()->json([
            'can' => $this->can($user),
            'runs' => $runs->map(fn (PayrollRun $r) => $this->brief2($r) + ['staff' => $r->payslips_count, 'unpaid' => in_array($r->status, ['finalised', 'paid'], true) ? ['slips' => (int) ($unpaid[$r->id]->slips ?? 0), 'net' => Money::text($unpaid[$r->id]->net ?? 0)] : null]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:payroll:create');
        $data = $request->validate(['period' => ['required', 'date_format:Y-m'], 'label' => ['nullable', 'string', 'max:120'], 'department_id' => ['nullable', 'integer'], 'employee_ids' => ['nullable', 'array', 'max:2000'], 'employee_ids.*' => ['integer']]);
        $only = $data['employee_ids'] ?? null;
        if (! $only && ! empty($data['department_id'])) {
            $only = Employee::query()->where('department_id', $data['department_id'])->pluck('id')->all();
        }
        $run = Payroll::start($data['period'], $data['label'] ?? null, $only ? array_map('intval', $only) : null, $user);
        Audit::record('payroll.started', $run, null, ['period' => $run->period, 'staff' => $run->payslips()->count(), 'net' => $run->net]);

        return response()->json($this->detail($run, $user), 201);
    }

    public function show(Request $request, int $run): JsonResponse
    {
        Access::require($request->user(), 'wf:payroll:view');

        return response()->json($this->detail(PayrollRun::query()->findOrFail($run), $request->user()));
    }

    /** Works a draft out again from the records as they stand now. */
    public function workOut(Request $request, int $run): JsonResponse
    {
        Access::require($request->user(), 'wf:payroll:edit');
        $run = Payroll::workOut(PayrollRun::query()->findOrFail($run));
        Audit::record('payroll.worked_out', $run, null, ['staff' => $run->payslips()->count(), 'net' => $run->net]);

        return response()->json($this->detail($run, $request->user()));
    }

    /** A one-off addition or deduction on one person's payslip in a draft: an incentive, a fine, a correction for last month. */
    public function addLine(Request $request, int $run, int $slip): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:payroll:edit');
        [$run, $slip] = $this->draftSlip($run, $slip);
        Access::requireFor($user, 'wf:salary:view', $slip->employee);
        $data = $request->validate(['kind' => ['required', Rule::in(['earning', 'deduction'])], 'name' => ['required', 'string', 'max:100'], 'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'], 'note' => ['required', 'string', 'max:400']], ['note.required' => 'Say what this is for. It is printed on the payslip.', 'amount.gt' => 'Enter an amount above nothing.']);
        $line = $slip->lines()->create(['code' => 'EXTRA', 'name' => $data['name'], 'kind' => $data['kind'], 'amount' => Money::text($data['amount']), 'explanation' => $data['note'], 'source_type' => 'one_off', 'sort' => 999]);
        $run = Payroll::workOut($run);
        Audit::record('payroll.line_added', $line, null, ['kind' => $data['kind'], 'name' => $data['name'], 'amount' => Money::text($data['amount'])], $data['note'], $slip->employee_id);

        return response()->json($this->detail($run, $user));
    }

    public function removeLine(Request $request, int $run, int $slip, int $line): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:payroll:edit');
        [$run, $slip] = $this->draftSlip($run, $slip);
        $line = $slip->lines()->where('source_type', 'one_off')->findOrFail($line);
        Audit::record('payroll.line_removed', $line, ['kind' => $line->kind, 'name' => $line->name, 'amount' => $line->amount], null, null, $slip->employee_id);
        $line->delete();

        return response()->json($this->detail(Payroll::workOut($run), $user));
    }

    /** Leaves someone out of a draft (their pay is in dispute, they are paid another way this month), or brings them back. */
    public function holdBack(Request $request, int $run): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:payroll:edit');
        $run = PayrollRun::query()->findOrFail($run);
        $data = $request->validate(['employee_id' => ['required', 'integer'], 'leave_out' => ['required', 'boolean'], 'reason' => ['required_if:leave_out,true', 'nullable', 'string', 'max:500']], ['reason.required_if' => 'Say why this person is left out.']);
        $employee = Employee::query()->findOrFail($data['employee_id']);
        $held = collect($run->rules['held_back'] ?? [])->reject(fn ($id) => (int) $id === $employee->id);
        if ($data['leave_out']) {
            $held->push($employee->id);
        }
        if ($run->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only a draft is changed. Send this back to draft first.']);
        }
        $run->update(['rules' => ['held_back' => $held->values()->all()] + ($run->rules ?? [])]);
        $run = Payroll::workOut($run->fresh());
        Audit::record($data['leave_out'] ? 'payroll.left_out' : 'payroll.brought_back', $run, null, ['period' => $run->period], $data['reason'] ?? null, $employee->id);

        return response()->json($this->detail($run, $user));
    }

    /** Reviewed, approved, or sent back to draft. Whoever prepared a payroll does not also approve it. */
    public function step(Request $request, int $run): JsonResponse
    {
        $user = $request->user();
        $run = PayrollRun::query()->findOrFail($run);
        $data = $request->validate(['to' => ['required', Rule::in(['reviewed', 'approved', 'draft'])], 'reason' => ['required_if:to,draft', 'nullable', 'string', 'max:500']], ['reason.required_if' => 'Say why it is sent back.']);
        match ($data['to']) {
            'reviewed' => Access::require($user, 'wf:payroll:edit'),
            'approved' => Access::require($user, 'wf:payroll:approve'),
            'draft' => abort_unless($user->allows('wf:payroll:edit') || $user->allows('wf:payroll:approve'), 403, 'You are not allowed to do this.'),
        };
        if ($data['to'] === 'approved' && $user->role->value !== 'admin' && in_array($user->id, [$run->created_by, $run->reviewed_by], true)) {
            abort(403, 'A payroll is approved by someone other than the person who prepared or reviewed it.');
        }
        $was = $run->status;
        $run = Payroll::step($run, $data['to'], $user);
        Audit::record($data['to'] === 'draft' ? 'payroll.sent_back' : 'payroll.'.$data['to'], $run, ['status' => $was], ['status' => $run->status, 'net' => $run->net], $data['reason'] ?? null);

        return response()->json($this->detail($run, $user));
    }

    /** Locks the payroll. Behind the password step (see the route). */
    public function finalise(Request $request, int $run): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:payroll:finalise');
        $run = Payroll::finalise(PayrollRun::query()->findOrFail($run), $user);
        Audit::record('payroll.finalised', $run, ['status' => 'approved'], ['status' => 'finalised', 'period' => $run->period, 'staff' => $run->payslips()->count(), 'gross' => $run->gross, 'deductions' => $run->deductions, 'net' => $run->net]);

        return response()->json($this->detail($run, $user));
    }

    /** Undoes a finalised payroll, with the reason. Behind the password step. */
    public function reverse(Request $request, int $run): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:payroll:reverse');
        $reason = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']], ['reason.required' => 'Say why this payroll is reversed.', 'reason.min' => 'Say why this payroll is reversed.'])['reason'];
        $run = PayrollRun::query()->findOrFail($run);
        $was = $run->status;
        $run = Payroll::reverse($run, $reason, $user);
        Audit::record('payroll.reversed', $run, ['status' => $was, 'net' => $run->net], ['status' => 'reversed'], $reason);

        return response()->json($this->detail($run, $user));
    }

    /** Writes down that salaries were paid, with the date, how and the reference. It moves no money. */
    public function pay(Request $request, int $run): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:payroll:approve');
        $data = $request->validate([
            'payslip_ids' => ['required', 'array', 'min:1'], 'payslip_ids.*' => ['integer'], 'status' => ['required', Rule::in(['paid', 'held', 'unpaid'])],
            'paid_on' => ['required_if:status,paid', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'payment_mode' => ['required_if:status,paid', 'nullable', Rule::in(Payroll::MODES)], 'payment_reference' => ['nullable', 'string', 'max:120'],
            'reason' => ['required_unless:status,paid', 'nullable', 'string', 'max:500'],
        ], ['payslip_ids.required' => 'Tick at least one payslip.', 'paid_on.required_if' => 'Enter the date it was paid.', 'payment_mode.required_if' => 'Choose how it was paid.', 'paid_on.before_or_equal' => 'The payment date cannot be in the future.', 'reason.required_unless' => 'Say why.']);
        $run = Payroll::pay(PayrollRun::query()->findOrFail($run), $data['payslip_ids'], $data, $user);
        Audit::record('payroll.payment_'.$data['status'], $run, null, ['payslips' => count($data['payslip_ids']), 'paid_on' => $data['paid_on'] ?? null, 'mode' => $data['payment_mode'] ?? null, 'reference' => $data['payment_reference'] ?? null], $data['reason'] ?? null);

        return response()->json($this->detail($run, $user));
    }

    /** One payslip in full, for the screen and for printing. Opening someone else's is written down. */
    public function payslip(Request $request, int $slip): JsonResponse
    {
        $user = $request->user();
        $slip = Payslip::query()->with(['lines', 'run', 'employee.department:id,name'])->findOrFail($slip);
        Access::require($user, 'wf:payroll:view');
        Access::requireFor($user, 'wf:salary:view', $slip->employee);
        Audit::record('payslip.viewed', $slip, null, ['period' => $slip->period], null, $slip->employee_id);

        return response()->json($this->slip($slip));
    }

    /** The payroll register for a run: one row a person, every earning and deduction in its own column. Behind the password step. */
    public function export(Request $request, int $run): StreamedResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:payroll:export');
        Access::require($user, 'wf:salary:view');
        $run = PayrollRun::query()->findOrFail($run);
        $slips = Access::narrow($run->payslips()->getQuery(), $user, 'wf:salary:view')->with(['lines', 'employee.department:id,name'])->get()->sortBy(fn (Payslip $s) => $s->employee?->name)->values();
        $columns = $slips->flatMap(fn (Payslip $s) => $s->lines->map(fn (PayslipLine $l) => $l->kind.'|'.$l->name))->unique()->sortBy(fn ($key) => array_search(explode('|', $key)[0], ['earning', 'deduction', 'employer'], true))->values();
        Audit::record('payroll.exported', $run, null, ['period' => $run->period, 'rows' => $slips->count()]);

        return $this->csv("payroll-{$run->period}.csv",
            ['Payslip no.', 'Employee no.', 'Name', 'Department', 'Days in month', 'Paid leave', 'Unpaid days', ...$columns->map(fn ($key) => explode('|', $key)[1].' ('.explode('|', $key)[0].')')->all(), 'Gross', 'Deductions', 'Net pay', 'Payment', 'Paid on', 'Mode', 'Reference'],
            $slips->map(fn (Payslip $s) => [
                $s->slip_no, $s->employee?->employee_no, $s->employee?->name, $s->employee?->department?->name, $s->days['in_month'] ?? null, $s->days['paid_leave'] ?? null, $s->days['unpaid'] ?? null,
                ...$columns->map(fn ($key) => (string) Money::paise($s->lines->filter(fn (PayslipLine $l) => $l->kind.'|'.$l->name === $key)->sum('amount')))->all(),
                $s->gross, $s->deductions, $s->net, $s->payment_status, $s->paid_on?->format('Y-m-d'), $s->payment_mode, $s->payment_reference,
            ]));
    }

    /** Salaries finalised and not yet paid, run by run. */
    public function outstanding(Request $request): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:payroll:view');
        $slips = Payslip::query()->where('payment_status', '!=', 'paid')->whereNotNull('live_period')->whereHas('run', fn ($q) => $q->whereIn('status', ['finalised', 'paid']))
            ->with(['employee.department:id,name', 'run:id,period,status'])->orderBy('period')->get();
        $seen = Access::employees($user, 'wf:salary:view')->pluck('id')->flip();

        return response()->json([
            'slips' => $slips->count(), 'net' => Money::text($slips->sum('net')),
            'rows' => $slips->map(fn (Payslip $s) => ['id' => $s->id, 'run_id' => $s->run_id, 'period' => $s->period, 'slip_no' => $s->slip_no, 'employee' => $s->employee ? $this->brief($s->employee) : null, 'payment_status' => $s->payment_status, 'net' => $seen->has($s->employee_id) ? $s->net : null]),
        ]);
    }

    // ---- Shared ----

    /** @return array<string, bool> */
    private function can(User $user): array
    {
        return collect(['create', 'edit', 'approve', 'finalise', 'reverse', 'export'])->mapWithKeys(fn ($action) => [$action => Permissions::allows($user, "wf:payroll:{$action}")])->all()
            + ['see_pay' => Permissions::allows($user, 'wf:salary:view')];
    }

    /** @return array<string, mixed> */
    private function brief2(PayrollRun $r): array
    {
        return [
            'id' => $r->id, 'period' => $r->period, 'period_name' => Period::name($r->period), 'label' => $r->label, 'status' => $r->status,
            'gross' => $r->gross, 'deductions' => $r->deductions, 'net' => $r->net, 'employer_cost' => $r->employer_cost, 'replaces_run_id' => $r->reversal_of_id,
        ];
    }

    /** @return array<string, mixed> */
    private function detail(PayrollRun $run, User $user): array
    {
        $slips = $run->payslips()->with(['employee.department:id,name', 'lines'])->get()->sortBy(fn (Payslip $s) => $s->employee?->name)->values();
        $seen = Access::employees($user, 'wf:salary:view')->pluck('id')->flip();
        $all = $slips->every(fn (Payslip $s) => $seen->has($s->employee_id));
        $names = User::query()->whereIn('id', array_filter([$run->created_by, $run->reviewed_by, $run->approved_by, $run->finalised_by, $run->reversed_by]))->pluck('name', 'id');
        $who = fn (?int $id, mixed $at = null) => $id ? ['name' => $names->get($id), 'at' => $at?->format('Y-m-d H:i')] : null;

        return $this->brief2($run) + [
            'can' => $this->can($user) + ['approve_this' => $user->role->value === 'admin' || ! in_array($user->id, [$run->created_by, $run->reviewed_by], true)],
            'period_start' => $run->period_start->format('Y-m-d'), 'period_end' => $run->period_end->format('Y-m-d'),
            'steps' => ['prepared' => $who($run->created_by, $run->created_at), 'reviewed' => $who($run->reviewed_by, $run->reviewed_at), 'approved' => $who($run->approved_by, $run->approved_at), 'finalised' => $who($run->finalised_by, $run->finalised_at), 'reversed' => $who($run->reversed_by, $run->reversed_at)],
            'reverse_reason' => $run->reverse_reason, 'worked_out_at' => $run->rules['worked_out_at'] ?? null, 'settings' => $run->rules['finalised_with']['payroll'] ?? $run->rules['payroll'] ?? null,
            'left_out' => $run->rules['left_out'] ?? [],
            // Department totals would give away the pay of a department of one, so they go only to someone who may see pay.
            'departments' => $all ? $slips->groupBy(fn (Payslip $s) => $s->employee?->department?->name ?? 'No department')->map(fn (Collection $list, string $name) => [
                'name' => $name, 'staff' => $list->count(), 'gross' => Money::text($list->sum('gross')), 'deductions' => Money::text($list->sum('deductions')), 'net' => Money::text($list->sum('net')), 'employer_cost' => Money::text($list->sum('employer_cost')),
            ])->sortBy('name')->values() : null,
            'payslips' => $slips->map(fn (Payslip $s) => [
                'id' => $s->id, 'employee' => $s->employee ? $this->brief($s->employee) : null, 'slip_no' => $s->slip_no, 'payment_status' => $s->payment_status, 'paid_on' => $s->paid_on?->format('Y-m-d'), 'payment_mode' => $s->payment_mode,
                'notes' => count($s->days['notes'] ?? []),
            ] + ($seen->has($s->employee_id) ? ['gross' => $s->gross, 'deductions' => $s->deductions, 'net' => $s->net, 'unpaid_days' => $s->days['unpaid'] ?? 0, 'one_offs' => $s->lines->where('source_type', 'one_off')->count()] : ['gross' => null, 'deductions' => null, 'net' => null, 'unpaid_days' => null, 'one_offs' => null])),
        ];
    }

    /**
     * A payslip in full.
     *
     * @return array<string, mixed>
     */
    public function slip(Payslip $slip): array
    {
        $run = $slip->run;

        return [
            'id' => $slip->id, 'run_id' => $slip->run_id, 'run_status' => $run?->status, 'period' => $slip->period, 'period_name' => Period::name($slip->period), 'slip_no' => $slip->slip_no,
            'employee' => $slip->employee ? $this->brief($slip->employee) + ['joined_on' => $slip->employee->joined_on?->format('Y-m-d')] : null,
            'days' => $slip->days, 'pay_basis' => $slip->pay_as_set['pay_basis'] ?? null,
            'lines' => $slip->lines->map(fn (PayslipLine $l) => ['id' => $l->id, 'kind' => $l->kind, 'code' => $l->code, 'name' => $l->name, 'amount' => $l->amount, 'explanation' => $l->explanation, 'one_off' => $l->source_type === 'one_off']),
            'gross' => $slip->gross, 'deductions' => $slip->deductions, 'net' => $slip->net, 'employer_cost' => $slip->employer_cost,
            'payment_status' => $slip->payment_status, 'paid_on' => $slip->paid_on?->format('Y-m-d'), 'payment_mode' => $slip->payment_mode, 'payment_reference' => $slip->payment_reference,
            // Under a payroll run again after a reversal, part of the pay may have gone out already under the reversed one.
            'paid_amount' => $slip->paid_amount, 'already_paid' => (string) Payroll::alreadyPaid($slip),
            'reversed' => $run?->status === 'reversed', 'final' => (bool) $run?->isLocked(),
        ];
    }

    /** @return array{0: PayrollRun, 1: Payslip} */
    private function draftSlip(int $run, int $slip): array
    {
        $run = PayrollRun::query()->findOrFail($run);
        if ($run->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only a draft is changed. Send this back to draft first.']);
        }

        return [$run, $run->payslips()->with('employee')->findOrFail($slip)];
    }
}
