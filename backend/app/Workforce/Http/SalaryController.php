<?php

namespace App\Workforce\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Workforce\Models\Employee;
use App\Workforce\Models\Payslip;
use App\Workforce\Models\SalaryComponent;
use App\Workforce\Models\SalaryStructure;
use App\Workforce\Support\Access;
use App\Workforce\Support\Audit;
use App\Workforce\Support\Money;
use App\Workforce\Support\PayrollEngine;
use App\Workforce\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** What salaries are made of, and what each person is paid from which month. */
class SalaryController extends Controller
{
    use Helpers;

    /** How an earning may be worked out, and how a deduction or the hospital's own share may be. */
    private const BASES = [
        'earning' => ['fixed', 'percent_of_basic', 'per_day', 'per_hour'],
        'deduction' => ['fixed', 'percent_of_basic', 'percent_of_gross', 'slab_of_gross'],
        'employer' => ['fixed', 'percent_of_basic', 'percent_of_gross', 'slab_of_gross'],
    ];

    /**
     * A starting list of parts, made only when someone asks for it. The statutory ones are names only: switched
     * off, with no rate, until the accountant enters each rule.
     */
    private const STARTER = [
        ['BASIC', 'Basic pay', 'earning', 'fixed', false], ['HRA', 'House rent allowance', 'earning', 'fixed', false], ['ALLOW', 'Other allowance', 'earning', 'fixed', false],
        ['PF', 'Provident fund', 'deduction', 'percent_of_basic', true], ['ESI', 'ESI', 'deduction', 'percent_of_gross', true],
        ['PT', 'Professional tax', 'deduction', 'slab_of_gross', true], ['TDS', 'Income tax (TDS)', 'deduction', 'fixed', true],
        ['PF_ER', 'Provident fund, hospital’s share', 'employer', 'percent_of_basic', true], ['ESI_ER', 'ESI, hospital’s share', 'employer', 'percent_of_gross', true],
    ];

    public function components(Request $request): JsonResponse
    {
        Access::require($request->user(), 'wf:salary:view');

        return response()->json(['components' => SalaryComponent::query()->orderBy('sort')->orderBy('id')->get(), 'bases' => self::BASES, 'settings' => Settings::get('payroll')]);
    }

    public function starter(Request $request): JsonResponse
    {
        Access::require($request->user(), 'wf:salary:edit');
        if (SalaryComponent::query()->exists()) {
            throw ValidationException::withMessages(['components' => 'The list has already been started. Add to it instead.']);
        }
        foreach (self::STARTER as $i => [$code, $name, $kind, $basis, $statutory]) {
            SalaryComponent::create(['code' => $code, 'name' => $name, 'kind' => $kind, 'basis' => $basis, 'is_statutory' => $statutory, 'is_enabled' => ! $statutory, 'reduces_with_absence' => $kind === 'earning', 'rule' => null, 'sort' => ($i + 1) * 10]);
        }
        Audit::record('pay.components_started', null, null, ['parts' => count(self::STARTER)]);

        return $this->components($request);
    }

