<?php

namespace Tests\Feature;

use App\Models\AdminEntry;
use App\Models\Branch;
use App\Models\User;
use App\Workforce\Models\Attendance;
use App\Workforce\Models\AuditEvent;
use App\Workforce\Models\Employee;
use App\Workforce\Models\Payslip;
use App\Workforce\Models\SalaryComponent;
use App\Workforce\Models\SalaryStructure;
use App\Workforce\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Salaries, payroll runs, payslips and advances as the screens use them, and who may do each step. Made-up staff and figures. */
class WorkforcePayrollApiTest extends TestCase
{
    use RefreshDatabase;

    private Branch $here;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-05-05 10:00:00');
        $this->here = Branch::create(['name' => 'Sample Hospital']);
    }

    private function user(string $role, array $more = [], ?Branch $branch = null): User
    {
        return User::factory()->create($more + ['role' => $role, 'branch_id' => ($branch ?? $this->here)->id, 'password' => bcrypt('Sample-Passw0rd')]);
    }

    private function employee(string $name, array $more = []): Employee
    {
        return Tenant::run($this->here->id, fn () => Employee::create($more + ['employee_no' => 'E'.random_int(10000, 99999), 'name' => $name, 'joined_on' => '2025-01-01', 'weekly_off' => ['sun']]));
    }

    /** A salary already approved, with a month of attendance behind it. */
    private function paid(Employee $employee, int $basic, string $period = '2026-04'): void
    {
        Tenant::run($this->here->id, function () use ($employee, $basic, $period) {
            $part = SalaryComponent::query()->firstOrCreate(['code' => 'BASIC'], ['name' => 'Basic pay', 'kind' => 'earning', 'basis' => 'fixed']);
            SalaryStructure::create(['employee_id' => $employee->id, 'effective_from' => '2026-01-01', 'pay_basis' => 'monthly', 'approved_at' => now()])->lines()->create(['component_id' => $part->id, 'amount' => $basic]);
            for ($day = CarbonImmutable::parse($period.'-01'); $day->format('Y-m') === $period; $day = $day->addDay()) {
                if (! $day->isSunday()) {
                    Attendance::create(['employee_id' => $employee->id, 'work_date' => $day->toDateString(), 'check_in' => $day->toDateString().' 09:00:00', 'check_out' => $day->toDateString().' 17:00:00', 'state' => 'completed', 'day_status' => 'present', 'worked_minutes' => 480, 'origin' => 'manual']);
                }
            }
        });
    }

    private function typePassword(): void
    {
        $this->postJson('/api/workforce/confirm-password', ['password' => 'Sample-Passw0rd'])->assertOk();
    }

    public function test_a_salary_is_entered_by_one_person_approved_by_another_and_kept_as_history(): void
    {
        $hrLogin = $this->user('hr');
        $hrSelf = $this->employee('Hema HR', ['user_id' => $hrLogin->id]);
        $nurse = $this->employee('Nurse One');

        // The list of parts starts empty. HR asks for the usual ones: the statutory ones arrive switched off and with no rate.
        Sanctum::actingAs($hrLogin);
        $this->getJson('/api/workforce/pay/components')->assertOk()->assertJsonCount(0, 'components');
        $parts = collect($this->postJson('/api/workforce/pay/components/starter')->assertOk()->json('components'))->keyBy('code');
        $this->assertSame(['BASIC', 'HRA', 'ALLOW', 'PF', 'ESI', 'PT', 'TDS', 'PF_ER', 'ESI_ER'], $parts->keys()->all());
        $this->assertSame([], $parts->where('is_statutory', true)->filter(fn ($p) => $p['is_enabled'] || $p['rule'] !== null)->all());
        $this->postJson('/api/workforce/pay/components/starter')->assertStatus(422);

        // A part's rule holds only what was typed; an earning cannot be "a percentage of pay".
        $this->putJson('/api/workforce/pay/components/'.$parts['PF']['id'], ['code' => 'PF', 'name' => 'Provident fund', 'kind' => 'deduction', 'basis' => 'percent_of_basic', 'is_statutory' => true, 'is_enabled' => true, 'rule' => ['percent' => 10, 'ceiling' => '', 'round' => null]])
            ->assertOk()->assertJsonPath('rule', ['percent' => 10]);
        $this->postJson('/api/workforce/pay/components', ['code' => 'BONUS', 'name' => 'Bonus', 'kind' => 'earning', 'basis' => 'percent_of_gross'])->assertStatus(422)->assertJsonValidationErrors('basis');
        $this->postJson('/api/workforce/pay/components', ['code' => 'HRA', 'name' => 'Again', 'kind' => 'earning', 'basis' => 'fixed'])->assertStatus(422)->assertJsonValidationErrors('code');

        // A salary starts on the first of a month, needs its figures, and waits for someone else's approval.
        $lines = [['component_id' => $parts['BASIC']['id'], 'amount' => 20000], ['component_id' => $parts['HRA']['id'], 'amount' => 8000], ['component_id' => $parts['PF']['id']]];
        $this->postJson("/api/workforce/pay/salaries/{$nurse->id}", ['effective_from' => '2026-04-15', 'pay_basis' => 'monthly', 'lines' => $lines])->assertStatus(422)->assertJsonValidationErrors('effective_from');
        $this->postJson("/api/workforce/pay/salaries/{$nurse->id}", ['effective_from' => '2026-04-01', 'pay_basis' => 'monthly', 'lines' => [['component_id' => $parts['BASIC']['id']]]])->assertStatus(422)->assertJsonValidationErrors('lines.0.amount');
        $first = $this->postJson("/api/workforce/pay/salaries/{$nurse->id}", ['effective_from' => '2026-04-01', 'pay_basis' => 'monthly', 'lines' => $lines])->assertCreated()->assertJsonPath('approved', false)->assertJsonPath('monthly', '28000.00')->json('id');
        $this->postJson("/api/workforce/pay/structures/{$first}/approve")->assertForbidden();
        // Nobody sets their own.
        $this->postJson("/api/workforce/pay/salaries/{$hrSelf->id}", ['effective_from' => '2026-04-01', 'pay_basis' => 'monthly', 'lines' => $lines])->assertForbidden();
        $this->getJson('/api/workforce/pay/salaries')->assertOk()->assertJsonPath('rows.1.current', null)->assertJsonPath('rows.1.waiting', 1);

        Sanctum::actingAs($this->user('accountant'));
        $this->postJson("/api/workforce/pay/structures/{$first}/approve")->assertOk()->assertJsonPath('approved', true);
        $this->postJson("/api/workforce/pay/structures/{$first}/approve")->assertStatus(422);
        $this->postJson("/api/workforce/pay/salaries/{$nurse->id}", ['effective_from' => '2026-06-01', 'pay_basis' => 'monthly', 'lines' => $lines])->assertForbidden(); // approves, does not set

        // A rise is a new salary from a later month; the earlier one stays. Opening someone's salary leaves a trace.
        Sanctum::actingAs($hrLogin);
        $lines[0]['amount'] = 22000;
        $this->postJson("/api/workforce/pay/salaries/{$nurse->id}", ['effective_from' => '2026-06-01', 'pay_basis' => 'monthly', 'note' => 'Annual rise', 'lines' => $lines])->assertCreated();
        $history = $this->getJson("/api/workforce/pay/salaries/{$nurse->id}")->assertOk()->json('structures');
        $this->assertSame([['2026-06-01', '30000.00', false], ['2026-04-01', '28000.00', true]], array_map(fn ($s) => [$s['effective_from'], $s['monthly'], $s['approved']], $history));
        $this->assertSame(1, AuditEvent::where('action', 'salary.viewed')->where('employee_id', $nurse->id)->count());
        $this->getJson('/api/workforce/pay/salaries')->assertJsonPath('rows.1.current.monthly', '28000.00')->assertJsonPath('rows.1.waiting', 1);

        // One still waiting can be withdrawn, with a reason. A manager, who runs the day, sees no salaries at all.
        $this->postJson("/api/workforce/pay/structures/{$history[0]['id']}/withdraw", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/workforce/pay/structures/{$history[0]['id']}/withdraw", ['reason' => 'Entered for the wrong month'])->assertOk();
        Sanctum::actingAs($this->user('manager'));
        $this->getJson('/api/workforce/pay/salaries')->assertForbidden();
        $this->getJson("/api/workforce/pay/salaries/{$nurse->id}")->assertForbidden();
        $this->getJson('/api/workforce/pay/components')->assertForbidden();
    }

    public function test_a_payroll_goes_from_draft_to_paid_through_different_hands(): void
    {
        $nursing = AdminEntry::create(['kind' => 'department', 'name' => 'Nursing']);
        $nurseLogin = $this->user('nurse');
        $asha = $this->employee('Asha Sample', ['user_id' => $nurseLogin->id, 'department_id' => $nursing->id]);
        $ravi = $this->employee('Ravi Sample');
        $this->paid($asha, 30000);
        $this->paid($ravi, 18000);
        $hr = $this->user('hr');
        $accountant = $this->user('accountant');
        $admin = $this->user('admin');

        // HR prepares it. Next month has not begun. A hand-typed incentive needs its reason, and changes the net.
        Sanctum::actingAs($hr);
        $this->postJson('/api/workforce/payroll/runs', ['period' => '2026-06'])->assertStatus(422)->assertJsonValidationErrors('period');
        $run = $this->postJson('/api/workforce/payroll/runs', ['period' => '2026-04'])->assertCreated()->assertJsonPath('status', 'draft')->assertJsonPath('net', '48000.00')->assertJsonCount(2, 'payslips')->json();
        $ashaSlip = collect($run['payslips'])->firstWhere('employee.id', $asha->id)['id'];
        $raviSlip = collect($run['payslips'])->firstWhere('employee.id', $ravi->id)['id'];
        $this->assertSame([['No department', '18000.00'], ['Nursing', '30000.00']], array_map(fn ($d) => [$d['name'], $d['net']], $run['departments']));
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/payslips/{$ashaSlip}/lines", ['kind' => 'earning', 'name' => 'Night duty incentive', 'amount' => 500])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/payslips/{$ashaSlip}/lines", ['kind' => 'earning', 'name' => 'Night duty incentive', 'amount' => 500, 'note' => 'Six night duties in April'])->assertOk()->assertJsonPath('net', '48500.00');
        $slip = $this->getJson("/api/workforce/payroll/payslips/{$ashaSlip}")->assertOk()->assertJsonPath('net', '30500.00')->assertJsonPath('final', false)->json();
        $this->assertSame(['Basic pay', 'Night duty incentive'], array_column($slip['lines'], 'name'));
        $this->postJson('/api/workforce/payroll/runs', ['period' => '2026-04'])->assertStatus(422); // nobody is left to pay for April

        // HR reviews, but cannot approve. Finalising asks for the password before anything else.
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/step", ['to' => 'reviewed'])->assertOk()->assertJsonPath('status', 'reviewed');
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/step", ['to' => 'approved'])->assertForbidden();
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/finalise")->assertStatus(428);

        // The accountant approves; a reviewed run is no longer changed; the accountant cannot finalise even with the password.
        Sanctum::actingAs($accountant);
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/payslips/{$ashaSlip}/lines", ['kind' => 'deduction', 'name' => 'Fine', 'amount' => 100, 'note' => 'x'])->assertForbidden();
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/step", ['to' => 'approved'])->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('steps.approved.name', $accountant->name);
        $this->typePassword();
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/finalise")->assertForbidden();

        // The admin finalises, password typed. Payslips get their numbers and nothing in the run moves again.
        Sanctum::actingAs($admin);
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/finalise")->assertStatus(428);
        $this->typePassword();
        $final = $this->postJson("/api/workforce/payroll/runs/{$run['id']}/finalise")->assertOk()->assertJsonPath('status', 'finalised')->json();
        $this->assertSame(['PS-202604-0001', 'PS-202604-0002'], collect($final['payslips'])->pluck('slip_no')->sort()->values()->all());
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/work-out")->assertStatus(422);
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/payslips/{$ashaSlip}/lines", ['kind' => 'deduction', 'name' => 'Fine', 'amount' => 100, 'note' => 'Late'])->assertStatus(422);
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/step", ['to' => 'draft', 'reason' => 'Changed my mind'])->assertStatus(422);
        // A salary cannot be slipped in under a finalised month.
        $basic = Tenant::run($this->here->id, fn () => SalaryComponent::query()->value('id'));
        $this->postJson("/api/workforce/pay/salaries/{$asha->id}", ['effective_from' => '2026-04-01', 'pay_basis' => 'monthly', 'lines' => [['component_id' => $basic, 'amount' => 99000]]])->assertStatus(422)->assertJsonValidationErrors('effective_from');

        // Who sees what. The manager sees the run and its total, never anyone's pay. A department manager and a nurse see no runs.
        Sanctum::actingAs($this->user('manager'));
        $seen = $this->getJson("/api/workforce/payroll/runs/{$run['id']}")->assertOk()->assertJsonPath('net', '48500.00')->assertJsonPath('departments', null)->json('payslips');
        $this->assertSame([[null, null], [null, null]], array_map(fn ($s) => [$s['net'], $s['gross']], $seen));
        $this->getJson("/api/workforce/payroll/payslips/{$ashaSlip}")->assertForbidden();
        $this->get("/api/workforce/payroll/runs/{$run['id']}/export")->assertStatus(428);
        $this->getJson('/api/workforce/dashboard')->assertOk()->assertJsonPath('pay.unpaid_salaries', ['slips' => 2, 'net' => '48500.00']);
        Sanctum::actingAs($this->user('dept_manager'));
        $this->getJson('/api/workforce/payroll/runs')->assertForbidden();

        // The nurse sees her own payslip in her own record, in full, and nobody else's.
        Sanctum::actingAs($nurseLogin);
        $this->getJson('/api/workforce/payroll/runs')->assertForbidden();
        $this->getJson('/api/workforce/me')->assertOk()->assertJsonCount(1, 'payslips')->assertJsonPath('payslips.0.net', '30500.00')->assertJsonPath('payslips.0.payment_status', 'unpaid');
        $this->getJson("/api/workforce/me/payslips/{$ashaSlip}")->assertOk()->assertJsonPath('slip_no', collect($final['payslips'])->firstWhere('id', $ashaSlip)['slip_no'])->assertJsonPath('final', true);
        $this->getJson("/api/workforce/me/payslips/{$raviSlip}")->assertNotFound();
        $this->getJson("/api/workforce/payroll/payslips/{$ashaSlip}")->assertForbidden();

        // The accountant records one payment, holds the other with a reason, then pays it: only then is the run "paid".
        Sanctum::actingAs($accountant);
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/pay", ['payslip_ids' => [$ashaSlip], 'status' => 'paid'])->assertStatus(422)->assertJsonValidationErrors(['paid_on', 'payment_mode']);
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/pay", ['payslip_ids' => [$ashaSlip], 'status' => 'paid', 'paid_on' => '2026-05-04', 'payment_mode' => 'bank', 'payment_reference' => 'NEFT 4471'])->assertOk()->assertJsonPath('status', 'finalised');
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/pay", ['payslip_ids' => [$raviSlip], 'status' => 'held', 'reason' => 'Bank details being checked'])->assertOk()->assertJsonPath('status', 'finalised');
        $this->getJson('/api/workforce/payroll/outstanding')->assertOk()->assertJsonPath('slips', 1)->assertJsonPath('net', '18000.00')->assertJsonPath('rows.0.payment_status', 'held');
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/pay", ['payslip_ids' => [$raviSlip], 'status' => 'paid', 'paid_on' => '2026-05-05', 'payment_mode' => 'cash'])->assertOk()->assertJsonPath('status', 'paid');

        // The register is taken away only with the password, and the taking is written down.
        $this->get("/api/workforce/payroll/runs/{$run['id']}/export")->assertOk();
        $file = $this->get("/api/workforce/payroll/runs/{$run['id']}/export")->streamedContent();
        $this->assertStringContainsString('Night duty incentive (earning)', $file);
        $this->assertStringContainsString('Asha Sample', $file);
        $this->assertSame(['period' => '2026-04', 'rows' => 2], AuditEvent::where('action', 'payroll.exported')->first()->after);

        // Reversal is the admin's, with the password and a reason. It frees the month, and the old payslips stay on record.
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/reverse", ['reason' => 'Wrong incentive'])->assertForbidden();
        Sanctum::actingAs($admin);
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/reverse", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/reverse", ['reason' => 'The incentive was for May, not April'])->assertOk()->assertJsonPath('status', 'reversed')->assertJsonPath('reverse_reason', 'The incentive was for May, not April');
        $this->assertSame(2, Tenant::run($this->here->id, fn () => Payslip::query()->where('run_id', $run['id'])->whereNull('live_period')->count()));
        $this->postJson('/api/workforce/payroll/runs', ['period' => '2026-04'])->assertCreated()->assertJsonPath('replaces_run_id', $run['id'])->assertJsonPath('net', '48000.00');
        Sanctum::actingAs($nurseLogin);
        $this->getJson('/api/workforce/me')->assertJsonCount(0, 'payslips'); // the reversed one is no longer hers to show; the new one is still a draft

        // Every step left its line, and none of it is visible from another hospital.
        $done = AuditEvent::withoutGlobalScopes()->where('action', 'like', 'payroll.%')->pluck('action')->all();
        foreach (['payroll.started', 'payroll.line_added', 'payroll.reviewed', 'payroll.approved', 'payroll.finalised', 'payroll.payment_paid', 'payroll.payment_held', 'payroll.exported', 'payroll.reversed'] as $action) {
            $this->assertContains($action, $done);
        }
        Sanctum::actingAs($this->user('admin', [], Branch::create(['name' => 'Another Hospital'])));
        Tenant::forget();
        $this->getJson("/api/workforce/payroll/runs/{$run['id']}")->assertNotFound();
        $this->getJson("/api/workforce/payroll/payslips/{$ashaSlip}")->assertNotFound();
        $this->getJson('/api/workforce/payroll/runs')->assertOk()->assertJsonCount(0, 'runs');
    }

    public function test_whoever_prepared_a_payroll_does_not_also_approve_it(): void
    {
        $this->paid($this->employee('Asha Sample'), 30000);
        // An HR login that was also given the approving permission still cannot approve its own work.
        Sanctum::actingAs($this->user('hr', ['permissions' => ['wf:payroll:approve' => true]]));
        $run = $this->postJson('/api/workforce/payroll/runs', ['period' => '2026-04'])->assertCreated()->assertJsonPath('can.approve', true)->assertJsonPath('can.approve_this', false)->json('id');
        $this->postJson("/api/workforce/payroll/runs/{$run}/step", ['to' => 'reviewed'])->assertOk();
        $this->postJson("/api/workforce/payroll/runs/{$run}/step", ['to' => 'approved'])->assertForbidden();

        // Sent back with a reason, someone is left out by hand, and the run is worked out again without them.
        $this->postJson("/api/workforce/payroll/runs/{$run}/step", ['to' => 'draft'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/workforce/payroll/runs/{$run}/step", ['to' => 'draft', 'reason' => 'One more to add'])->assertOk()->assertJsonPath('status', 'draft');
        $ravi = $this->employee('Ravi Sample');
        $this->paid($ravi, 18000);
        $this->postJson("/api/workforce/payroll/runs/{$run}/work-out")->assertOk()->assertJsonCount(2, 'payslips')->assertJsonPath('net', '48000.00');
        $this->postJson("/api/workforce/payroll/runs/{$run}/hold-back", ['employee_id' => $ravi->id, 'leave_out' => true])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/workforce/payroll/runs/{$run}/hold-back", ['employee_id' => $ravi->id, 'leave_out' => true, 'reason' => 'Paid by his agency this month'])->assertOk()->assertJsonCount(1, 'payslips')->assertJsonPath('left_out.0.reason', 'Left out of this payroll by hand.');
        $this->postJson("/api/workforce/payroll/runs/{$run}/hold-back", ['employee_id' => $ravi->id, 'leave_out' => false])->assertOk()->assertJsonCount(2, 'payslips');
    }

    public function test_advances_are_asked_for_approved_given_and_let_go_only_by_those_who_may(): void
    {
        $nurseLogin = $this->user('nurse');
        $nurse = $this->employee('Nurse One', ['user_id' => $nurseLogin->id]);
        $accountantLogin = $this->user('accountant');
        $accountantSelf = $this->employee('Anil Accounts', ['user_id' => $accountantLogin->id]);

        // The manager puts the request in, and cannot decide it.
        Sanctum::actingAs($this->user('manager'));
        $this->postJson('/api/workforce/advances', ['employee_id' => $nurse->id, 'kind' => 'advance', 'amount' => 9000, 'instalments' => 3, 'first_period' => '2026-05'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $id = $this->postJson('/api/workforce/advances', ['employee_id' => $nurse->id, 'kind' => 'advance', 'amount' => 9000, 'reason' => 'Medical bills', 'instalments' => 3, 'first_period' => '2026-05'])
            ->assertCreated()->assertJsonPath('advance_no', 'ADV-00001')->assertJsonPath('status', 'requested')->assertJsonPath('owed', null)->json('id');
        $own = $this->postJson('/api/workforce/advances', ['employee_id' => $accountantSelf->id, 'kind' => 'loan', 'amount' => 50000, 'reason' => 'House repair', 'instalments' => 10, 'first_period' => '2026-05'])->assertCreated()->assertJsonPath('advance_no', 'ADV-00002')->json('id');
        $this->postJson("/api/workforce/advances/{$id}/decide", ['approve' => true])->assertForbidden();
        $this->getJson('/api/workforce/advances')->assertOk()->assertJsonPath('waiting', 2)->assertJsonPath('owed', ['advances' => 0, 'amount' => '0.00']);

        // The accountant approves (changing the plan to two months) and records the money as given; never their own.
        Sanctum::actingAs($accountantLogin);
        $this->postJson("/api/workforce/advances/{$own}/decide", ['approve' => true])->assertForbidden();
        $this->postJson("/api/workforce/advances/{$id}/issue", ['issued_on' => '2026-05-04', 'payment_mode' => 'cash'])->assertStatus(422); // not approved yet
        $this->postJson("/api/workforce/advances/{$id}/decide", ['approve' => false])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->postJson("/api/workforce/advances/{$id}/decide", ['approve' => true, 'instalments' => 2])->assertOk()->assertJsonPath('status', 'approved')->assertJsonPath('instalments', 2);
        $this->postJson("/api/workforce/advances/{$id}/decide", ['approve' => true])->assertStatus(422);
        $this->postJson("/api/workforce/advances/{$id}/issue", ['issued_on' => '2026-05-09', 'payment_mode' => 'cash'])->assertStatus(422)->assertJsonValidationErrors('issued_on'); // not a future date
        $this->postJson("/api/workforce/advances/{$id}/issue", ['issued_on' => '2026-05-04', 'payment_mode' => 'cash', 'payment_reference' => 'Voucher 88'])->assertOk()->assertJsonPath('status', 'issued')->assertJsonPath('owed', '9000.00');
        $detail = $this->getJson("/api/workforce/advances/{$id}")->assertOk()->json();
        $this->assertSame([['2026-05', '4500.00', 'due'], ['2026-06', '4500.00', 'due']], array_map(fn ($i) => [$i['period'], $i['amount'], $i['status']], $detail['schedule']));
        $this->assertSame([['issued', '9000.00', '9000.00']], array_map(fn ($l) => [$l['kind'], $l['amount'], $l['balance_after']], $detail['ledger']));

        // Part is handed back in cash; June's instalment is put off to the end, with a reason.
        $this->postJson("/api/workforce/advances/{$id}/repay", ['amount' => 20000, 'paid_on' => '2026-05-05', 'note' => 'Cash'])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson("/api/workforce/advances/{$id}/repay", ['amount' => 1000, 'paid_on' => '2026-05-05', 'note' => 'Cash, receipt 20'])->assertOk()->assertJsonPath('owed', '8000.00');
        $this->postJson("/api/workforce/advances/{$id}/skip", ['period' => '2026-06'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/workforce/advances/{$id}/skip", ['period' => '2026-06', 'reason' => 'Asked for one month off'])->assertOk();
        $this->assertSame([['2026-05', 'due'], ['2026-06', 'skipped'], ['2026-07', 'due']], array_map(fn ($i) => [$i['period'], $i['status']], $this->getJson("/api/workforce/advances/{$id}")->json('schedule')));

        // Letting go of what is owed needs the password, then its own permission, which the accountant does not have.
        $this->postJson("/api/workforce/advances/{$id}/let-go", ['kind' => 'waiver', 'amount' => 500, 'reason' => 'Goodwill'])->assertStatus(428);
        $this->typePassword();
        $this->postJson("/api/workforce/advances/{$id}/let-go", ['kind' => 'waiver', 'amount' => 500, 'reason' => 'Goodwill'])->assertForbidden();
        $this->assertStringContainsString('Nurse One', $this->get('/api/workforce/advances/export')->assertOk()->streamedContent());

        // The nurse sees what she owes in her own record, and nothing of the list.
        Sanctum::actingAs($nurseLogin);
        $this->getJson('/api/workforce/advances')->assertForbidden();
        $this->getJson('/api/workforce/me')->assertOk()->assertJsonCount(1, 'advances')->assertJsonPath('advances.0.owed', '8000.00')->assertJsonPath('advances.0.next.period', '2026-05');

        // The admin waives part with a reason, then writes off the rest. The account is closed and says why.
        Sanctum::actingAs($this->user('admin'));
        $this->typePassword();
        $this->postJson("/api/workforce/advances/{$id}/let-go", ['kind' => 'waiver', 'amount' => 500])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/workforce/advances/{$id}/let-go", ['kind' => 'waiver', 'amount' => 500, 'reason' => 'Festival goodwill'])->assertOk()->assertJsonPath('owed', '7500.00')->assertJsonPath('status', 'issued');
        $this->postJson("/api/workforce/advances/{$id}/let-go", ['kind' => 'write_off', 'reason' => 'Left the hospital, not recoverable'])->assertOk()->assertJsonPath('owed', '0.00')->assertJsonPath('status', 'written_off');
        $after = $this->getJson("/api/workforce/advances/{$id}")->json();
        $this->assertSame(['issued', 'repayment', 'waiver', 'write_off'], array_column($after['ledger'], 'kind'));
        $this->assertSame(['waived', 'skipped', 'waived'], array_column($after['schedule'], 'status'));
        $this->assertSame(['owed' => '7500.00'], AuditEvent::where('action', 'advance.written_off')->first()->before);

        // Another hospital cannot reach it.
        Sanctum::actingAs($this->user('admin', [], Branch::create(['name' => 'Another Hospital'])));
        Tenant::forget();
        $this->getJson("/api/workforce/advances/{$id}")->assertNotFound();
        $this->getJson('/api/workforce/advances?status=all')->assertOk()->assertJsonCount(0, 'rows');
    }
}
