<?php

namespace App\Workforce\Support;

use App\Workforce\Models\Attendance;
use App\Workforce\Models\Employee;
use App\Workforce\Models\RosterDay;
use App\Workforce\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Which duty a member of staff is on, day by day. The roster says so where it differs from their usual shift and
 * weekly off; otherwise the usual applies. Attendance, the day's overview and payroll all ask here, so a nurse moved
 * to night duty for a week is measured against night duty that week.
 */
final class Roster
{
    private const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /**
     * The roster lines for these staff in a stretch, keyed "employee id|date".
     *
     * @return Collection<string, RosterDay>
     */
    public static function lines(Collection $employeeIds, string $from, string $to): Collection
    {
        return RosterDay::query()->whereIn('employee_id', $employeeIds)->whereBetween('work_date', [$from, $to])->get()
            ->keyBy(fn (RosterDay $d) => $d->employee_id.'|'.$d->work_date->format('Y-m-d'));
    }

    /**
     * A person's duty on a day: the shift (or none), whether it is their day off, and whether that came from the roster.
     *
     * @param  array<int, string>|null  $usualOff  the hospital's usual weekly off, for someone with none of their own
     * @return array{shift_id: int|null, off: bool, rostered: bool}
     */
    public static function duty(Employee $employee, string $date, ?RosterDay $line, ?array $usualOff = null): array
    {
        if ($line) {
            return ['shift_id' => $line->is_off ? null : $line->shift_id, 'off' => (bool) $line->is_off, 'rostered' => true];
        }
        $off = $employee->weekly_off ?? $usualOff ?? Settings::get('attendance')['default_weekly_off'];

        return ['shift_id' => $employee->shift_id, 'off' => in_array(self::DAYS[CarbonImmutable::parse($date)->dayOfWeek], $off, true), 'rostered' => false];
    }

    /**
     * The shift a person's check-in at this moment belongs to, and the day it counts for. A check-in in the small
     * hours, while a night duty that began the evening before is still running and has no check-in yet, belongs to
     * that night duty and to the day before.
     *
     * @return array{shift_id: int|null, work_date: string}
     */
    public static function forCheckIn(Employee $employee, string $at): array
    {
        $moment = CarbonImmutable::parse($at);
        $today = $moment->toDateString();
        $yesterday = $moment->subDay()->toDateString();
        $lines = self::lines(collect([$employee->id]), $yesterday, $today);
        $before = self::duty($employee, $yesterday, $lines->get($employee->id.'|'.$yesterday));
        if ($before['shift_id'] && ! $before['off']) {
            $shift = Shift::query()->find($before['shift_id']);
            if ($shift?->crossesMidnight()) {
                [, $ends] = AttendanceRules::window($yesterday, $shift);
                $started = Attendance::query()->where('employee_id', $employee->id)->whereDate('work_date', $yesterday)->exists();
                if ($moment->lt($ends->subHours(2)) && ! $started) {
                    return ['shift_id' => $shift->id, 'work_date' => $yesterday];
                }
            }
        }
        $now = self::duty($employee, $today, $lines->get($employee->id.'|'.$today));
        $shift = $now['shift_id'] ?? $employee->shift_id;
        // With no duty set for them at all, someone arriving in the small hours is on the night that began the evening
        // before: nobody starts a day's work at two in the morning. Not if they already worked that day.
        if (! $shift && ! $before['shift_id'] && $moment->hour < AttendanceRules::NIGHT_ARRIVAL_BEFORE
            && ! Attendance::query()->where('employee_id', $employee->id)->whereDate('work_date', $yesterday)->exists()) {
            return ['shift_id' => null, 'work_date' => $yesterday];
        }

        return ['shift_id' => $shift, 'work_date' => $today];
    }

    /** The shift for a day entered by hand: the rostered one, or the usual one on a rostered day off. */
    public static function shiftOn(Employee $employee, string $date): ?int
    {
        $duty = self::duty($employee, $date, self::lines(collect([$employee->id]), $date, $date)->first());

        return $duty['shift_id'] ?? $employee->shift_id;
    }
}
