<?php

namespace App\Workforce\Support;

use App\Workforce\Models\Attendance;
use App\Workforce\Models\Employee;
use App\Workforce\Models\Holiday;
use App\Workforce\Models\HolidayCalendar;
use App\Workforce\Models\LeaveRequest;
use App\Workforce\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How a spell of work is measured against its shift, and what each day comes to for a member of staff once their
 * attendance, approved leave, holidays and weekly off are put together. Attendance and, later, payroll both read a
 * day through here, so the two can never disagree.
 */
final class AttendanceRules
{
    private const DAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /**
     * Measures one spell of work: its state, the minutes worked, and how late, how early and how far over.
     * A day belongs to the date the shift started, so a night shift's check-out the next morning is the same day.
     *
     * @return array{state: string, worked_minutes: int|null, late_minutes: int, early_minutes: int, overtime_minutes: int}
     */
    public static function measure(string $workDate, ?string $checkIn, ?string $checkOut, ?Shift $shift): array
    {
        $none = ['state' => 'open', 'worked_minutes' => null, 'late_minutes' => 0, 'early_minutes' => 0, 'overtime_minutes' => 0];
        if (! $checkIn) {
            return array_merge($none, ['state' => 'review']);
        }
        $in = CarbonImmutable::parse($checkIn);
        $late = 0;
        [$starts, $ends] = $shift ? self::window($workDate, $shift) : [null, null];
        if ($starts && $in->greaterThan($starts->addMinutes((int) $shift->grace_minutes))) {
            $late = (int) $starts->diffInMinutes($in);
        }
        if (! $checkOut) {
            return array_merge($none, ['late_minutes' => $late]);
        }
        $out = CarbonImmutable::parse($checkOut);
        if ($out->lessThan($in)) {
            return array_merge($none, ['state' => 'review']);
        }

        $gross = (int) $in->diffInMinutes($out);
        // The unpaid break is taken off only once the spell is long enough to have had one: half the shift or more.
        $break = $shift && $starts && $gross >= intdiv((int) $starts->diffInMinutes($ends), 2) ? min($gross, (int) $shift->break_minutes) : 0;
        $worked = $gross - $break;
        $early = $ends && $out->lessThan($ends->subMinutes((int) $shift->early_leave_minutes)) ? (int) $out->diffInMinutes($ends) : 0;
        $over = $shift && $shift->overtime_after_minutes !== null ? max(0, $worked - (int) $shift->overtime_after_minutes) : 0;

        return ['state' => 'completed', 'worked_minutes' => $worked, 'late_minutes' => $late, 'early_minutes' => $early, 'overtime_minutes' => $over];
    }

    /**
     * When a shift starts and ends for a given day. An end time at or before the start is the next morning.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function window(string $workDate, Shift $shift): array
    {
        $starts = CarbonImmutable::parse($workDate.' '.substr((string) $shift->starts_at, 0, 5));
        $ends = CarbonImmutable::parse($workDate.' '.substr((string) $shift->ends_at, 0, 5));

        return [$starts, $shift->crossesMidnight() ? $ends->addDay() : $ends];
    }

    /**
     * Every day in a stretch for each of these staff, put together from their attendance, approved leave, holidays
     * and weekly off. Read in a few large steps however many staff and days there are.
     *
     * Each day says what it was (`status`) and how it divides, in fractions of a day that always add up to one on a
     * working day: `present`, `leave`, `absent`.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array<string, array<string, mixed>>> employee id => date => day
     */
    public static function days(Collection $employees, string $from, string $to, ?string $today = null): array
    {
        $today ??= now()->toDateString();
        $ids = $employees->pluck('id');
        $records = Attendance::query()->whereIn('employee_id', $ids)->whereBetween('work_date', [$from, $to])->orderBy('check_in')->get()->groupBy(fn (Attendance $a) => $a->employee_id.'|'.$a->work_date->format('Y-m-d'));
        $leaves = LeaveRequest::query()->whereIn('employee_id', $ids)->where('status', 'approved')->where('from_date', '<=', $to)->where('to_date', '>=', $from)->with('type:id,code,name,is_paid')->get()->groupBy('employee_id');
        $shifts = Shift::query()->get()->keyBy('id');
        $default = HolidayCalendar::query()->where('is_default', true)->value('id');
        $holidays = Holiday::query()->whereBetween('on', [$from, $to])->where('is_optional', false)->get()->groupBy('calendar_id')->map(fn ($list) => $list->keyBy(fn (Holiday $h) => $h->on->format('Y-m-d')));
        $usualOff = Settings::get('attendance')['default_weekly_off'];
        // Whether a weekly off or holiday inside a stretch of leave is itself leave: the same choice the leave count uses.
        $leaveCountsDaysOff = (bool) Settings::get('leave')['count_days_off'];

        $out = [];
        foreach ($employees as $employee) {
            $shift = $shifts->get($employee->shift_id);
            $off = $employee->weekly_off ?? $usualOff;
            $calendar = $holidays->get($employee->holiday_calendar_id ?? $default) ?? collect();
            for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
                $date = $day->toDateString();
                $out[$employee->id][$date] = self::day(
                    $employee, $date, $today, $records->get($employee->id.'|'.$date) ?? collect(), $leaves->get($employee->id) ?? collect(),
                    $shift, in_array(self::DAYS[$day->dayOfWeek], $off, true), $calendar->get($date)?->name, $leaveCountsDaysOff,
                );
            }
        }