    public function saveComponent(Request $request, ?int $component = null): JsonResponse
    {
        Access::require($request->user(), 'wf:salary:edit');
        $existing = $component ? SalaryComponent::query()->findOrFail($component) : null;
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z][A-Z0-9_]*$/'], 'name' => ['required', 'string', 'max:100'], 'kind' => ['required', Rule::in(array_keys(self::BASES))],
            'basis' => ['required', 'string'], 'is_statutory' => ['sometimes', 'boolean'], 'is_enabled' => ['sometimes', 'boolean'], 'reduces_with_absence' => ['sometimes', 'boolean'], 'sort' => ['nullable', 'integer', 'between:0,9999'],
            'rule' => ['nullable', 'array'], 'rule.percent' => ['nullable', 'numeric', 'between:0,100'], 'rule.ceiling' => ['nullable', 'numeric', 'min:0'], 'rule.only_if_pay_up_to' => ['nullable', 'numeric', 'min:0'],
            'rule.amount' => ['nullable', 'numeric', 'min:0'], 'rule.round' => ['nullable', Rule::in(['rupee', 'rupee_up'])],
            'rule.slabs' => ['nullable', 'array', 'max:20'], 'rule.slabs.*.up_to' => ['nullable', 'numeric', 'min:0'], 'rule.slabs.*.amount' => ['required', 'numeric', 'min:0'],
        ], ['code.regex' => 'Use capital letters, numbers and _ only, starting with a letter.']);
        if (! in_array($data['basis'], self::BASES[$data['kind']], true)) {
            throw ValidationException::withMessages(['basis' => $data['kind'] === 'earning' ? 'An earning is a fixed amount, a percentage of basic pay, or an amount for each day or hour.' : 'Choose how this is worked out.']);
        }
        if ($existing && $existing->code === PayrollEngine::BASIC && $data['code'] !== PayrollEngine::BASIC) {
            throw ValidationException::withMessages(['code' => 'Basic pay keeps the code BASIC: other parts are worked out from it.']);
        }
        if (SalaryComponent::query()->where('code', $data['code'])->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))->exists()) {
            throw ValidationException::withMessages(['code' => 'Another part already has this code.']);
        }
        // Only what was actually entered is kept as the rule: an empty box is no rule at all, never a nought.
        $rule = array_filter($data['rule'] ?? [], fn ($v) => $v !== null && $v !== '' && $v !== []);
        if (isset($rule['slabs'])) {
            $rule['slabs'] = collect($rule['slabs'])->sortBy(fn ($s) => $s['up_to'] ?? PHP_FLOAT_MAX)->values()->all();
        }
        $data['rule'] = $rule === [] ? null : $rule;
        $data['sort'] = (int) ($data['sort'] ?? ($existing->sort ?? ((int) SalaryComponent::query()->max('sort') + 10)));
        if ($data['kind'] !== 'earning') {
            $data['reduces_with_absence'] = false;
        }

        $was = $existing?->only(['code', 'name', 'kind', 'basis', 'is_statutory', 'is_enabled', 'reduces_with_absence', 'rule', 'sort']) ?? [];
        $saved = $existing ? tap($existing)->update($data) : SalaryComponent::create($data);
        [$before, $after] = Audit::difference($was, $saved->only(array_keys($data)));
        if ($after !== []) {
            Audit::record($existing ? 'pay.component_changed' : 'pay.component_added', $saved, $before, $after, $request->input('reason'));
        }

        return response()->json($saved->fresh(), $existing ? 200 : 201);
    }

    /** Every member of staff within reach, with the salary they are on now and any that is waiting. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        Access::require($user, 'wf:salary:view');
        $staff = Access::employees($user, 'wf:salary:view')->where('status', '!=', 'inactive')->with('department:id,name')->orderBy('name')->get();
        $all = SalaryStructure::query()->whereIn('employee_id', $staff->pluck('id'))->with('lines.component')->orderBy('effective_from')->orderBy('id')->get()->groupBy('employee_id');
        $today = now()->toDateString();

        return response()->json([
            'can_edit' => $user->allows('wf:salary:edit'), 'can_approve' => $user->allows('wf:salary:approve'),
            'rows' => $staff->map(function (Employee $e) use ($all, $today) {
                $list = $all->get($e->id) ?? collect();
                $now = $list->filter(fn (SalaryStructure $s) => $s->approved_at && $s->effective_from->format('Y-m-d') <= $today)->last();
                $next = $list->filter(fn (SalaryStructure $s) => $s->approved_at && $s->effective_from->format('Y-m-d') > $today)->first();

                return $this->brief($e) + [
                    'current' => $now ? ['effective_from' => $now->effective_from->format('Y-m-d'), 'pay_basis' => $now->pay_basis, 'monthly' => $this->monthly($now)] : null,
                    'next' => $next ? ['effective_from' => $next->effective_from->format('Y-m-d'), 'monthly' => $this->monthly($next)] : null,
                    'waiting' => $list->filter(fn (SalaryStructure $s) => ! $s->approved_at)->count(),
                ];
            }),
        ]);
    }

    /** One person's salary, from the first they were given to the latest. Opening it is written down. */
    public function show(Request $request, int $employee): JsonResponse
    {
        $user = $request->user();
        $employee = Employee::query()->with('department:id,name')->findOrFail($employee);
        Access::requireFor($user, 'wf:salary:view', $employee);
        $list = SalaryStructure::query()->where('employee_id', $employee->id)->with('lines.component')->orderByDesc('effective_from')->orderByDesc('id')->get();
        $names = User::query()->whereIn('id', $list->pluck('created_by')->merge($list->pluck('approved_by'))->filter())->pluck('name', 'id');
        Audit::record('salary.viewed', $employee, null, null, null, $employee->id);
        $own = Employee::query()->where('user_id', $user->id)->value('id') === $employee->id && $user->role->value !== 'admin';

        return response()->json([
            'employee' => $this->brief($employee) + ['joined_on' => $employee->joined_on?->format('Y-m-d')],
            'can_edit' => $user->allows('wf:salary:edit') && Access::reaches($user, 'wf:salary:edit', $employee) && ! $own,
            'structures' => $list->map(fn (SalaryStructure $s) => $this->present($s, $names) + [
                'can_approve' => ! $s->approved_at && ! $own && $user->allows('wf:salary:approve') && ($user->role->value === 'admin' || $s->created_by !== $user->id),
                'can_withdraw' => $user->allows('wf:salary:edit') && ! $own && ! $this->usedInLockedPayroll($s),
            ]),
        ]);
    }

    /** A new salary for someone, from the first of a month. The salary before it is kept. */
    public function store(Request $request, int $employee): JsonResponse
    {
        $user = $request->user();
        $employee = Employee::query()->findOrFail($employee);
        Access::requireFor($user, 'wf:salary:edit', $employee);
        abort_if($user->role->value !== 'admin' && $employee->user_id === $user->id, 403, 'Your own salary must be set by someone else.');
        $data = $request->validate([
            'effective_from' => ['required', 'date_format:Y-m-d'], 'pay_basis' => ['required', Rule::in(['monthly', 'daily', 'hourly'])], 'overtime_rate' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'note' => ['nullable', 'string', 'max:500'], 'lines' => ['required', 'array', 'min:1', 'max:40'], 'lines.*.component_id' => ['required', 'integer', 'distinct'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'], 'lines.*.percent' => ['nullable', 'numeric', 'min:0', 'max:1000'],
        ], ['lines.required' => 'Add at least one part to the salary.', 'lines.*.component_id.distinct' => 'A part can be on a salary only once.']);
        $from = CarbonImmutable::parse($data['effective_from']);
        if ($from->day !== 1) {
            throw ValidationException::withMessages(['effective_from' => 'A salary starts from the first of a month. Someone who joins mid-month is paid only for the days from their joining date.']);
        }
        if ($this->lockedFrom($employee->id, $from->format('Y-m'))) {
            throw ValidationException::withMessages(['effective_from' => 'Payroll for '.$from->format('F Y').' or a later month is already finalised for this person. Choose a later month, or reverse that payroll first.']);
        }
        $components = SalaryComponent::query()->whereIn('id', collect($data['lines'])->pluck('component_id'))->get()->keyBy('id');
        $lines = [];
        foreach ($data['lines'] as $i => $line) {
            $c = $components->get($line['component_id']);
            if (! $c) {
                throw ValidationException::withMessages(["lines.{$i}.component_id" => 'Choose one from the list.']);
            }
            $percent = str_starts_with($c->basis, 'percent');
            $value = $percent ? ($line['percent'] ?? null) : ($line['amount'] ?? null);
            // An earning needs its figure. A deduction may be left to the rule the accountant entered for it.
            if ($value === null && $c->basis !== 'slab_of_gross' && ($c->kind === 'earning' || blank($c->rule[$percent ? 'percent' : 'amount'] ?? null))) {
                throw ValidationException::withMessages(["lines.{$i}.".($percent ? 'percent' : 'amount') => 'Enter the '.($percent ? 'percentage' : 'amount').' for '.$c->name.'.']);
            }
            $lines[] = ['component_id' => $c->id, 'amount' => $percent || $c->basis === 'slab_of_gross' ? null : $value, 'percent' => $percent ? $value : null];
        }

        $structure = DB::transaction(function () use ($employee, $data, $lines, $user) {
            $same = SalaryStructure::query()->where('employee_id', $employee->id)->whereDate('effective_from', $data['effective_from'])->first();
            if ($same?->approved_at) {
                throw ValidationException::withMessages(['effective_from' => 'A salary from that month is already approved. Withdraw it first, or choose a later month.']);
            }
            $same?->delete(); // one still waiting for approval is simply replaced
            $approvedNow = $user->role->value === 'admin' || ! Settings::get('payroll')['salary_needs_approval'];
            $structure = SalaryStructure::create([
                'employee_id' => $employee->id, 'effective_from' => $data['effective_from'], 'pay_basis' => $data['pay_basis'], 'overtime_rate' => $data['overtime_rate'] ?? null, 'note' => $data['note'] ?? null,
                'created_by' => $user->id, 'approved_by' => $approvedNow ? $user->id : null, 'approved_at' => $approvedNow ? now() : null,
            ]);
            $structure->lines()->createMany($lines);

            return $structure->load('lines.component');
        });
        Audit::record('salary.set', $structure, null, ['effective_from' => $data['effective_from'], 'monthly' => $this->monthly($structure), 'approved' => (bool) $structure->approved_at], $data['note'] ?? null, $employee->id);

        return response()->json($this->present($structure, collect([$user->id => $user->name])), 201);
    }

    /** A second person's yes to a salary someone else entered. Until then payroll does not use it. */
    public function approve(Request $request, int $structure): JsonResponse
    {
        $user = $request->user();
        $structure = SalaryStructure::query()->with(['lines.component', 'employee'])->findOrFail($structure);
        Access::requireFor($user, 'wf:salary:approve', $structure->employee);
        abort_if($user->role->value !== 'admin' && ($structure->created_by === $user->id || $structure->employee->user_id === $user->id), 403, 'A salary is approved by someone other than the person who entered it, and never by the person it is for.');
        if ($structure->approved_at) {
            throw ValidationException::withMessages(['structure' => 'This salary is already approved.']);
        }
        $structure->update(['approved_by' => $user->id, 'approved_at' => now()]);
        Audit::record('salary.approved', $structure, null, ['effective_from' => $structure->effective_from->format('Y-m-d'), 'monthly' => $this->monthly($structure)], null, $structure->employee_id);

        return response()->json($this->present($structure, collect([$user->id => $user->name])));
    }

    /** Takes back a salary that no finalised payroll has used. One that a payroll stands on is never removed. */
    public function withdraw(Request $request, int $structure): JsonResponse
    {
        $user = $request->user();
        $structure = SalaryStructure::query()->with(['lines.component', 'employee'])->findOrFail($structure);
        Access::requireFor($user, 'wf:salary:edit', $structure->employee);
        abort_if($user->role->value !== 'admin' && $structure->employee->user_id === $user->id, 403, 'Your own salary must be changed by someone else.');
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Say why this salary is withdrawn.'])['reason'];
        if ($this->usedInLockedPayroll($structure)) {
            throw ValidationException::withMessages(['structure' => 'A finalised payroll was worked out from this salary, so it stays. Enter a new salary from a later month instead.']);
        }
        Audit::record('salary.withdrawn', $structure, ['effective_from' => $structure->effective_from->format('Y-m-d'), 'monthly' => $this->monthly($structure)], null, $reason, $structure->employee_id);
        $structure->delete();

        return response()->json(['withdrawn' => true]);
    }

    /** @return array<string, mixed> */
    private function present(SalaryStructure $s, Collection $names): array
    {
        return [
            'id' => $s->id, 'effective_from' => $s->effective_from->format('Y-m-d'), 'pay_basis' => $s->pay_basis, 'overtime_rate' => $s->overtime_rate, 'note' => $s->note,
            'approved' => (bool) $s->approved_at, 'approved_at' => $s->approved_at?->format('Y-m-d H:i'), 'approved_by' => $names->get($s->approved_by), 'entered_by' => $names->get($s->created_by),
            'monthly' => $this->monthly($s),
            'lines' => $s->lines->sortBy(fn ($l) => [$l->component?->sort, $l->component_id])->values()->map(fn ($l) => [
                'component_id' => $l->component_id, 'code' => $l->component?->code, 'name' => $l->component?->name, 'kind' => $l->component?->kind, 'basis' => $l->component?->basis,
                'amount' => $l->amount, 'percent' => $l->percent === null ? null : (string) (float) $l->percent, 'by_rule' => $l->amount === null && $l->percent === null,
            ]),
        ];
    }

    /** What the person is set to earn in a full month from the parts that are monthly: the figure people quote as "the salary". */
    private function monthly(SalaryStructure $s): string
    {
        $basic = $s->lines->first(fn ($l) => $l->component?->code === PayrollEngine::BASIC && $l->component->basis === 'fixed')?->amount;
        $sum = Money::of(0);
        foreach ($s->lines as $l) {
            if ($l->component?->kind !== 'earning') {
                continue;
            }
            $sum = match ($l->component->basis) {
                'fixed' => $sum->plus(Money::of($l->amount)),
                'percent_of_basic' => $basic === null ? $sum : $sum->plus(Money::percent($basic, $l->percent ?? 0)),
                default => $sum,
            };
        }

        return Money::text($sum);
    }

    /** Whether a finalised, standing payslip exists for this person for that month or a later one. */
    private function lockedFrom(int $employeeId, string $period): bool
    {
        return Payslip::query()->where('employee_id', $employeeId)->where('live_period', '>=', $period)->whereHas('run', fn ($q) => $q->whereIn('status', ['finalised', 'paid']))->exists();
    }

    private function usedInLockedPayroll(SalaryStructure $s): bool
    {
        return (bool) $s->approved_at && $this->lockedFrom($s->employee_id, $s->effective_from->format('Y-m'));
    }
}
