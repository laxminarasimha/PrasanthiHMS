<?php

namespace App\Workforce\Support;

use App\Models\User;
use App\Workforce\Models\Advance;
use App\Workforce\Models\AdvanceLedgerLine;
use App\Workforce\Models\Employee;
use App\Workforce\Models\PayrollRun;
use App\Workforce\Models\Payslip;
use App\Workforce\Models\PayslipLine;
use App\Workforce\Models\SalaryStructure;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A payroll run from start to finish: worked out as a draft, reviewed, approved, finalised, paid, and if it must be,
 * reversed. Each step forward first works the run out again and refuses if the figures no longer match what was
 * looked at, so nobody approves or finalises a payroll that attendance, leave or a salary has since moved under.
 */
final class Payroll
{
    public const MODES = ['bank', 'cash', 'upi', 'cheque'];

    /**
     * The staff who belong in a salary month: employed for at least a day of it.
     *
     * @param  list<int>|null  $only
     * @return Collection<int, Employee>
     */
    public static function staff(string $period, ?array $only = null): Collection
    {
        // Salaries are for the regular staff. Doctors and consultants are paid apart, and are not in a payroll.
        return Employee::query()->regular()->with('department:id,name')
            ->when($only !== null, fn ($q) => $q->whereIn('id', $only))
            ->where(fn ($q) => $q->whereNull('joined_on')->orWhereDate('joined_on', '<=', Period::end($period)))
            ->where(fn ($q) => $q->where(fn ($w) => $w->where('status', '!=', 'inactive')->whereNull('separated_on'))->orWhereDate('separated_on', '>=', Period::start($period)))
            ->orderBy('name')->get();
    }

    /** The salary each of these staff is on at the end of a month: the latest approved one that has begun. */
    public static function salaries(Collection $employeeIds, string $period): Collection
    {
        return SalaryStructure::query()->whereIn('employee_id', $employeeIds)->whereNotNull('approved_at')->whereDate('effective_from', '<=', Period::end($period))
            ->with('lines.component')->orderBy('effective_from')->orderBy('id')->get()->groupBy('employee_id')->map(fn ($list) => $list->last());
    }

    /** Starts a draft for a month and works it out. */
    public static function start(string $period, ?string $label, ?array $only, User $by): PayrollRun
    {
        if ($period > now()->format('Y-m')) {
            throw ValidationException::withMessages(['period' => Period::name($period).' has not begun.']);
        }

        return DB::transaction(function () use ($period, $label, $only, $by) {
            $earlier = PayrollRun::query()->where('period', $period)->where('status', 'reversed')->latest('id')->value('id');
            $run = PayrollRun::create([
                'period' => $period, 'period_start' => Period::start($period)->toDateString(), 'period_end' => Period::end($period)->toDateString(),
                'label' => $label, 'status' => 'draft', 'created_by' => $by->id, 'reversal_of_id' => $earlier, 'rules' => ['only' => $only, 'held_back' => []],
            ]);
            self::workOut($run);
            if ($run->payslips()->count() === 0) {
                $why = collect($run->rules['left_out'] ?? [])->pluck('reason')->unique()->implode(' ');
                throw ValidationException::withMessages(['period' => 'There is nobody to pay in '.Period::name($period).'. '.($why ?: 'No member of staff was employed in that month.')]);
            }

            return $run->fresh();
        });
    }

    /**
     * Works a draft out afresh from attendance, leave, salaries and advances as they stand now, keeping the one-off
     * lines that were added by hand.
     */
    public static function workOut(PayrollRun $run): PayrollRun
    {
        if ($run->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Only a draft is worked out again. Send this back to draft first.']);
        }

        return DB::transaction(function () use ($run) {
            $settings = Settings::get('payroll');
            [$results, $leftOut] = self::figures($run, $settings);
            $existing = $run->payslips()->get()->keyBy('employee_id');
            foreach ($results as $employeeId => $result) {
                $slip = $existing->get($employeeId);
                $values = ['days' => $result['days'], 'pay_as_set' => $result['pay_as_set'], 'gross' => $result['gross'], 'deductions' => $result['deductions'], 'net' => $result['net'], 'employer_cost' => $result['employer_cost']];
                try {
                    // The database allows one standing payslip for a person and a month; a second is left out, not forced.
                    $slip ? $slip->update($values) : $slip = DB::transaction(fn () => Payslip::create($values + ['run_id' => $run->id, 'employee_id' => $employeeId, 'period' => $run->period]));
                } catch (UniqueConstraintViolationException) {
                    $leftOut[] = ['employee_id' => $employeeId, 'reason' => 'Already has a payslip for this month in another payroll.'];

                    continue;
                }
                $slip->lines()->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', '!=', 'one_off'))->delete();
                // The one-off lines stay as they were typed; the worked-out lines are written around them, in payslip order.
                $kept = $slip->lines()->where('source_type', 'one_off')->get();
                foreach ($result['lines'] as $line) {
                    if ($line['source_type'] === 'one_off') {
                        $same = $kept->search(fn (PayslipLine $l) => $l->kind === $line['kind'] && $l->name === $line['name'] && (string) $l->amount === $line['amount']);
                        if ($same !== false) {
                            $kept->pull($same)->update(['sort' => $line['sort']]);
                        }

                        continue;
                    }
                    $slip->lines()->create($line);
                }
            }
            // Someone who no longer belongs in the run (their salary was withdrawn, they were held back) goes out of it.
            // (By staff member. An Eloquent collection's except() goes by the payslip's own id, which is not the same thing.)
            foreach ($existing->reject(fn (Payslip $slip) => isset($results[$slip->employee_id])) as $gone) {
                $gone->lines()->delete();
                $gone->delete();
            }
            $run->update(self::totals($run) + ['rules' => ['payroll' => $settings, 'left_out' => $leftOut, 'worked_out_at' => now()->format('Y-m-d H:i')] + ($run->rules ?? [])]);

            return $run->fresh();
        });
    }

