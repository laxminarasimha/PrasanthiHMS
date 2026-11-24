<?php

namespace App\Workforce\Support;

use App\Workforce\Models\Attendance;
use App\Workforce\Models\AttendanceCorrection;
use App\Workforce\Models\Holiday;
use App\Workforce\Models\HolidayCalendar;
use App\Workforce\Models\LeaveRequest;
use App\Workforce\Models\LeaveType;
use App\Workforce\Models\PayrollRun;
use App\Workforce\Models\Payslip;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * What must be right before a payroll goes any further. A payroll is only as good as the attendance, leave and setup
 * it is worked out from, and a mistake in those takes pay off people who did nothing wrong.
 *
 * Each point is either a "stop", which the payroll cannot pass (reviewed, approved or finalised) until it is put
 * right, or a "look", which whoever finalises must say they have looked at. Nothing here changes a figure: it only
 * says what the figures rest on.
 */
final class PayrollReadiness
{
    public const STOP = 'stop';

    public const LOOK = 'look';

    /**
     * @param  bool  $seesPay  whether the reader may see what each person is paid; without it, points that would give that away name nobody
     * @return list<array{key: string, level: string, title: string, detail: string, names: list<string>, where: ?string}>
     */
    public static function of(PayrollRun $run, bool $seesPay = true): array
    {
        $slips = $run->payslips()->with('employee:id,name')->get();
        if ($slips->isEmpty()) {
            return [];
        }
        $ids = $slips->pluck('employee_id');
        $name = fn (Payslip $s) => $s->employee?->name ?? 'A member of staff';
        $from = $run->period_start->format('Y-m-d');
        $to = $run->period_end->format('Y-m-d');
        $month = Period::name($run->period);
        $deduct = (bool) (Settings::get('payroll')['deduct_absence'] ?? true);
        $absent = fn (Payslip $s) => (float) ($s->days['absent'] ?? 0);
        $points = [];
        $add = function (string $key, string $level, string $title, string $detail, iterable $names = [], ?string $where = null) use (&$points) {
            $points[] = ['key' => $key, 'level' => $level, 'title' => $title, 'detail' => $detail, 'names' => collect($names)->filter()->unique()->sort()->values()->all(), 'where' => $where];
        };

        // 1. Pay is taken off for absence, but nobody's attendance was kept at all: every day of the month would count as absent.
        $marked = Attendance::query()->whereBetween('work_date', [$from, $to])->distinct()->pluck('employee_id');
        $losing = $slips->filter(fn (Payslip $s) => $absent($s) > 0);
        if ($deduct && $marked->isEmpty() && $losing->isNotEmpty()) {
            $add('attendance_not_kept', self::STOP, "No attendance was kept for {$month}",
                'Pay is taken off for absence, and with no attendance at all every working day counts as absent: everyone would lose their pay. Bring in the month’s attendance, or switch off “Take pay off for absence” under Workforce → Setup, then work this payroll out again.',
                [], '/workforce/setup');
        } elseif ($deduct) {
            // 2. Attendance was kept for others, but these people have none and no leave: they lose the whole month.
            $none = $losing->filter(fn (Payslip $s) => ! $marked->contains($s->employee_id) && (float) ($s->days['paid_leave'] ?? 0) + (float) ($s->days['unpaid_leave'] ?? 0) + (float) ($s->days['present'] ?? 0) === 0.0);
            if ($none->isNotEmpty()) {
                $add('no_attendance_for_some', self::LOOK, 'No attendance at all for '.self::count($none->count(), 'person', 'people'),
                    "Nothing was marked for them in {$month} and they took no leave, so every working day counts as absent. If they did work, enter their attendance and work this payroll out again.",
                    $none->map($name), '/workforce/attendance?view=month');
            }
        }

        // 3 and 4. Requests still waiting change the days once they are decided.
        $leave = LeaveRequest::query()->whereIn('employee_id', $ids)->where('status', 'pending')->whereDate('from_date', '<=', $to)->whereDate('to_date', '>=', $from)->pluck('employee_id');
        if ($leave->isNotEmpty()) {
            $add('leave_waiting', self::STOP, self::count($leave->count(), 'leave request is', 'leave requests are').' still waiting for a decision',
                "Leave asked for in {$month} counts as absence until it is approved. Approve or refuse each one, then work this payroll out again.",
                $slips->whereIn('employee_id', $leave)->map($name), '/workforce/leave');
        }
        $corrections = AttendanceCorrection::query()->whereIn('employee_id', $ids)->where('status', 'pending')->whereBetween('work_date', [$from, $to])->pluck('employee_id');
        if ($corrections->isNotEmpty()) {
            $add('corrections_waiting', self::STOP, self::count($corrections->count(), 'attendance correction is', 'attendance corrections are').' still waiting for a decision',
                "A correction asked for in {$month} changes the day it is about once it is approved. Decide each one, then work this payroll out again.",
                $slips->whereIn('employee_id', $corrections)->map($name), '/workforce/attendance?view=corrections');
        }

        // 5. A check-in with no check-out is paid as a worked day.
        $open = Attendance::query()->whereIn('employee_id', $ids)->where('state', 'open')->whereBetween('work_date', [$from, $to])->pluck('employee_id');
        if ($open->isNotEmpty()) {
            $add('no_check_out', self::LOOK, self::count($open->count(), 'day has', 'days have').' a check-in with no check-out',
                'Each is counted as a worked day. Put in the check-out if the hours matter.', $slips->whereIn('employee_id', $open)->map($name), '/workforce/attendance?view=month');
        }

        if ($deduct) {
            // 6. No holiday is set up for the year: a day the hospital was closed would count as absence.
            $year = $run->period_start->format('Y');
            $calendars = HolidayCalendar::query()->pluck('id');
            if (! Holiday::query()->whereIn('calendar_id', $calendars)->whereBetween('on', ["{$year}-01-01", "{$year}-12-31"])->exists()) {
                $add('no_holidays', self::LOOK, "No holidays are set up for {$year}",
                    "If the hospital gave a holiday in {$month}, add it under Workforce → Setup and work this payroll out again. Otherwise that day counts as absence for anyone who was not marked in.", [], '/workforce/setup');
            }
            // 7. No kind of leave exists: a day off cannot have been recorded as leave.
            if (! LeaveType::query()->where('is_active', true)->exists()) {
                $add('no_leave_types', self::LOOK, 'No kinds of leave are set up',
                    'Nobody could have been given leave, so every day off counts as absence. Set up the kinds of leave under Workforce → Setup if the hospital gives any.', [], '/workforce/setup');
            }
        }

        // 8. Staff who are not in this payroll because no salary stands for them.
        $unpaid = collect($run->rules['left_out'] ?? [])->filter(fn ($l) => str_contains((string) ($l['reason'] ?? ''), 'salary'));
        if ($unpaid->isNotEmpty()) {
            $add('no_salary', self::LOOK, self::count($unpaid->count(), 'member of staff is', 'staff are').' not in this payroll',
                'No salary has been set for them, or it still waits for approval, so they get no payslip this month.', $unpaid->pluck('name'), '/workforce/pay');
        }

        // 9. Anyone paid for less than half the days they were employed in the month.
        $half = $slips->filter(fn (Payslip $s) => (float) ($s->days['employed'] ?? 0) > 0 && (float) ($s->days['unpaid'] ?? 0) > (float) $s->days['employed'] / 2);
        if ($half->isNotEmpty()) {
            $add('half_unpaid', self::LOOK, self::count($half->count(), 'person is', 'people are').' paid for less than half the month',
                'More than half their days are unpaid: absence, or leave without pay. Open each payslip and see that this is right.', $seesPay ? $half->map($name) : []);
        }

        return $points;
    }

    /** Stops here, in words for the screen, while any point is one the payroll cannot pass. */
    public static function refuse(PayrollRun $run): void
    {
        $stops = collect(self::of($run))->where('level', self::STOP);
        if ($stops->isNotEmpty()) {
            throw ValidationException::withMessages(['status' => 'This payroll cannot go further yet: '.$stops->pluck('title')->implode('; ').'. See “Before this payroll goes further”.']);
        }
    }

    /**
     * The points that whoever finalises must say they have looked at.
     *
     * @return list<string> their keys
     */
    public static function toLookAt(PayrollRun $run): array
    {
        return collect(self::of($run))->where('level', self::LOOK)->pluck('key')->values()->all();
    }

    private static function count(int $n, string $one, string $many): string
    {
        return $n.' '.($n === 1 ? $one : $many);
    }
}