        return $out;
    }

    /**
     * One day for one person.
     *
     * @param  Collection<int, Attendance>  $records
     * @param  Collection<int, LeaveRequest>  $leaves
     * @return array<string, mixed>
     */
    private static function day(Employee $employee, string $date, string $today, Collection $records, Collection $leaves, ?Shift $shift, bool $weeklyOff, ?string $holiday, bool $leaveCountsDaysOff = false): array
    {
        $day = ['date' => $date, 'status' => '', 'present' => 0.0, 'leave' => 0.0, 'paid_leave' => 0.0, 'absent' => 0.0, 'worked_minutes' => 0, 'late_minutes' => 0, 'early_minutes' => 0, 'overtime_minutes' => 0, 'open' => false, 'records' => [], 'leave_type' => null, 'holiday' => $holiday, 'note' => null];

        // Before they joined or after they left, a day is not theirs at all.
        if (($employee->joined_on && $date < $employee->joined_on->format('Y-m-d')) || ($employee->separated_on && $date > $employee->separated_on->format('Y-m-d'))) {
            return ['status' => 'not_employed'] + $day;
        }

        $leave = $leaves->first(fn (LeaveRequest $l) => $l->from_date->format('Y-m-d') <= $date && $l->to_date->format('Y-m-d') >= $date);
        // A day off inside a stretch of leave stays a day off, unless the hospital counts such days as leave.
        if ($leave && ! $leaveCountsDaysOff && ($weeklyOff || $holiday)) {
            $leave = null;
        }
        if ($leave) {
            $half = ($date === $leave->from_date->format('Y-m-d') && $leave->from_part !== 'full') || ($date === $leave->to_date->format('Y-m-d') && $leave->to_part !== 'full');
            $day['leave'] = $half ? 0.5 : 1.0;
            $day['paid_leave'] = $leave->type?->is_paid ? $day['leave'] : 0.0;
            $day['leave_type'] = $leave->type?->code;
        }

        foreach ($records as $record) {
            $day['records'][] = $record->id;
            $day['worked_minutes'] += (int) $record->worked_minutes;
            $day['late_minutes'] = max($day['late_minutes'], (int) $record->late_minutes);
            $day['early_minutes'] = max($day['early_minutes'], (int) $record->early_minutes);
            $day['overtime_minutes'] += (int) $record->overtime_minutes;
            $day['open'] = $day['open'] || $record->state === 'open';
        }

        if ($records->isNotEmpty()) {
            // A person's own judgement on the day, where one was recorded, wins over the arithmetic.
            $said = $records->pluck('day_status')->filter()->last();
            $worked = match (true) {
                $said === 'present' => 1.0,
                $said === 'half_day' => 0.5,
                $said === 'absent' => 0.0,
                $day['open'] || $records->contains('state', 'review') => 1.0, // in, and not yet out: the day stands until the time is put right
                $shift?->full_day_minutes === null && $shift?->half_day_minutes === null => 1.0,
                $day['worked_minutes'] >= (int) ($shift->full_day_minutes ?? $shift->half_day_minutes) => 1.0,
                $shift->half_day_minutes !== null && $day['worked_minutes'] >= (int) $shift->half_day_minutes => 0.5,
                $shift->half_day_minutes === null => 0.5, // only a full-day mark is set, and it was not reached
                default => 0.0,
            };
            $day['present'] = min($worked, 1.0 - $day['leave']);
            $day['absent'] = ($weeklyOff || $holiday) ? 0.0 : max(0.0, 1.0 - $day['leave'] - $day['present']);
            $day['status'] = match (true) {
                $day['open'] => 'open',
                $day['present'] >= 1.0 => 'present',
                $day['present'] > 0 => $day['leave'] > 0 ? 'half_leave' : 'half_day',
                $day['leave'] >= 1.0 => 'on_leave',
                default => 'absent',
            };
            if ($day['present'] < 1.0 && ! $day['open'] && $said === null && $shift) {
                $day['note'] = 'Worked '.intdiv($day['worked_minutes'], 60).'h '.($day['worked_minutes'] % 60).'m';
            }

            return $day;
        }

        if ($day['leave'] >= 1.0) {
            return ['status' => 'on_leave'] + $day;
        }
        if ($holiday) {
            return ['status' => 'holiday'] + $day;
        }
        if ($weeklyOff) {
            return ['status' => 'weekly_off'] + $day;
        }
        if ($date > $today) {
            return ['status' => $day['leave'] > 0 ? 'half_leave' : ''] + $day;
        }
        $day['absent'] = 1.0 - $day['leave'];

        return ['status' => $date === $today ? 'not_in' : ($day['leave'] > 0 ? 'half_leave' : 'absent')] + $day;
    }

    /**
     * A stretch added up for one person, from their days.
     *
     * @param  array<string, array<string, mixed>>  $days
     * @return array<string, float|int>
     */
    public static function totals(array $days): array
    {
        $sum = fn (string $key) => array_sum(array_column($days, $key));
        $count = fn (string $status) => count(array_filter($days, fn ($d) => $d['status'] === $status));

        return [
            'present' => (float) $sum('present'), 'leave' => (float) $sum('leave'), 'paid_leave' => (float) $sum('paid_leave'),
            'absent' => (float) array_sum(array_map(fn ($d) => in_array($d['status'], ['not_in', ''], true) ? 0 : $d['absent'], $days)),
            'holidays' => $count('holiday'), 'weekly_offs' => $count('weekly_off'),
            'late_days' => count(array_filter($days, fn ($d) => $d['late_minutes'] > 0)),
            'early_days' => count(array_filter($days, fn ($d) => $d['early_minutes'] > 0)),
            'open' => count(array_filter($days, fn ($d) => $d['open'])),
            'worked_minutes' => (int) $sum('worked_minutes'), 'overtime_minutes' => (int) $sum('overtime_minutes'),
        ];
    }
}