    /**
     * What each person's payslip in this run comes to if worked out now, and who is left out and why.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: list<array{employee_id: int, reason: string}>}
     */
    public static function figures(PayrollRun $run, array $settings): array
    {
        $staff = self::staff($run->period, $run->rules['only'] ?? null);
        $heldBack = $run->rules['held_back'] ?? [];
        $elsewhere = Payslip::query()->where('live_period', $run->period)->where('run_id', '!=', $run->id)->pluck('employee_id')->all();
        $salaries = self::salaries($staff->pluck('id'), $run->period);
        $advances = Advance::query()->whereIn('employee_id', $staff->pluck('id'))->where('status', 'issued')->orderBy('issued_on')->orderBy('id')->get()->groupBy('employee_id');
        $slipIds = $run->payslips()->pluck('id', 'employee_id');
        $oneOffs = PayslipLine::query()->whereIn('payslip_id', $slipIds->values())->where('source_type', 'one_off')->orderBy('id')->get()->groupBy('payslip_id');
        $days = AttendanceRules::days($staff, $run->period_start->format('Y-m-d'), $run->period_end->format('Y-m-d'));

        $results = [];
        $leftOut = [];
        foreach ($staff as $employee) {
            $reason = match (true) {
                in_array($employee->id, $heldBack, true) => 'Left out of this payroll by hand.',
                in_array($employee->id, $elsewhere, true) => 'Already has a payslip for this month in another payroll.',
                ! $salaries->has($employee->id) => SalaryStructure::query()->where('employee_id', $employee->id)->whereNull('approved_at')->exists() ? 'Their salary is waiting for approval.' : 'No salary has been set.',
                default => null,
            };
            if ($reason) {
                $leftOut[] = ['employee_id' => $employee->id, 'name' => $employee->name, 'reason' => $reason];

                continue;
            }
            $due = ($advances->get($employee->id) ?? collect())->map(fn (Advance $a) => ['id' => $a->id, 'no' => $a->advance_no, 'kind' => $a->kind, 'due' => (string) AdvanceBook::dueBy($a, $run->period)])
                ->filter(fn ($a) => Money::of($a['due'])->isPositive())->values()->all();
            $extras = ($oneOffs->get($slipIds->get($employee->id)) ?? collect())->map(fn (PayslipLine $l) => ['kind' => $l->kind, 'name' => $l->name, 'amount' => $l->amount, 'explanation' => $l->explanation])->all();
            $results[$employee->id] = PayrollEngine::work($employee, $run->period, $days[$employee->id], $salaries->get($employee->id), $settings, $due, $extras);
        }

        return [$results, $leftOut];
    }

    /**
     * The staff whose payslip in this run no longer matches what it would come to now. Empty when the run is sound.
     *
     * @return list<string>
     */
    public static function stale(PayrollRun $run): array
    {
        [$now] = self::figures($run, Settings::get('payroll'));
        $names = [];
        foreach ($run->payslips()->with(['employee:id,name', 'lines'])->get() as $slip) {
            $fresh = $now[$slip->employee_id] ?? null;
            $stored = $slip->lines->map(fn (PayslipLine $l) => $l->kind.'|'.$l->code.'|'.$l->amount)->sort()->values()->all();
            $worked = collect($fresh['lines'] ?? [])->map(fn ($l) => $l['kind'].'|'.$l['code'].'|'.$l['amount'])->sort()->values()->all();
            if ($fresh === null || $stored !== $worked) {
                $names[] = $slip->employee?->name ?? 'A member of staff';
            }
        }

        return $names;
    }

