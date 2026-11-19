<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Workforce\Models\Advance;
use App\Workforce\Models\Attendance;
use App\Workforce\Models\Employee;
use App\Workforce\Models\LeaveRequest;
use App\Workforce\Models\LeaveType;
use App\Workforce\Models\PayrollRun;
use App\Workforce\Models\Payslip;
use App\Workforce\Models\SalaryComponent;
use App\Workforce\Models\SalaryStructure;
use App\Workforce\Support\AdvanceBook;
use App\Workforce\Support\Money;
use App\Workforce\Support\Payroll;
use App\Workforce\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

/**
 * The payroll arithmetic and the life of a run, worked through with made-up staff and made-up figures. The rates
 * and slabs here are arbitrary numbers chosen to exercise the rules; none is a real statutory rate.
 */
class WorkforcePayrollRulesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** @var array<string, SalaryComponent> */
    private array $parts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-05 10:00:00'); // April 2026 (30 days, Sundays on the 5th, 12th, 19th and 26th) is over
        $branch = Branch::create(['name' => 'Sample Hospital']);
        $this->admin = User::factory()->create(['role' => 'admin', 'branch_id' => $branch->id]);
        Sanctum::actingAs($this->admin);
        $this->part('BASIC', 'Basic pay', 'earning', 'fixed');
        $this->part('HRA', 'House rent allowance', 'earning', 'percent_of_basic');
        $this->part('ALLOW', 'Conveyance', 'earning', 'fixed', ['reduces_with_absence' => false]);
    }

    private function part(string $code, string $name, string $kind, string $basis, array $more = []): SalaryComponent
    {
        return $this->parts[$code] = SalaryComponent::create($more + ['code' => $code, 'name' => $name, 'kind' => $kind, 'basis' => $basis, 'reduces_with_absence' => $kind === 'earning', 'sort' => count($this->parts) * 10]);
    }

    private function employee(string $name, array $more = []): Employee
    {
        return Employee::create($more + ['employee_no' => 'E'.random_int(10000, 99999), 'name' => $name, 'joined_on' => '2025-01-01', 'weekly_off' => ['sun']]);
    }

    /** @param array<string, array<string, mixed>> $lines code => amount or percent */
    private function salary(Employee $employee, array $lines, array $more = []): SalaryStructure
    {
        $structure = SalaryStructure::create($more + ['employee_id' => $employee->id, 'effective_from' => '2026-01-01', 'pay_basis' => 'monthly', 'created_by' => $this->admin->id, 'approved_by' => $this->admin->id, 'approved_at' => now()]);
        foreach ($lines as $code => $line) {
            $structure->lines()->create($line + ['component_id' => $this->parts[$code]->id]);
        }

        return $structure;
    }

    /** Marks every working day of a month as worked, except the days named. */
    private function worked(Employee $employee, string $period, array $except = [], array $overtime = []): void
    {
        $from = $employee->joined_on->format('Y-m-d');
        for ($day = CarbonImmutable::parse($period.'-01'); $day->format('Y-m') === $period; $day = $day->addDay()) {
            $date = $day->toDateString();
            if ($day->isSunday() || in_array($date, $except, true) || $date < $from) {
                continue;
            }
            Attendance::create(['employee_id' => $employee->id, 'work_date' => $date, 'check_in' => $date.' 09:00:00', 'check_out' => $date.' 17:00:00', 'state' => 'completed', 'day_status' => 'present', 'worked_minutes' => 480, 'overtime_minutes' => $overtime[$date] ?? 0, 'origin' => 'manual']);
        }
    }

    private function usual(Employee $employee): SalaryStructure
    {
        return $this->salary($employee, ['BASIC' => ['amount' => 20000], 'HRA' => ['percent' => 40], 'ALLOW' => ['amount' => 2000]]);
    }

    private function slip(PayrollRun $run, Employee $employee): Payslip
    {
        return $run->payslips()->where('employee_id', $employee->id)->with('lines')->firstOrFail();
    }

    private function line(Payslip $slip, string $code): ?object
    {
        return $slip->lines->firstWhere('code', $code);
    }

    public function test_a_full_month_pays_the_salary_as_set_and_says_how_each_line_was_arrived_at(): void
    {
        $asha = $this->employee('Asha Sample');
        $this->usual($asha);
        $this->worked($asha, '2026-04');

        $run = Payroll::start('2026-04', null, null, $this->admin);
        $slip = $this->slip($run, $asha);

        $this->assertSame(['20000.00', '8000.00', '2000.00'], $slip->lines->pluck('amount')->all());
        $this->assertSame(['30000.00', '0.00', '30000.00'], [$slip->gross, $slip->deductions, $slip->net]);
        $this->assertSame('₹20,000.00 a month', $this->line($slip, 'BASIC')->explanation);
        $this->assertSame('40% of basic pay ₹20,000.00', $this->line($slip, 'HRA')->explanation);
        $this->assertSame([30, 30, 26.0, 0.0, []], [$slip->days['in_month'], $slip->days['employed'], (float) $slip->days['present'], (float) $slip->days['unpaid'], $slip->days['notes']]);
        $this->assertSame(['draft', '30000.00', '30000.00'], [$run->status, $run->gross, $run->net]);
    }

    public function test_unpaid_days_take_a_days_pay_each_and_net_pay_is_rounded_as_the_hospital_chooses(): void
    {
        $asha = $this->employee('Asha Sample');
        $this->usual($asha);
        $this->worked($asha, '2026-04', ['2026-04-07']); // absent one Tuesday

        // One absent day of the pay that shrinks with absence (28,000; the conveyance does not): 28,000 ÷ 30 = 933.33.
        $slip = $this->slip(Payroll::start('2026-04', null, null, $this->admin), $asha);
        $this->assertSame('933.33', $this->line($slip, 'UNPAID')->amount);
        $this->assertSame('1 unpaid day (1 absent) at ₹933.33 a day (₹28,000.00 ÷ 30)', $this->line($slip, 'UNPAID')->explanation);
        // 30,000 − 933.33 = 29,066.67, rounded to 29,067 with the 33 paise shown as its own line.
        $this->assertSame(['0.33', 'earning'], [$this->line($slip, 'ROUND')->amount, $this->line($slip, 'ROUND')->kind]);
        $this->assertSame(['30000.33', '933.33', '29067.00'], [$slip->gross, $slip->deductions, $slip->net]);

        // Left in paise, and with a day's pay as a 26th of the month, on the hospital's say-so.
        Settings::put('payroll', ['round_net_to' => 'paise', 'month_days' => 'fixed_26']);
        $slip = $this->slip(Payroll::workOut($slip->run), $asha);
        $this->assertSame('1076.92', $this->line($slip, 'UNPAID')->amount);
        $this->assertNull($this->line($slip, 'ROUND'));
        $this->assertSame('28923.08', $slip->net);

        // Leave without pay counts with absence; paid leave costs nothing.
        Settings::put('payroll', ['round_net_to' => 'rupee', 'month_days' => 'calendar']);
        $unpaid = LeaveType::create(['code' => 'LOP', 'name' => 'Leave without pay', 'is_paid' => false]);
        $paid = LeaveType::create(['code' => 'CL', 'name' => 'Casual leave', 'is_paid' => true]);
        Attendance::query()->where('employee_id', $asha->id)->whereIn('work_date', ['2026-04-09', '2026-04-10', '2026-04-14'])->delete();
        LeaveRequest::create(['employee_id' => $asha->id, 'leave_type_id' => $unpaid->id, 'from_date' => '2026-04-09', 'to_date' => '2026-04-10', 'days' => 2, 'status' => 'approved']);
        LeaveRequest::create(['employee_id' => $asha->id, 'leave_type_id' => $paid->id, 'from_date' => '2026-04-14', 'to_date' => '2026-04-14', 'days' => 1, 'status' => 'approved']);
        $slip = $this->slip(Payroll::workOut($slip->run), $asha);
        $this->assertSame('2800.00', $this->line($slip, 'UNPAID')->amount);
        $this->assertStringContainsString('3 unpaid days (1 absent, 2 leave without pay)', $this->line($slip, 'UNPAID')->explanation);
        $this->assertSame('27200.00', $slip->net);

        // A hospital that does not keep attendance here takes pay off for leave without pay only.
        Settings::put('payroll', ['deduct_absence' => false]);
        $slip = $this->slip(Payroll::workOut($slip->run), $asha);
        $this->assertSame('1866.67', $this->line($slip, 'UNPAID')->amount);
    }

    public function test_overtime_daily_pay_and_a_month_joined_part_way_through(): void
    {
        // Overtime at the person's own hourly rate, from the minutes attendance recorded.
        $asha = $this->employee('Asha Sample');
        $this->usual($asha)->update(['overtime_rate' => 150]);
        $this->worked($asha, '2026-04', [], ['2026-04-02' => 90, '2026-04-03' => 45]);

        // Paid by the day: 26 days worked at 800.
        $this->part('WAGE', 'Daily wage', 'earning', 'per_day', ['reduces_with_absence' => false]);
        $ravi = $this->employee('Ravi Sample');
        $this->salary($ravi, ['WAGE' => ['amount' => 800]], ['pay_basis' => 'daily']);
        $this->worked($ravi, '2026-04');

        // Joined on the 16th: paid for 15 of the 30 days, and the days before joining are not absence.
        $new = $this->employee('New Joiner', ['joined_on' => '2026-04-16']);
        $this->usual($new);
        $this->worked($new, '2026-04');

        $run = Payroll::start('2026-04', null, null, $this->admin);

        $this->assertSame('337.50', $this->line($this->slip($run, $asha), 'OT')->amount);
        $this->assertSame('2h 15m of overtime × ₹150.00 an hour', $this->line($this->slip($run, $asha), 'OT')->explanation);
        $this->assertSame('30338.00', $this->slip($run, $asha)->net); // 30,337.50 rounded up

        $this->assertSame('20800.00', $this->slip($run, $ravi)->net);
        $this->assertStringContainsString('₹800.00 a day × 26 paid days', $this->line($this->slip($run, $ravi), 'WAGE')->explanation);

        $joiner = $this->slip($run, $new);
        $this->assertSame(['10000.00', '4000.00', '1000.00'], $joiner->lines->pluck('amount')->all());
        $this->assertStringContainsString('for the 15 of 30 days employed this month', $this->line($joiner, 'BASIC')->explanation);
        $this->assertNull($this->line($joiner, 'UNPAID'));
        $this->assertSame('15000.00', $joiner->net);

        // With days off paid for daily staff, Ravi is paid for all 30.
        Settings::put('payroll', ['daily_paid_days_off' => true]);
        $this->assertSame('24000.00', $this->slip(Payroll::workOut($run), $ravi)->net);
    }

    public function test_a_statutory_part_is_worked_out_only_from_the_rule_entered_for_it(): void
    {
        $asha = $this->employee('Asha Sample');
        $fund = $this->part('FUND', 'A statutory fund', 'deduction', 'percent_of_basic', ['is_statutory' => true, 'is_enabled' => true, 'rule' => null]);
        $levy = $this->part('LEVY', 'A levy by slab', 'deduction', 'slab_of_gross', ['is_statutory' => true, 'is_enabled' => true]);
        $cover = $this->part('COVER', 'Cover for lower pay', 'deduction', 'percent_of_gross', ['is_statutory' => true, 'is_enabled' => false]);
        $share = $this->part('FUND_ER', 'The hospital’s share', 'employer', 'percent_of_basic', ['is_statutory' => true, 'is_enabled' => true, 'rule' => ['percent' => 10]]);
        $this->salary($asha, ['BASIC' => ['amount' => 20000], 'HRA' => ['percent' => 40], 'ALLOW' => ['amount' => 2000], 'FUND' => [], 'LEVY' => [], 'COVER' => [], 'FUND_ER' => []]);
        $this->worked($asha, '2026-04');

        // Switched on with no rate entered: nothing is taken, and the payslip says why. Switched off: left out.
        $run = Payroll::start('2026-04', null, null, $this->admin);
        $slip = $this->slip($run, $asha);
        $this->assertNull($this->line($slip, 'FUND'));
        $this->assertNull($this->line($slip, 'LEVY'));
        $this->assertContains('A statutory fund is switched on but has no rate entered. Nothing was worked out for it.', $slip->days['notes']);
        $this->assertContains('Cover for lower pay is switched off, so nothing was worked out for it.', $slip->days['notes']);
        $this->assertSame(['30000.00', '0.00', '30000.00'], [$slip->gross, $slip->deductions, $slip->net]);
        // The hospital's own share is not taken from the salary; it adds to what the month costs.
        $this->assertSame(['2000.00', 'employer'], [$this->line($slip, 'FUND_ER')->amount, $this->line($slip, 'FUND_ER')->kind]);
        $this->assertSame('32000.00', $slip->employer_cost);

        // The accountant enters the rules: a percentage worked on no more than a ceiling; slabs; and a part only for pay up to a limit.
        $fund->update(['rule' => ['percent' => 10, 'ceiling' => 15000]]);
        $levy->update(['rule' => ['slabs' => [['up_to' => 15000, 'amount' => 0], ['up_to' => 25000, 'amount' => 100], ['up_to' => null, 'amount' => 250]]]]);
        $cover->update(['is_enabled' => true, 'rule' => ['percent' => 1.75, 'only_if_pay_up_to' => 25000, 'round' => 'rupee_up']]);
        $slip = $this->slip(Payroll::workOut($run), $asha);
        $this->assertSame('1500.00', $this->line($slip, 'FUND')->amount);
        $this->assertSame('10% of ₹15,000.00 (the most it is worked on; basic pay earned is ₹20,000.00)', $this->line($slip, 'FUND')->explanation);
        $this->assertSame('250.00', $this->line($slip, 'LEVY')->amount);
        $this->assertNull($this->line($slip, 'COVER')); // her set pay of 30,000 is above the limit
        $this->assertSame('28250.00', $slip->net);

        // Someone under the limit pays it, rounded up to the next rupee as the rule says: 1.75% of 14,010 = 245.175 → 246.
        $ravi = $this->employee('Ravi Sample');
        $this->salary($ravi, ['BASIC' => ['amount' => 14010], 'COVER' => []]);
        $this->worked($ravi, '2026-04');
        $slip = $this->slip(Payroll::workOut($run), $ravi);
        $this->assertSame('246.00', $this->line($slip, 'COVER')->amount);
        $this->assertStringContainsString('rounded up to the next rupee', $this->line($slip, 'COVER')->explanation);
    }

    public function test_an_advance_comes_back_through_payroll_and_its_account_always_adds_up(): void
    {
        $asha = $this->employee('Asha Sample');
        $this->usual($asha);
        $this->worked($asha, '2026-04');
        $advance = Advance::create(['employee_id' => $asha->id, 'advance_no' => 'ADV-00001', 'kind' => 'advance', 'amount' => 10000, 'reason' => 'School fees', 'status' => 'approved', 'instalments' => 3, 'first_period' => '2026-04']);

        // Three parts, whole rupees, the odd rupee on the last.
        $advance = AdvanceBook::issue($advance, '2026-04-02', 'bank', 'NEFT 1');
        $this->assertSame([['2026-04', '3333.00'], ['2026-05', '3333.00'], ['2026-06', '3334.00']], $advance->schedule->map(fn ($i) => [$i->period, $i->amount])->all());
        $this->assertSame('10000.00', (string) AdvanceBook::balance($advance));

        // April's payroll takes April's part. Nothing is written to the advance's account until the payroll is finalised.
        $run = Payroll::start('2026-04', null, null, $this->admin);
        $this->assertSame('3333.00', $this->line($this->slip($run, $asha), 'ADVANCE')->amount);
        $this->assertSame('26667.00', $this->slip($run, $asha)->net);
        $this->assertSame('10000.00', (string) AdvanceBook::balance($advance));

        $run = Payroll::finalise(Payroll::step(Payroll::step($run, 'reviewed', $this->admin), 'approved', $this->admin), $this->admin);
        $this->assertSame('6667.00', (string) AdvanceBook::balance($advance));
        $this->assertSame(['deducted', 'due', 'due'], $advance->schedule()->pluck('status')->all());
        $this->assertSame('PS-202604-0001', $this->slip($run, $asha)->slip_no);

        // Reversing the payroll puts the money back as owed, in a new line; the old lines stay.
        $run = Payroll::reverse($run, 'Attendance for the 20th was wrong', $this->admin);
        $this->assertSame('10000.00', (string) AdvanceBook::balance($advance));
        $this->assertSame(['issued', 'payroll_deduction', 'reversal'], $advance->ledger()->pluck('kind')->all());
        $this->assertSame(['due', 'due', 'due'], $advance->schedule()->pluck('status')->all());

        // Paid back by hand in part: the next payroll takes only what the plan still expects by then.
        AdvanceBook::line($advance, 'repayment', -1000, '2026-05-02', 'Cash, receipt 14');
        $again = Payroll::start('2026-04', null, null, $this->admin);
        $this->assertSame($run->id, $again->reversal_of_id);
        $this->assertSame('2333.00', $this->line($this->slip($again, $asha), 'ADVANCE')->amount);
    }

    public function test_an_advance_never_takes_a_salary_below_nothing_and_the_rest_is_made_up_later(): void
    {
        $ravi = $this->employee('Ravi Sample');
        $this->salary($ravi, ['BASIC' => ['amount' => 3000]]);
        $this->worked($ravi, '2026-03');
        $this->worked($ravi, '2026-04');
        $advance = AdvanceBook::issue(Advance::create(['employee_id' => $ravi->id, 'kind' => 'loan', 'advance_no' => 'ADV-00002', 'amount' => 5000, 'status' => 'approved', 'instalments' => 1, 'first_period' => '2026-03']), '2026-03-01', 'cash', null);

        $march = Payroll::start('2026-03', null, null, $this->admin);
        $slip = $this->slip($march, $ravi);
        $this->assertSame(['3000.00', '0.00'], [$this->line($slip, 'ADVANCE')->amount, $slip->net]);
        $this->assertStringContainsString('the salary did not cover the rest', $this->line($slip, 'ADVANCE')->explanation);
        Payroll::finalise(Payroll::step(Payroll::step($march, 'reviewed', $this->admin), 'approved', $this->admin), $this->admin);
        $this->assertSame(['2000.00', 'issued'], [(string) AdvanceBook::balance($advance), $advance->fresh()->status]);

        // April has no instalment of its own, yet takes the 2,000 March could not; then the loan is closed.
        $april = Payroll::start('2026-04', null, null, $this->admin);
        $this->assertSame('2000.00', $this->line($this->slip($april, $ravi), 'ADVANCE')->amount);
        Payroll::finalise(Payroll::step(Payroll::step($april, 'reviewed', $this->admin), 'approved', $this->admin), $this->admin);
        $this->assertSame(['0.00', 'closed'], [(string) AdvanceBook::balance($advance), $advance->fresh()->status]);

        // More than is owed can never be taken back or let go.
        $this->expectException(ValidationException::class);
        AdvanceBook::line($advance, 'repayment', -1, '2026-05-01', 'Too much');
    }

    public function test_nobody_is_paid_twice_for_a_month_and_those_who_cannot_be_paid_are_named(): void
    {
        $asha = $this->employee('Asha Sample');
        $this->usual($asha);
        $this->worked($asha, '2026-04');
        $noSalary = $this->employee('No Salary Yet');
        $waiting = $this->employee('Waiting For Approval');
        $this->salary($waiting, ['BASIC' => ['amount' => 9000]], ['approved_at' => null, 'approved_by' => null]);
        $left = $this->employee('Left Last Year', ['status' => 'inactive', 'separated_on' => '2025-12-31']);

        $run = Payroll::start('2026-04', null, null, $this->admin);
        $this->assertSame([$asha->id], $run->payslips()->pluck('employee_id')->all());
        $this->assertEqualsCanonicalizing(
            [[$noSalary->id, 'No salary has been set.'], [$waiting->id, 'Their salary is waiting for approval.']],
            array_map(fn ($row) => [$row['employee_id'], $row['reason']], $run->rules['left_out']),
        );
        $this->assertNotContains($left->id, array_column($run->rules['left_out'], 'employee_id'));

        // A second payroll for the same month has nobody left to pay, and the database itself refuses a second payslip.
        try {
            Payroll::start('2026-04', null, null, $this->admin);
            $this->fail('A second payroll for the same people and month was started.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Already has a payslip for this month in another payroll.', $e->getMessage());
        }
        $this->assertSame(1, PayrollRun::count());
        $this->expectException(UniqueConstraintViolationException::class);
        Payslip::create(['run_id' => $run->id, 'employee_id' => $asha->id, 'period' => '2026-04']);
    }

    public function test_a_run_cannot_go_forward_once_the_records_under_it_have_moved_and_is_fixed_once_finalised(): void
    {
        $asha = $this->employee('Asha Sample');
        $this->usual($asha);
        $this->worked($asha, '2026-04');
        $run = Payroll::step(Payroll::start('2026-04', null, null, $this->admin), 'reviewed', $this->admin);

        // Attendance is corrected after the review: the approval is refused until the run is worked out again.
        Attendance::query()->where('employee_id', $asha->id)->where('work_date', '2026-04-20')->delete();
        try {
            Payroll::step($run, 'approved', $this->admin);
            $this->fail('A payroll went forward on figures that no longer match the attendance.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('has changed since this was worked out, for Asha Sample', $e->getMessage());
        }
        $run = Payroll::workOut(Payroll::step($run, 'draft', $this->admin));
        $this->assertSame('29067.00', $run->net);
        $run = Payroll::finalise(Payroll::step(Payroll::step($run, 'reviewed', $this->admin), 'approved', $this->admin), $this->admin);
        $this->assertSame(['finalised', ['month_days' => 'calendar']], [$run->status, array_intersect_key($run->rules['finalised_with']['payroll'], ['month_days' => 1])]);

        // Finalised: it is not worked out again, a salary cannot be slipped under it, and its lines do not move.
        try {
            Payroll::workOut($run);
            $this->fail('A finalised payroll was worked out again.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Only a draft is worked out again', $e->getMessage());
        }
        Attendance::query()->where('employee_id', $asha->id)->delete();
        $this->assertSame('29067.00', $this->slip($run->fresh(), $asha)->net);

        // Paying is recorded, never performed; when everyone is paid the run says so.
        $run = Payroll::pay($run, [$this->slip($run, $asha)->id], ['status' => 'paid', 'paid_on' => '2026-05-04', 'payment_mode' => 'bank', 'payment_reference' => 'NEFT 22'], $this->admin);
        $this->assertSame(['paid', 'paid', 'NEFT 22'], [$run->status, $this->slip($run, $asha)->payment_status, $this->slip($run, $asha)->payment_reference]);

        $this->expectException(LogicException::class);
        $this->slip($run, $asha)->lines->first()->update(['amount' => 1]);
    }

    public function test_working_a_draft_out_again_keeps_everyone_whose_payslip_numbers_differ_from_their_staff_numbers(): void
    {
        // Make the payslip ids and the staff ids differ, as they will in any real hospital.
        $first = $this->employee('Asha Sample');
        $this->usual($first);
        $this->worked($first, '2026-03');
        Payroll::start('2026-03', null, null, $this->admin);
        $others = [$this->employee('Ravi Sample'), $this->employee('Meena Sample')];
        foreach ($others as $e) {
            $this->usual($e);
            $this->worked($e, '2026-04');
        }
        $this->worked($first, '2026-04');

        $run = Payroll::start('2026-04', null, null, $this->admin);
        $this->assertSame(3, $run->payslips()->count());
        $this->assertNotEquals($run->payslips()->orderBy('id')->pluck('id')->all(), $run->payslips()->orderBy('id')->pluck('employee_id')->all());
        $this->assertSame(3, Payroll::workOut($run)->payslips()->count());
        $this->assertSame(3, Payroll::workOut($run)->payslips()->count());
    }

    public function test_money_is_exact_decimal_arithmetic_and_reads_the_indian_way(): void
    {
        $this->assertSame('0.30', Money::text(Money::of(0.1)->plus(Money::of(0.2))));
        $this->assertSame('933.33', Money::text(Money::share(28000, 1, 30)));
        $this->assertSame('1866.67', Money::text(Money::share(28000, 2, 30)));
        $this->assertSame('0.01', Money::text('0.005')); // half a paisa goes up
        $this->assertSame('₹12,34,567.50', Money::show('1234567.5'));
        // An advance is planned in whole rupees where it can be, the odd rupee on the last month; in paise only when it must be.
        $this->assertSame([['2026-01', '33.00'], ['2026-02', '33.00'], ['2026-03', '34.00']], array_map(fn ($p) => [$p['period'], $p['amount']], AdvanceBook::plan(100, 3, '2026-01')));
        $this->assertSame(['0.50', '0.50'], array_column(AdvanceBook::plan(1, 2, '2026-12'), 'amount'));
        $this->assertSame('2027-01', AdvanceBook::plan(1, 2, '2026-12')[1]['period']);
    }
}
