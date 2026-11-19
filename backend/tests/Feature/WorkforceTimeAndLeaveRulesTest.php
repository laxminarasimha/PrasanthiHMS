<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Workforce\Models\Attendance;
use App\Workforce\Models\Employee;
use App\Workforce\Models\Holiday;
use App\Workforce\Models\HolidayCalendar;
use App\Workforce\Models\LeaveLedgerLine;
use App\Workforce\Models\LeaveRequest;
use App\Workforce\Models\LeaveType;
use App\Workforce\Models\Shift;
use App\Workforce\Support\AttendanceRules;
use App\Workforce\Support\LeaveBook;
use App\Workforce\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The arithmetic of working time and leave, with made-up staff: shifts that run past midnight, a check-out that
 * was never made, weekly offs, holidays, half days, and leave that is earned, carried and lapses.
 */
class WorkforceTimeAndLeaveRulesTest extends TestCase
{
    use RefreshDatabase;

    private Shift $day;

    private Shift $night;

    protected function setUp(): void
    {
        parent::setUp();
        $branch = Branch::create(['name' => 'Sample Hospital']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'branch_id' => $branch->id]));
        // 9 to 5:30 with ten minutes' grace, half an hour's break, half day from 4 hours, full day from 7, overtime past 8.
        $this->day = Shift::create(['name' => 'Day', 'starts_at' => '09:00', 'ends_at' => '17:30', 'grace_minutes' => 10, 'early_leave_minutes' => 10, 'break_minutes' => 30, 'half_day_minutes' => 240, 'full_day_minutes' => 420, 'overtime_after_minutes' => 480]);
        $this->night = Shift::create(['name' => 'Night', 'starts_at' => '20:00', 'ends_at' => '08:00', 'grace_minutes' => 15, 'break_minutes' => 60, 'half_day_minutes' => 300, 'full_day_minutes' => 600]);
    }

    private function employee(string $name, array $more = []): Employee
    {
        return Employee::create($more + ['employee_no' => 'E'.random_int(10000, 99999), 'name' => $name, 'shift_id' => $this->day->id, 'weekly_off' => ['sun'], 'joined_on' => '2025-01-01']);
    }

    private function worked(Employee $employee, string $date, string $in, ?string $out, array $more = []): Attendance
    {
        $record = new Attendance($more + ['employee_id' => $employee->id, 'work_date' => $date, 'shift_id' => $employee->shift_id, 'check_in' => $in, 'check_out' => $out]);
        $record->measured()->save();

        return $record;
    }

    public function test_a_day_shift_is_measured_for_lateness_early_leaving_break_and_overtime(): void
    {
        $m = fn (string $in, ?string $out) => AttendanceRules::measure('2026-03-02', "2026-03-02 {$in}", $out ? "2026-03-02 {$out}" : null, $this->day);

        // On time and a full day: 8h30 at work less the half-hour break.
        $this->assertSame(['state' => 'completed', 'worked_minutes' => 480, 'late_minutes' => 0, 'early_minutes' => 0, 'overtime_minutes' => 0], $m('09:00', '17:30'));
        // Within the grace nobody is late; past it, lateness is counted from the shift's start.
        $this->assertSame(0, $m('09:10', '17:30')['late_minutes']);
        $this->assertSame(11, $m('09:11', '17:30')['late_minutes']);
        // Leaving 45 minutes early; and staying on is overtime beyond eight worked hours.
        $this->assertSame(45, $m('09:00', '16:45')['early_minutes']);
        $this->assertSame(['worked_minutes' => 570, 'overtime_minutes' => 90], array_intersect_key($m('09:00', '19:00'), ['worked_minutes' => 0, 'overtime_minutes' => 0]));
        // A short spell had no break to take off.
        $this->assertSame(120, $m('09:00', '11:00')['worked_minutes']);
        // No check-out yet: open, with the lateness already known. Out before in: for a person to look at.
        $this->assertSame(['state' => 'open', 'worked_minutes' => null, 'late_minutes' => 30], array_intersect_key($m('09:30', null), ['state' => 0, 'worked_minutes' => 0, 'late_minutes' => 0]));
        $this->assertSame('review', $m('09:00', '08:00')['state']);
        // With no shift set, the hours are simply the time between.
        $this->assertSame(['state' => 'completed', 'worked_minutes' => 300, 'late_minutes' => 0, 'early_minutes' => 0, 'overtime_minutes' => 0], AttendanceRules::measure('2026-03-02', '2026-03-02 10:00:00', '2026-03-02 15:00:00', null));
    }

    public function test_a_night_shift_belongs_to_the_day_it_started_and_a_missing_check_out_stays_open(): void
    {
        $this->assertTrue($this->night->crossesMidnight());
        // In at 8 pm on the 2nd, out at 8:05 am on the 3rd: twelve hours less the break, all on the 2nd.
        $whole = AttendanceRules::measure('2026-03-02', '2026-03-02 20:00:00', '2026-03-03 08:05:00', $this->night);
        $this->assertSame(['state' => 'completed', 'worked_minutes' => 665, 'late_minutes' => 0, 'early_minutes' => 0, 'overtime_minutes' => 0], $whole);
        // Left at 6 am: two hours early, measured against the next morning's end, not the same evening.
        $this->assertSame(120, AttendanceRules::measure('2026-03-02', '2026-03-02 20:00:00', '2026-03-03 06:00:00', $this->night)['early_minutes']);

        $nurse = $this->employee('Night Nurse', ['shift_id' => $this->night->id]);
        $this->worked($nurse, '2026-03-02', '2026-03-02 20:00:00', '2026-03-03 08:05:00');
        $this->worked($nurse, '2026-03-03', '2026-03-03 20:20:00', null);
        $days = AttendanceRules::days(collect([$nurse]), '2026-03-02', '2026-03-04', '2026-03-10')[$nurse->id];

        $this->assertSame(['present', 1.0, 665], [$days['2026-03-02']['status'], $days['2026-03-02']['present'], $days['2026-03-02']['worked_minutes']]);
        // The check-out that was never made: the day still stands, flagged, until someone puts the time right.
        $this->assertSame(['open', true, 20], [$days['2026-03-03']['status'], $days['2026-03-03']['open'], $days['2026-03-03']['late_minutes']]);
        $this->assertSame('absent', $days['2026-03-04']['status']);
        $this->assertSame(1, AttendanceRules::totals($days)['open']);
    }

    public function test_a_month_puts_together_work_leave_holidays_weekly_offs_and_half_days(): void
    {
        $calendar = HolidayCalendar::create(['name' => 'Hospital holidays', 'is_default' => true]);
        Holiday::create(['calendar_id' => $calendar->id, 'on' => '2026-03-04', 'name' => 'Sample festival']);
        Holiday::create(['calendar_id' => $calendar->id, 'on' => '2026-03-05', 'name' => 'Optional day', 'is_optional' => true]);
        $casual = LeaveType::create(['code' => 'CL', 'name' => 'Casual leave', 'annual_allowance' => 12]);
        $unpaid = LeaveType::create(['code' => 'LOP', 'name' => 'Leave without pay', 'is_paid' => false, 'accrual' => 'none']);
        $asha = $this->employee('Asha Sample');

        $this->worked($asha, '2026-03-02', '2026-03-02 09:00:00', '2026-03-02 17:30:00'); // Monday: full day
        $this->worked($asha, '2026-03-03', '2026-03-03 09:00:00', '2026-03-03 13:30:00'); // Tuesday: 4h30, a half day
        // Wednesday the 4th is a holiday. Thursday the 5th is only an optional one, and she did not come.
        $this->worked($asha, '2026-03-06', '2026-03-06 09:00:00', '2026-03-06 10:00:00'); // Friday: an hour, below a half day
        LeaveRequest::create(['employee_id' => $asha->id, 'leave_type_id' => $casual->id, 'from_date' => '2026-03-07', 'to_date' => '2026-03-07', 'days' => 1, 'status' => 'approved']); // Saturday
        // Sunday the 8th is her weekly off.
        LeaveRequest::create(['employee_id' => $asha->id, 'leave_type_id' => $casual->id, 'from_date' => '2026-03-09', 'to_date' => '2026-03-09', 'from_part' => 'first_half', 'to_part' => 'first_half', 'days' => 0.5, 'status' => 'approved']);
        $this->worked($asha, '2026-03-09', '2026-03-09 13:30:00', '2026-03-09 17:30:00'); // Monday: half leave, then worked the afternoon
        LeaveRequest::create(['employee_id' => $asha->id, 'leave_type_id' => $unpaid->id, 'from_date' => '2026-03-10', 'to_date' => '2026-03-10', 'days' => 1, 'status' => 'approved']);
        LeaveRequest::create(['employee_id' => $asha->id, 'leave_type_id' => $casual->id, 'from_date' => '2026-03-11', 'to_date' => '2026-03-11', 'days' => 1, 'status' => 'pending']); // not approved: does not count

        $days = AttendanceRules::days(collect([$asha]), '2026-03-02', '2026-03-12', '2026-03-11')[$asha->id];
        $this->assertSame(
            ['present', 'half_day', 'holiday', 'absent', 'absent', 'on_leave', 'weekly_off', 'half_leave', 'on_leave', 'not_in', ''],
            array_column($days, 'status'),
        );
        $this->assertSame('Sample festival', $days['2026-03-04']['holiday']);
        $this->assertSame([0.5, 0.5, 0.0], [$days['2026-03-09']['present'], $days['2026-03-09']['leave'], $days['2026-03-09']['absent']]);
        $this->assertSame('Worked 1h 0m', $days['2026-03-06']['note']);
        // Every working day splits into parts that add up to one day.
        foreach (['2026-03-02', '2026-03-03', '2026-03-05', '2026-03-06', '2026-03-07', '2026-03-09', '2026-03-10'] as $date) {
            $this->assertSame(1.0, $days[$date]['present'] + $days[$date]['leave'] + $days[$date]['absent'], $date);
        }

        $totals = AttendanceRules::totals($days);
        $this->assertSame([2.0, 2.5, 1.5, 2.5, 1, 1], [$totals['present'], $totals['leave'], $totals['paid_leave'], $totals['absent'], $totals['holidays'], $totals['weekly_offs']]);

        // A day someone judged by hand keeps that judgement: an hour's emergency call-in counted as present.
        Attendance::where('work_date', '2026-03-06')->first()->update(['day_status' => 'present']);
        $this->assertSame('present', AttendanceRules::days(collect([$asha]), '2026-03-06', '2026-03-06', '2026-03-11')[$asha->id]['2026-03-06']['status']);
        // Before she joined, a day is not hers at all: neither present nor absent.
        $this->assertSame(['not_employed', 0.0], array_values(array_intersect_key(AttendanceRules::days(collect([$asha]), '2024-12-31', '2024-12-31')[$asha->id]['2024-12-31'], ['status' => 0, 'absent' => 0])));
    }

    public function test_leave_is_earned_by_the_year_or_the_month_and_never_twice(): void
    {
        $casual = LeaveType::create(['code' => 'CL', 'name' => 'Casual leave', 'annual_allowance' => 12, 'accrual' => 'yearly']);
        $earned = LeaveType::create(['code' => 'EL', 'name' => 'Earned leave', 'annual_allowance' => 18, 'accrual' => 'monthly']);
        LeaveType::create(['code' => 'LOP', 'name' => 'Leave without pay', 'is_paid' => false, 'accrual' => 'none']);
        $old = $this->employee('Long Service');
        $new = $this->employee('Joined In July', ['joined_on' => '2026-07-15']);
        $gone = $this->employee('Left Already', ['status' => 'inactive']);

        $this->assertSame(2 + 3 + 0, LeaveBook::accrue(2026, 3)); // yearly lines for two people, three monthly lines for the one who was there
        $this->assertSame(12.0, LeaveBook::balance($old->id, $casual->id, 2026));
        $this->assertSame(4.5, LeaveBook::balance($old->id, $earned->id, 2026));
        // Joined on 15 July: August to December, five twelfths of twelve days.
        $this->assertSame(5.0, LeaveBook::balance($new->id, $casual->id, 2026));
        $this->assertSame(0.0, LeaveBook::balance($new->id, $earned->id, 2026));
        $this->assertSame(0, LeaveLedgerLine::where('employee_id', $gone->id)->count());

        // Run again for the same months: nothing more. Run to the end of the year: only what is new.
        $this->assertSame(0, LeaveBook::accrue(2026, 3));
        $this->assertSame(9 + 5, LeaveBook::accrue(2026));
        $this->assertSame(18.0, LeaveBook::balance($old->id, $earned->id, 2026));
        $this->assertSame(7.5, LeaveBook::balance($new->id, $earned->id, 2026));
        $this->assertSame(0, LeaveBook::accrue(2026));
    }

    public function test_leave_is_taken_only_from_what_is_there_and_comes_back_when_cancelled(): void
    {
        $casual = LeaveType::create(['code' => 'CL', 'name' => 'Casual leave', 'annual_allowance' => 12]);
        $asha = $this->employee('Asha Sample');
        LeaveBook::accrue(2026);

        // Friday to Tuesday, starting after lunch: Fri half, Sat, (Sunday off), Mon, Tue = 3.5 days.
        $this->assertSame(3.5, LeaveBook::daysBetween($asha, '2026-03-06', '2026-03-10', 'second_half'));
        Settings::put('leave', ['count_days_off' => true]);
        $this->assertSame(4.5, LeaveBook::daysBetween($asha, '2026-03-06', '2026-03-10', 'second_half'));
        Settings::put('leave', ['count_days_off' => false]);

        $request = LeaveRequest::create(['employee_id' => $asha->id, 'leave_type_id' => $casual->id, 'from_date' => '2026-03-06', 'to_date' => '2026-03-10', 'from_part' => 'second_half', 'days' => 3.5]);
        LeaveBook::approve($request);
        $this->assertSame(8.5, LeaveBook::balance($asha->id, $casual->id, 2026));
        // Approving the same request twice is refused, and takes nothing more.
        try {
            LeaveBook::approve($request);
            $this->fail('A request was approved twice.');
        } catch (ValidationException) {
            $this->assertSame(8.5, LeaveBook::balance($asha->id, $casual->id, 2026));
        }

        // More than is left is refused, with the number that is left.
        $tooMuch = LeaveRequest::create(['employee_id' => $asha->id, 'leave_type_id' => $casual->id, 'from_date' => '2026-05-04', 'to_date' => '2026-05-15', 'days' => 10]);
        try {
            LeaveBook::approve($tooMuch);
            $this->fail('Leave was approved beyond the balance.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Only 8.5 days', $e->getMessage());
        }

        // Cancelling approved leave puts the days back as a line of their own; the "taken" line is not removed.
        LeaveBook::cancel($request->fresh(), 'Plans changed');
        $this->assertSame(12.0, LeaveBook::balance($asha->id, $casual->id, 2026));
        $this->assertSame(['accrual', 'taken', 'reversal'], LeaveLedgerLine::where('employee_id', $asha->id)->orderBy('id')->pluck('kind')->all());
        $this->assertSame('cancelled', $request->fresh()->status);
    }

    public function test_at_the_years_end_leave_is_carried_up_to_the_limit_and_the_rest_lapses(): void
    {
        $earned = LeaveType::create(['code' => 'EL', 'name' => 'Earned leave', 'annual_allowance' => 18, 'accrual' => 'yearly', 'carry_forward_max' => 6, 'carry_forward_expires_months' => 3]);
        $casual = LeaveType::create(['code' => 'CL', 'name' => 'Casual leave', 'annual_allowance' => 12, 'carry_forward_max' => 0]);
        $asha = $this->employee('Asha Sample');
        $bhanu = $this->employee('Bhanu Sample');
        LeaveBook::accrue(2026);
        LeaveBook::line($asha->id, $earned->id, 2026, 'taken', -8, '2026-06-01', 'taken:test-1');   // Asha has 10 left
        LeaveBook::line($bhanu->id, $earned->id, 2026, 'taken', -15, '2026-06-01', 'taken:test-2'); // Bhanu has 3 left

        $this->assertGreaterThan(0, LeaveBook::closeYear(2026));
        // The old year ends at nothing for everyone; what moved and what lapsed are both written down.
        foreach ([$asha, $bhanu] as $person) {
            $this->assertSame(0.0, LeaveBook::balance($person->id, $earned->id, 2026));
            $this->assertSame(0.0, LeaveBook::balance($person->id, $casual->id, 2026));
        }
        $this->assertSame(6.0, LeaveBook::balance($asha->id, $earned->id, 2027)); // 10 left: 6 carried, 4 lapsed
        $this->assertSame(3.0, LeaveBook::balance($bhanu->id, $earned->id, 2027)); // 3 left: all carried
        $this->assertSame(0.0, LeaveBook::balance($asha->id, $casual->id, 2027)); // casual leave is not carried at all
        $this->assertSame(-4.0, (float) LeaveLedgerLine::where(['employee_id' => $asha->id, 'leave_type_id' => $earned->id, 'leave_year' => 2026, 'kind' => 'expiry'])->value('days'));
        $this->assertSame(0, LeaveBook::closeYear(2026));

        // Carried leave must be used within three months. Asha uses two of her six by March; Bhanu none.
        LeaveBook::line($asha->id, $earned->id, 2027, 'taken', -2, '2027-02-10', 'taken:test-3');
        $this->assertSame(0, LeaveBook::expireCarried(2027, '2027-03-31')); // not yet: the three months end that day
        $this->assertSame(2, LeaveBook::expireCarried(2027, '2027-04-01'));
        $this->assertSame(0.0, LeaveBook::balance($asha->id, $earned->id, 2027)); // 6 carried − 2 used − 4 lapsed
        $this->assertSame(0.0, LeaveBook::balance($bhanu->id, $earned->id, 2027));
        $this->assertSame(0, LeaveBook::expireCarried(2027, '2027-06-01'));
    }

    public function test_the_leave_year_can_start_in_april(): void
    {
        Settings::put('leave', ['year_starts_month' => 4]);
        $this->assertSame([2025, 2026], [LeaveBook::yearOf('2026-03-31'), LeaveBook::yearOf('2026-04-01')]);
        [$start, $end] = LeaveBook::span(2026);
        $this->assertSame(['2026-04-01', '2027-03-31'], [$start->toDateString(), $end->toDateString()]);
    }
}