    /** Moves a run one step: a draft to reviewed, a reviewed one to approved, or either back to draft. */
    public static function step(PayrollRun $run, string $to, User $by): PayrollRun
    {
        $from = $run->status;
        $allowed = ['draft' => ['reviewed'], 'reviewed' => ['approved', 'draft'], 'approved' => ['draft']];
        if (! in_array($to, $allowed[$from] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "A payroll that is {$from} cannot be made {$to}."]);
        }
        if ($to === 'draft') {
            $run->update(['status' => 'draft', 'reviewed_by' => null, 'reviewed_at' => null, 'approved_by' => null, 'approved_at' => null]);

            return $run->fresh();
        }
        self::sound($run);
        $run->update($to === 'reviewed' ? ['status' => 'reviewed', 'reviewed_by' => $by->id, 'reviewed_at' => now()] : ['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now()]);

        return $run->fresh();
    }

    /**
     * Locks an approved run for good: payslips are numbered, the rules it was worked out with are kept with it, and
     * what was taken for advances is written into their accounts.
     */
    public static function finalise(PayrollRun $run, User $by): PayrollRun
    {
        return DB::transaction(function () use ($run, $by) {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($run->status !== 'approved') {
                throw ValidationException::withMessages(['status' => 'Only an approved payroll is finalised.']);
            }
            self::sound($run);
            $prefix = Settings::get('numbering')['payslip_prefix'].str_replace('-', '', $run->period).'-';
            $next = Payslip::query()->where('period', $run->period)->whereNotNull('slip_no')->count() + 1;
            $touched = [];
            foreach ($run->payslips()->with(['lines', 'employee:id,name'])->orderBy('id')->get() as $slip) {
                $slip->update(['slip_no' => $prefix.str_pad((string) $next++, 4, '0', STR_PAD_LEFT), 'payment_status' => 'unpaid']);
                foreach ($slip->lines->where('source_type', 'advance') as $line) {
                    $advance = Advance::query()->findOrFail($line->source_id);
                    AdvanceBook::line($advance, 'payroll_deduction', Money::of($line->amount)->negated(), now()->toDateString(), 'Taken from the salary for '.Period::name($run->period), $slip->id);
                    $touched[$advance->id] = $advance;
                }
            }
            foreach ($touched as $advance) {
                AdvanceBook::settle($advance);
            }
            $run->update(['status' => 'finalised', 'finalised_by' => $by->id, 'finalised_at' => now(), 'rules' => ['finalised_with' => ['payroll' => Settings::get('payroll'), 'numbering' => Settings::get('numbering')]] + ($run->rules ?? [])]);

            return $run->fresh();
        });
    }

    /**
     * Undoes a finalised run. Its payslips stay on record, marked as reversed with their run; the month is freed so
     * the payroll can be run again; and what was taken for advances is put back as owed, in new ledger lines.
     */
    public static function reverse(PayrollRun $run, string $reason, User $by): PayrollRun
    {
        return DB::transaction(function () use ($run, $reason, $by) {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array($run->status, ['finalised', 'paid'], true)) {
                throw ValidationException::withMessages(['status' => 'Only a finalised payroll is reversed. A draft is simply worked out again.']);
            }
            $slipIds = $run->payslips()->pluck('id');
            $touched = [];
            foreach (AdvanceLedgerLine::query()->whereIn('payslip_id', $slipIds)->where('kind', 'payroll_deduction')->get() as $taken) {
                $advance = Advance::query()->findOrFail($taken->advance_id);
                AdvanceBook::line($advance, 'reversal', Money::of($taken->amount)->negated(), now()->toDateString(), 'Payroll for '.Period::name($run->period).' was reversed: '.$reason, $taken->payslip_id);
                $touched[$advance->id] = $advance;
            }
            foreach ($touched as $advance) {
                AdvanceBook::settle($advance);
            }
            Payslip::query()->where('run_id', $run->id)->update(['live_period' => null]);
            $run->update(['status' => 'reversed', 'reversed_by' => $by->id, 'reversed_at' => now(), 'reverse_reason' => $reason]);

            return $run->fresh();
        });
    }

