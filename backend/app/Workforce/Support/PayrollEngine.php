<?php

namespace App\Workforce\Support;

use App\Workforce\Models\Employee;
use App\Workforce\Models\SalaryStructure;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Works out one person's pay for one salary month. It reads nothing and saves nothing: it is given the person's
 * days (as attendance and leave see them), the salary in force, what is owed on advances and any one-off lines, and
 * returns the payslip, every line with how its amount was arrived at. The same inputs always give the same payslip,
 * which is how a run is checked for having gone stale before it moves forward.
 *
 * Nothing about any hospital or any law is written in here. A statutory part is worked out only from the rule the
 * accountant entered for it; with no rule it is left out and the payslip says so.
 */
final class PayrollEngine
{
    /** The part of a salary that "percent of basic" means. */
    public const BASIC = 'BASIC';

    /**
     * @param  array<string, array<string, mixed>>  $days  the person's days in the month, from AttendanceRules::days()
     * @param  array<string, mixed>  $settings  the branch's payroll choices
     * @param  list<array{id: int, no: string|null, kind: string, due: string}>  $advances  what is due back this month, oldest first
     * @param  list<array{kind: string, name: string, amount: string, explanation: string}>  $oneOffs
     * @return array{days: array<string, mixed>, pay_as_set: array<string, mixed>, lines: list<array<string, mixed>>, gross: string, deductions: string, net: string, employer_cost: string}
     */
    public static function work(Employee $employee, string $period, array $days, SalaryStructure $structure, array $settings, array $advances = [], array $oneOffs = []): array
    {
        $totals = AttendanceRules::totals($days);
        $inMonth = Period::days($period);
        $notEmployed = count(array_filter($days, fn ($d) => $d['status'] === 'not_employed'));
        $employed = $inMonth - $notEmployed;
        $unpaidLeave = round($totals['leave'] - $totals['paid_leave'], 2);
        // A hospital that does not keep attendance here yet takes pay off only for leave without pay.
        $absent = ($settings['deduct_absence'] ?? true) ? (float) $totals['absent'] : 0.0;
        $unpaid = round($absent + $unpaidLeave, 2);
        $paidDays = round($totals['present'] + $totals['paid_leave'] + (! empty($settings['daily_paid_days_off']) ? $totals['holidays'] + $totals['weekly_offs'] : 0), 2);
        $divisor = match ($settings['month_days'] ?? 'calendar') {
            'fixed_30' => 30, 'fixed_26' => 26, default => $inMonth,
        };
        $notes = [];
        if ($absent > 0 && array_sum(array_map(fn ($d) => count($d['records']), $days)) === 0) {
            $notes[] = 'No attendance at all is recorded for this person this month, so every working day counts as absent. If attendance is not kept here yet, switch off “Take pay off for absence” in Setup.';
        }
        if ($totals['open'] > 0) {
            $notes[] = $totals['open'].' day'.($totals['open'] === 1 ? ' has' : 's have').' a check-in with no check-out, counted as worked.';
        }
        $unfinished = count(array_filter($days, fn ($d) => in_array($d['status'], ['', 'not_in'], true)));
        if ($unfinished > 0) {
            $notes[] = 'The month had not ended when this was worked out: '.$unfinished.' day'.($unfinished === 1 ? '' : 's').' still to come '.($unfinished === 1 ? 'is' : 'are').' counted as worked.';
        }

        $parts = $structure->lines->filter(fn ($l) => $l->component)->sortBy(fn ($l) => [$l->component->sort, $l->component->id])->values();
        $lines = [];
        $add = function (string $kind, string $code, string $name, BigDecimal $amount, string $explanation, ?int $componentId = null, ?string $sourceType = null, ?int $sourceId = null) use (&$lines) {
            $lines[] = ['kind' => $kind, 'code' => $code, 'name' => $name, 'amount' => Money::text($amount), 'explanation' => mb_substr($explanation, 0, 500), 'component_id' => $componentId, 'source_type' => $sourceType, 'source_id' => $sourceId, 'sort' => count($lines)];
        };
        $zero = BigDecimal::zero()->toScale(2);

        // ---- Earnings ----
        $forMonth = $employed < $inMonth ? ', for the '.$employed.' of '.$inMonth.' days employed this month' : '';
        $reducing = $zero;          // a full month of the earnings that shrink with unpaid days
        $reducingPaid = $zero;      // those earnings as paid this month (less for someone who joined or left mid-month)
        $basicSet = null;           // basic pay for a full month
        $basicPaid = $zero;
        $basicReduces = false;
        $setGross = $zero;          // everything the person is set to earn in a full month, for a rule that depends on it
        foreach ($parts->filter(fn ($l) => $l->component->kind === 'earning') as $line) {
            $c = $line->component;
            if (! $c->is_enabled) {
                $notes[] = $c->name.' is switched off, so it was left out.';

                continue;
            }
            [$set, $how] = match ($c->basis) {
                'fixed' => [Money::paise($line->amount), Money::show($line->amount).' a month'],
                'percent_of_basic' => $basicSet === null ? [null, ''] : [Money::percent($basicSet, $line->percent ?? 0), Money::count($line->percent ?? 0).'% of basic pay '.Money::show($basicSet)],
                'per_day' => [Money::paise(Money::of($line->amount)->multipliedBy(Money::of($paidDays))), Money::show($line->amount).' a day × '.Money::count($paidDays).' paid day'.($paidDays == 1 ? '' : 's').' ('.Money::count($totals['present']).' worked, '.Money::count($totals['paid_leave']).' paid leave'.(! empty($settings['daily_paid_days_off']) ? ', '.($totals['holidays'] + $totals['weekly_offs']).' days off' : '').')'],
                'per_hour' => [Money::share($line->amount, $totals['worked_minutes'], 60), Money::show($line->amount).' an hour × '.self::hours($totals['worked_minutes'])],
                default => [null, ''],
            };
            if ($c->basis === 'percent_of_basic' && $basicSet !== null && $line->percent === null) {
                $notes[] = $c->name.' has no percentage entered. It was left out.';

                continue;
            }
            if ($set === null) {
                $notes[] = $c->basis === 'percent_of_basic' ? $c->name.' is a percentage of basic pay, but no basic pay is set above it. It was left out.' : $c->name.' cannot be an earning worked out that way. It was left out.';

                continue;
            }
            $monthly = in_array($c->basis, ['fixed', 'percent_of_basic'], true);
            $paid = $monthly && $employed < $inMonth ? Money::share($set, $employed, $inMonth) : $set;
            if ($monthly) {
                $setGross = $setGross->plus($set);
                if ($c->reduces_with_absence) {
                    $reducing = $reducing->plus($set);
                    $reducingPaid = $reducingPaid->plus($paid);
                }
            }
            if ($c->code === self::BASIC) {
                $basicSet = $set;
                $basicPaid = $paid;
                $basicReduces = $monthly && $c->reduces_with_absence;
            }
            if ($paid->isZero()) {
                continue;
            }
            $add('earning', $c->code, $c->name, $paid, $how.($monthly ? $forMonth : ''), $c->id);
        }
        if ($structure->overtime_rate !== null && $totals['overtime_minutes'] > 0) {
            $add('earning', 'OT', 'Overtime', Money::share($structure->overtime_rate, $totals['overtime_minutes'], 60), self::hours($totals['overtime_minutes']).' of overtime × '.Money::show($structure->overtime_rate).' an hour');
        }
        foreach ($oneOffs as $extra) {
            if ($extra['kind'] === 'earning') {
                $add('earning', 'EXTRA', $extra['name'], Money::paise($extra['amount']), $extra['explanation'], null, 'one_off');
            }
        }
        $gross = self::sum($lines, 'earning');

        // ---- Unpaid days ----
        $lost = $zero;
        if ($unpaid > 0 && $reducing->isPositive()) {
            $lost = BigDecimal::min(Money::share($reducing, $unpaid, $divisor), $reducingPaid);
            $made = array_filter([$absent > 0 ? Money::count($absent).' absent' : null, $unpaidLeave > 0 ? Money::count($unpaidLeave).' leave without pay' : null]);
            $add('deduction', 'UNPAID', 'Unpaid days', $lost, Money::count($unpaid).' unpaid day'.($unpaid == 1 ? '' : 's').' ('.implode(', ', $made).') at '.Money::show($reducing->dividedBy($divisor, 2, RoundingMode::HalfUp)).' a day ('.Money::show($reducing).' ÷ '.$divisor.')');
        }
        // What was actually earned, for the parts that are a share of pay.
        $grossEarned = $gross->minus($lost);
        $basicEarned = $basicSet !== null && $basicReduces ? BigDecimal::max($zero, $basicPaid->minus(Money::share($basicSet, $unpaid, $divisor))) : $basicPaid;

        // ---- Deductions the salary carries, then the hospital's own share (worked out the same way) ----
        foreach (['deduction', 'employer'] as $kind) {
            foreach ($parts->filter(fn ($l) => $l->component->kind === $kind) as $line) {
                $c = $line->component;
                if (! $c->is_enabled) {
                    $notes[] = $c->name.' is switched off, so nothing was worked out for it.';

                    continue;
                }
                $found = self::fromRule($c->basis, $c->rule ?? [], $line->amount, $line->percent, $basicEarned, $grossEarned, $setGross);
                if ($found === null) {
                    $notes[] = $c->name.' is switched on but has no '.($c->basis === 'fixed' ? 'amount' : 'rate').' entered. Nothing was worked out for it.';

                    continue;
                }
                if ($found[0]->isPositive()) {
                    $add($kind, $c->code, $c->name, $found[0], $found[1], $c->id);
                }
            }
            if ($kind === 'deduction') {
                foreach ($oneOffs as $extra) {
                    if ($extra['kind'] === 'deduction') {
                        $add('deduction', 'EXTRA', $extra['name'], Money::paise($extra['amount']), $extra['explanation'], null, 'one_off');
                    }
                }
                // Advances and loans come last, out of what is left, so a salary is never taken below nothing by one.
                $left = BigDecimal::max($zero, $gross->minus(self::sum($lines, 'deduction')));
                foreach ($advances as $advance) {
                    $due = Money::paise($advance['due']);
                    $take = BigDecimal::min($due, $left);
                    if (! $take->isPositive()) {
                        if ($due->isPositive()) {
                            $notes[] = 'Nothing was left this month to take back '.Money::show($due).' of '.($advance['no'] ?? 'an advance').'. It stays owed and is taken next month.';
                        }

                        continue;
                    }
                    $left = $left->minus($take);
                    $label = ($advance['kind'] === 'loan' ? 'Loan' : 'Advance').($advance['no'] ? ' '.$advance['no'] : '');
                    $add('deduction', 'ADVANCE', $label.' repayment', $take, $take->isEqualTo($due) ? 'This month’s part of '.$label : Money::show($take).' of the '.Money::show($due).' due on '.$label.': the salary did not cover the rest, which stays owed', null, 'advance', $advance['id']);
                }
            }
        }

        // ---- Net pay, rounded if the hospital rounds ----
        $deductions = self::sum($lines, 'deduction');
        $net = $gross->minus($deductions);
        if (($settings['round_net_to'] ?? 'rupee') === 'rupee') {
            $odd = $net->toScale(0, RoundingMode::HalfUp)->toScale(2)->minus($net);
            if (! $odd->isZero()) {
                $add($odd->isPositive() ? 'earning' : 'deduction', 'ROUND', 'Rounding', $odd->abs(), 'Net pay '.Money::show($net).' rounded to the nearest rupee');
                $gross = self::sum($lines, 'earning');
                $deductions = self::sum($lines, 'deduction');
                $net = $gross->minus($deductions);
            }
        }
        if ($net->isNegative()) {
            $notes[] = 'The deductions come to more than the earnings. Net pay cannot be below nothing: change a deduction before this goes forward.';
        }

        return [
            'days' => [
                'in_month' => $inMonth, 'employed' => $employed, 'present' => $totals['present'], 'paid_leave' => $totals['paid_leave'], 'unpaid_leave' => $unpaidLeave, 'absent' => $totals['absent'], 'absence_counted' => (bool) ($settings['deduct_absence'] ?? true),
                'holidays' => $totals['holidays'], 'weekly_offs' => $totals['weekly_offs'], 'unpaid' => $unpaid, 'paid_days' => $paidDays,
                'worked_minutes' => $totals['worked_minutes'], 'overtime_minutes' => $totals['overtime_minutes'], 'notes' => $notes,
            ],
            'pay_as_set' => [
                'structure_id' => $structure->id, 'effective_from' => $structure->effective_from->format('Y-m-d'), 'pay_basis' => $structure->pay_basis, 'overtime_rate' => $structure->overtime_rate,
                'lines' => $parts->map(fn ($l) => ['code' => $l->component->code, 'name' => $l->component->name, 'kind' => $l->component->kind, 'basis' => $l->component->basis, 'amount' => $l->amount, 'percent' => $l->percent, 'rule' => $l->component->rule])->all(),
            ],
            'lines' => $lines,
            'gross' => Money::text($gross), 'deductions' => Money::text($deductions), 'net' => Money::text($net),
            // What the month costs the hospital: the pay actually earned, plus its own contributions.
            'employer_cost' => Money::text($gross->minus($lost)->plus(self::sum($lines, 'employer'))),
        ];
    }

