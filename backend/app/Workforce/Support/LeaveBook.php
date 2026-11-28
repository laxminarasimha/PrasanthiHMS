<?php

namespace App\Workforce\Support;

use App\Support\LeaveOnCalendar;
use App\Workforce\Models\Employee;
use App\Workforce\Models\Holiday;
use App\Workforce\Models\HolidayCalendar;
use App\Workforce\Models\LeaveLedgerLine;
use App\Workforce\Models\LeaveRequest;
use App\Workforce\Models\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The leave accounts. A balance is never stored: it is the sum of the lines in a person's ledger for a leave year.
 * Every step here that earns, takes, carries or lapses leave writes a line, and each such line can only be written
 * once, so running a step again changes nothing.
 */
final class LeaveBook
{
    /** The leave year a date falls in, named by the calendar year it starts in. */
    public static function yearOf(string $date): int
    {
        $d = CarbonImmutable::parse($date);
        $starts = (int) Settings::get('leave')['year_starts_month'];

        return $d->month >= $starts ? $d->year : $d->year - 1;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the first and last day of a leave year */
    public static function span(int $year): array
    {
        $start = CarbonImmutable::create($year, (int) Settings::get('leave')['year_starts_month'], 1)->startOfDay();

        return [$start, $start->addYear()->subDay()];
    }

    public static function balance(int $employeeId, int $typeId, int $year): float
    {
        return round((float) LeaveLedgerLine::query()->where(['employee_id' => $employeeId, 'leave_type_id' => $typeId, 'leave_year' => $year])->sum('days'), 2);
    }

    /**
     * How many days of leave a request comes to. A half day at either end counts a half; weekly offs and holidays
     * inside the stretch are not leave unless the hospital has chosen to count them.
     */
    public static function daysBetween(Employee $employee, string $from, string $to, string $fromPart = 'full', string $toPart = 'full'): float
    {
        $settings = Settings::get('leave');
        $off = $employee->weekly_off ?? Settings::get('attendance')['default_weekly_off'];
        $calendar = $employee->holiday_calendar_id ?? HolidayCalendar::query()->where('is_default', true)->value('id');
        $holidays = $calendar ? Holiday::query()->where('calendar_id', $calendar)->where('is_optional', false)->whereBetween('on', [$from, $to])->pluck('on')->map(fn ($d) => $d->format('Y-m-d'))->all() : [];
        $names = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

        $days = 0.0;
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
            $date = $d->toDateString();
            if (! $settings['count_days_off'] && (in_array($names[$d->dayOfWeek], $off, true) || in_array($date, $holidays, true))) {
                continue;
            }
            $half = ($date === $from && $fromPart !== 'full') || ($date === $to && $toPart !== 'full');
            $days += $half ? 0.5 : 1.0;
        }

        return $days;
    }

    /**
     * Gives each active member of staff what they earn of each kind of leave: the whole year's allowance at the
     * start for a yearly kind (in proportion for someone who joined part-way), or a twelfth a month, up to the
     * month given, for a monthly kind. Returns how many lines were written; running it again writes none.
     */
    public static function accrue(int $year, ?int $uptoMonth = null): int
    {
        [$start, $end] = self::span($year);
        $written = 0;
        $types = LeaveType::query()->where('is_active', true)->where('accrual', '!=', 'none')->where('annual_allowance', '>', 0)->get();
        $staff = Employee::query()->where('status', '!=', 'inactive')->get();

        foreach ($staff as $employee) {
            $joined = $employee->joined_on ? CarbonImmutable::parse($employee->joined_on) : null;
            if ($joined && $joined->greaterThan($end)) {
                continue;
            }
            foreach ($types as $type) {
                if ($type->accrual === 'yearly') {
                    // A whole month counts if they were there on its first day; someone joining mid-year earns for the months left.
                    $months = $joined && $joined->greaterThan($start) ? 12 - (int) $start->diffInMonths($joined->startOfMonth()) - ($joined->day > 1 ? 1 : 0) : 12;
                    $days = self::half($type->annual_allowance * max(0, $months) / 12);
                    $written += self::line($employee->id, $type->id, $year, 'accrual', $days, ($joined && $joined->greaterThan($start) ? $joined : $start)->toDateString(), "accrual:{$type->code}:{$year}", $months < 12 ? "Allowance for {$months} of 12 months" : 'Allowance for the year');

                    continue;
                }
                for ($i = 0; $i < 12; $i++) {
                    $month = $start->addMonths($i);
                    // A month is earned by someone who was on the staff on its first day.
                    if (($uptoMonth !== null && $i + 1 > $uptoMonth) || ($joined && $joined->greaterThan($month))) {
                        continue;
                    }
                    $written += self::line($employee->id, $type->id, $year, 'accrual', round($type->annual_allowance / 12, 2), $month->toDateString(), "accrual:{$type->code}:{$month->format('Y-m')}", 'Earned for '.$month->format('M Y'));
                }
            }
        }

        return $written;
    }