    /**
     * Writes down that salaries were paid (or are being held back). No money is moved from here: this is the record
     * of a payment made at the bank or from the cash box. What was paid is posted as one paid expense under the
     * salaries heading, dated the day it was paid, so the day's cash count and the reports see it once.
     *
     * A salary paid under a payroll that was later reversed was still paid. When the month is run again, only what
     * had not already been paid to that person for that month is posted, and the payslip keeps both figures.
     *
     * @param  list<int>  $slipIds
     * @param  array{status: string, paid_on?: string|null, payment_mode?: string|null, payment_reference?: string|null}  $payment
     */
    public static function pay(PayrollRun $run, array $slipIds, array $payment, User $by): PayrollRun
    {
        return DB::transaction(function () use ($run, $slipIds, $payment, $by) {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array($run->status, ['finalised', 'paid'], true)) {
                throw ValidationException::withMessages(['status' => 'Payment is recorded only once a payroll is finalised.']);
            }
            $slips = $run->payslips()->with('employee:id,name')->whereIn('id', $slipIds)->get();
            $done = $slips->where('payment_status', 'paid')->map(fn (Payslip $s) => $s->employee?->name)->filter();
            if ($done->isNotEmpty()) {
                throw ValidationException::withMessages(['payslip_ids' => 'Already recorded as paid: '.$done->implode(', ').'. A salary recorded as paid stays paid; if that was a mistake, reverse the payroll.']);
            }
            $paid = $payment['status'] === 'paid';
            $posted = BigDecimal::zero()->toScale(2);
            $numbers = [];
            foreach ($slips as $slip) {
                $now = null;
                if ($paid) {
                    $now = BigDecimal::max(BigDecimal::zero(), Money::paise($slip->net)->minus(self::alreadyPaid($slip)))->toScale(2);
                    $posted = $posted->plus($now);
                    $numbers[] = $slip->slip_no;
                }
                $slip->update([
                    'payment_status' => $payment['status'], 'paid_on' => $paid ? $payment['paid_on'] : null, 'paid_amount' => $now === null ? null : (string) $now,
                    'payment_mode' => $paid ? $payment['payment_mode'] : null, 'payment_reference' => $paid ? ($payment['payment_reference'] ?? null) : null, 'paid_by' => $paid ? $by->id : null,
                ]);
            }
            if ($paid && $posted->isPositive()) {
                ExpenseBook::post('payroll_payment', $run->id, ExpenseBook::SALARIES, $posted, $payment['paid_on'], $payment['payment_mode'], $payment['payment_reference'] ?? null, count($numbers) === 1 ? $slips->first()->employee?->name : null,
                    'Salaries for '.Period::name($run->period).': '.count($numbers).' payslip'.(count($numbers) === 1 ? '' : 's').' ('.implode(', ', array_slice(array_filter($numbers), 0, 12)).(count($numbers) > 12 ? ', …' : '').')');
            }
            $waiting = $run->payslips()->where('payment_status', '!=', 'paid')->exists();
            if (! $waiting && $run->status === 'finalised') {
                $run->update(['status' => 'paid']);
            }

            return $run->fresh();
        });
    }

    /** What was already paid to this person for this month under payrolls since reversed. */
    public static function alreadyPaid(Payslip $slip): BigDecimal
    {
        return Money::paise(Payslip::query()->where('employee_id', $slip->employee_id)->where('period', $slip->period)->where('id', '!=', $slip->id)
            ->where('payment_status', 'paid')->whereHas('run', fn ($q) => $q->where('status', 'reversed'))->sum(DB::raw('COALESCE(paid_amount, net)')));
    }

    /** Refuses to let a run go forward empty, with someone below nothing, or out of step with the records it was worked out from. */
    private static function sound(PayrollRun $run): void
    {
        if ($run->payslips()->count() === 0) {
            throw ValidationException::withMessages(['status' => 'There is nobody in this payroll.']);
        }
        $below = $run->payslips()->where('net', '<', 0)->with('employee:id,name')->get()->map(fn (Payslip $s) => $s->employee?->name)->filter()->implode(', ');
        if ($below !== '') {
            throw ValidationException::withMessages(['status' => 'Net pay is below nothing for '.$below.'. Change a deduction first.']);
        }
        $stale = self::stale($run);
        if ($stale !== []) {
            throw ValidationException::withMessages(['status' => 'Attendance, leave, salary or an advance has changed since this was worked out, for '.implode(', ', array_slice($stale, 0, 5)).(count($stale) > 5 ? ' and '.(count($stale) - 5).' more' : '').'. Send it back to draft and work it out again.']);
        }
    }

    /** @return array{gross: string, deductions: string, net: string, employer_cost: string} */
    private static function totals(PayrollRun $run): array
    {
        $sums = $run->payslips()->selectRaw('COALESCE(SUM(gross), 0) as gross, COALESCE(SUM(deductions), 0) as deductions, COALESCE(SUM(net), 0) as net, COALESCE(SUM(employer_cost), 0) as employer_cost')->first();

        return ['gross' => Money::text($sums->gross), 'deductions' => Money::text($sums->deductions), 'net' => Money::text($sums->net), 'employer_cost' => Money::text($sums->employer_cost)];
    }
}