    /**
     * A deduction or an employer's share, from the amounts on the person's salary and the rule entered for the part.
     * Returns the amount and how it was arrived at, or null when there is nothing to work it out from.
     *
     * A rule may hold: `percent`; `ceiling` (the most pay the percentage is worked on); `only_if_pay_up_to` (the part
     * applies only to someone whose set pay for a full month is at most this); `amount` (a fixed sum); `slabs`
     * (a list of {up_to, amount}: the sum for the month's earned pay, the last slab having no upper end); and
     * `round` (`rupee`, `rupee_up` or nothing).
     *
     * @param  array<string, mixed>  $rule
     * @return array{0: BigDecimal, 1: string}|null
     */
    public static function fromRule(string $basis, array $rule, mixed $amount, mixed $percent, BigDecimal $basic, BigDecimal $gross, BigDecimal $setGross): ?array
    {
        $zero = BigDecimal::zero()->toScale(2);
        if (isset($rule['only_if_pay_up_to']) && $rule['only_if_pay_up_to'] !== '' && $setGross->isGreaterThan(Money::of($rule['only_if_pay_up_to']))) {
            return [$zero, ''];
        }
        $found = null;
        switch ($basis) {
            case 'fixed':
                $fixed = $amount ?? ($rule['amount'] ?? null);
                $found = $fixed === null || $fixed === '' ? null : [Money::paise($fixed), 'A fixed '.Money::show($fixed).' a month'];
                break;
            case 'percent_of_basic':
            case 'percent_of_gross':
                $rate = $percent ?? ($rule['percent'] ?? null);
                if ($rate === null || $rate === '') {
                    break;
                }
                $on = $basis === 'percent_of_basic' ? $basic : $gross;
                $what = $basis === 'percent_of_basic' ? 'basic pay earned' : 'pay earned';
                $capped = isset($rule['ceiling']) && $rule['ceiling'] !== '' && $on->isGreaterThan(Money::of($rule['ceiling']));
                $base = $capped ? Money::paise($rule['ceiling']) : $on;
                $found = [Money::percent($base, $rate), Money::count($rate).'% of '.($capped ? Money::show($base).' (the most it is worked on; '.$what.' is '.Money::show($on).')' : $what.' '.Money::show($on))];
                break;
            case 'slab_of_gross':
                foreach ($rule['slabs'] ?? [] as $slab) {
                    if (! isset($slab['up_to']) || $slab['up_to'] === '' || $slab['up_to'] === null || $gross->isLessThanOrEqualTo(Money::of($slab['up_to']))) {
                        $found = [Money::paise($slab['amount'] ?? 0), 'For pay earned of '.Money::show($gross).' ('.(isset($slab['up_to']) && $slab['up_to'] !== '' && $slab['up_to'] !== null ? 'up to '.Money::show($slab['up_to']) : 'the highest slab').')'];
                        break;
                    }
                }
                break;
        }
        if ($found === null) {
            return null;
        }
        $round = $rule['round'] ?? null;
        if (in_array($round, ['rupee', 'rupee_up'], true)) {
            $rounded = $found[0]->toScale(0, $round === 'rupee_up' ? RoundingMode::Up : RoundingMode::HalfUp)->toScale(2);
            if (! $rounded->isEqualTo($found[0])) {
                $found = [$rounded, $found[1].', rounded '.($round === 'rupee_up' ? 'up to the next' : 'to the nearest').' rupee'];
            }
        }

        return $found;
    }

    /** @param list<array<string, mixed>> $lines */
    private static function sum(array $lines, string $kind): BigDecimal
    {
        $sum = BigDecimal::zero()->toScale(2);
        foreach ($lines as $line) {
            if ($line['kind'] === $kind) {
                $sum = $sum->plus($line['amount']);
            }
        }

        return $sum;
    }

    private static function hours(int $minutes): string
    {
        return intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    }
}