    /**
     * Closes a leave year for everyone: what is left of each kind goes into the next year up to the kind's limit,
     * and the rest lapses. The old year ends at nothing. Returns the lines written; running it again writes none.
     */
    public static function closeYear(int $year): int
    {
        [, $end] = self::span($year);
        $written = 0;
        foreach (LeaveType::query()->get() as $type) {
            $balances = LeaveLedgerLine::query()->where(['leave_type_id' => $type->id, 'leave_year' => $year])->selectRaw('employee_id, SUM(days) as left_over')->groupBy('employee_id')->get();
            foreach ($balances as $row) {
                $left = round((float) $row->left_over, 2);
                if ($left <= 0) {
                    continue;
                }
                $carry = min($left, (float) $type->carry_forward_max);
                if ($carry > 0) {
                    $written += self::line($row->employee_id, $type->id, $year, 'carry_forward', -$carry, $end->toDateString(), "carried-out:{$type->code}:{$year}", 'Carried into '.($year + 1));
                    $written += self::line($row->employee_id, $type->id, $year + 1, 'carry_forward', $carry, $end->addDay()->toDateString(), "carried-in:{$type->code}:{$year}", "Carried from {$year}");
                }
                if ($left - $carry > 0) {
                    $written += self::line($row->employee_id, $type->id, $year, 'expiry', -($left - $carry), $end->toDateString(), "lapsed:{$type->code}:{$year}", 'Not taken and not carried: lapsed');
                }
            }
        }

        return $written;
    }

    /**
     * Lapses carried-in leave that was not used within the months its kind allows. Carried days are counted as used
     * first, so what lapses is the carried amount less everything taken in the year so far.
     */
    public static function expireCarried(int $year, ?string $asOf = null): int
    {
        $asOf = CarbonImmutable::parse($asOf ?? now()->toDateString());
        [$start] = self::span($year);
        $written = 0;
        foreach (LeaveType::query()->whereNotNull('carry_forward_expires_months')->get() as $type) {
            $lapsesOn = $start->addMonths((int) $type->carry_forward_expires_months)->subDay();
            if ($asOf->lessThanOrEqualTo($lapsesOn)) {
                continue;
            }
            $carried = LeaveLedgerLine::query()->where(['leave_type_id' => $type->id, 'leave_year' => $year, 'kind' => 'carry_forward'])->where('days', '>', 0)->get();
            foreach ($carried as $line) {
                $used = -(float) LeaveLedgerLine::query()->where(['employee_id' => $line->employee_id, 'leave_type_id' => $type->id, 'leave_year' => $year])
                    ->whereIn('kind', ['taken', 'reversal'])->where('entry_date', '<=', $lapsesOn->toDateString())->sum('days');
                $unused = round((float) $line->days - max(0, $used), 2);
                if ($unused > 0) {
                    $written += self::line($line->employee_id, $type->id, $year, 'expiry', -$unused, $lapsesOn->toDateString(), "carried-lapsed:{$type->code}:{$year}", "Carried leave not used within {$type->carry_forward_expires_months} months: lapsed");
                }
            }
        }

        return $written;
    }

    /** Approves a request and takes its days from the account. Refused if the account does not hold them. */
    public static function approve(LeaveRequest $request, ?string $note = null): LeaveRequest
    {
        return DB::transaction(function () use ($request, $note) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'This request has already been '.$request->status.'.']);
            }
            $year = self::yearOf($request->from_date->format('Y-m-d'));
            $type = $request->type;
            $left = self::balance($request->employee_id, $type->id, $year);
            if ($type->is_paid && $type->annual_allowance > 0 && ! Settings::get('leave')['allow_negative'] && $left < $request->days) {
                throw ValidationException::withMessages(['days' => "Only {$left} days of {$type->name} are left; this request is for {$request->days}."]);
            }
            $request->update(['status' => 'approved', 'decided_by' => auth()->id(), 'decided_at' => now(), 'decision_note' => $note]);
            self::line($request->employee_id, $type->id, $year, 'taken', -$request->days, $request->from_date->format('Y-m-d'), "taken:{$request->id}", null, $request->id);
            // A doctor's leave closes their appointment calendar for those days, so it is entered once.
            LeaveOnCalendar::block($request);

            return $request;
        });
    }

    /** Withdraws a request. If it had been approved, its days go back into the account as a line of their own. */
    public static function cancel(LeaveRequest $request, string $reason): LeaveRequest
    {
        return DB::transaction(function () use ($request, $reason) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->id);
            if (! in_array($request->status, ['pending', 'approved'], true)) {
                throw ValidationException::withMessages(['status' => 'This request is already '.$request->status.'.']);
            }
            if ($request->status === 'approved') {
                self::line($request->employee_id, $request->leave_type_id, self::yearOf($request->from_date->format('Y-m-d')), 'reversal', $request->days, now()->toDateString(), "reversal:{$request->id}", 'Leave cancelled: '.$reason, $request->id);
            }
            $request->update(['status' => 'cancelled', 'decision_note' => $reason, 'decided_by' => auth()->id(), 'decided_at' => now()]);
            LeaveOnCalendar::unblock($request);

            return $request;
        });
    }

    /** Writes one line, unless a line with the same key is already there. Returns 1 if written, 0 if it was. */
    public static function line(int $employeeId, int $typeId, int $year, string $kind, float $days, string $date, ?string $onceKey, ?string $note = null, ?int $requestId = null): int
    {
        if ($days == 0.0) {
            return 0;
        }
        if ($onceKey !== null && LeaveLedgerLine::query()->where(['employee_id' => $employeeId, 'once_key' => $onceKey])->exists()) {
            return 0;
        }
        try {
            // Its own savepoint: on some databases a refused insert would otherwise spoil the transaction around it.
            DB::transaction(fn () => LeaveLedgerLine::create([
                'employee_id' => $employeeId, 'leave_type_id' => $typeId, 'leave_year' => $year, 'kind' => $kind, 'days' => $days, 'entry_date' => $date,
                'once_key' => $onceKey, 'note' => $note, 'leave_request_id' => $requestId, 'created_by' => auth()->id(), 'created_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return 0; // written by someone else in the same moment
        }

        return 1;
    }

    /** To the nearest half day, which is the smallest amount of leave there is. */
    private static function half(float $days): float
    {
        return round($days * 2) / 2;
    }
}
