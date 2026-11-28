<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Branch;
use App\Models\LabOrder;
use App\Models\LabTest;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\PurchaseOrder;
use App\Models\Service;
use App\Models\StockCount;
use App\Models\Stockist;
use App\Models\User;
use App\Workforce\Models\Attendance;
use App\Workforce\Models\Employee;
use App\Workforce\Models\SalaryComponent;
use App\Workforce\Models\SalaryStructure;
use App\Workforce\Support\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The To do lines added so that each person is told of the work waiting for them: tests advised and not ordered,
 * tests out at another lab, supplier orders not come, consultants and salaries to pay, bookings from the patient app,
 * and stock counts for whoever approves them. Made-up patients, staff and figures.
 */
class ToDoStageCTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-24 10:00');
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Owner']);
    }

    /** The signed-in person's waiting work, each line by "section.key". */
    private function todo(): array
    {
        $screen = $this->getJson('/api/todo')->assertOk()->json();

        return collect($screen['waiting'])->flatMap(fn ($section) => collect($section['items'])->mapWithKeys(fn ($i) => [$section['key'].'.'.$i['key'] => $i]))->all();
    }

    /** @return list<string> the signed-in person's lines in one section */
    private function linesIn(string $section): array
    {
        return array_values(array_filter(array_keys($this->todo()), fn (string $key) => str_starts_with($key, $section.'.')));
    }

    private function labTest(string $name, array $more = []): array
    {
        $parameters = ($more['kind'] ?? null) === 'text' ? [] : ['parameters' => [['name' => 'Result']]];

        return $this->postJson('/api/lab/tests', $more + ['name' => $name, 'price' => 300] + $parameters)->assertCreated()->json();
    }

    /** A patient at the desk for this doctor now. */
    private function booking(User $doctor, Patient $patient): array
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson('/api/appointments', [
            'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now()->format('Y-m-d H:i'), 'status' => 'arrived',
            'service_id' => Service::factory()->create(['price' => 200])->id,
        ])->assertCreated()->json();
    }

    /** A visit the doctor finishes now, with these tests advised. */
    private function visit(User $doctor, Patient $patient, string $advised, ?string $diagnosis = null): array
    {
        $visit = $this->booking($doctor, $patient);
        Sanctum::actingAs($doctor);
        $this->putJson("/api/appointments/{$visit['id']}/consultation", ['tests_advised' => $advised, 'diagnosis' => $diagnosis, 'complete' => true])->assertSuccessful();

        return $visit;
    }

    public function test_tests_advised_today_that_our_lab_does_and_nobody_ordered_reach_the_desk(): void
    {
        $rao = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Rao']);
        $devi = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Devi']);
        Sanctum::actingAs($this->admin);
        $cbp = $this->labTest('Complete Blood Picture - CBP');
        $lipid = $this->labTest('Lipid Profile');
        $tsh = $this->labTest('TSH');
        LabTest::find($this->labTest('Vitamin D3')['id'])->update(['is_active' => false]);

        // Yesterday's advice is for the report now, not for today's desk.
        $this->travelTo('2026-11-23 10:00');
        $this->visit($rao, Patient::factory()->create(['name' => 'Seen Yesterday']), 'TSH');

        $this->travelTo('2026-11-24 10:00');
        $one = Patient::factory()->create(['title' => 'Mrs', 'name' => 'Sample Patient One', 'mobile' => '9123456780']);
        $first = $this->visit($rao, $one, "CBC, Lipid profile\nTSH; MRI brain, Vitamin D3", 'Suspected thyroid trouble');
        $this->travelTo('2026-11-24 10:10');
        $second = $this->visit($devi, Patient::factory()->create(['name' => 'Sample Patient Two']), 'tsh');
        // Only tests done outside the hospital: nothing for the desk to order.
        $this->visit($rao, Patient::factory()->create(['name' => 'Sample Patient Three']), 'X-ray chest, MRI brain');
        // The desk orders the blood count for the first patient: "CBC" is the same test as the lab's "CBP".
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/lab/orders', ['patient_id' => $one->id, 'test_ids' => [$cbp['id']]])->assertCreated();

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $line = $this->todo()['front_desk.tests_not_ordered'];
        // Under the lab's own names; what the lab does not do (an X-ray, a test no longer offered) is left out.
        $this->assertSame([
            ['text' => "Token {$first['token_no']} · Mrs Sample Patient One", 'detail' => 'Dr Rao · Lipid Profile, TSH', 'to' => '/front-desk'],
            ['text' => "Token {$second['token_no']} · Sample Patient Two", 'detail' => 'Dr Devi · TSH', 'to' => '/front-desk'],
        ], $line['rows']);
        $this->assertSame([2, false], [$line['count'], $line['urgent']]);
        // Never the patient's number, the diagnosis, or what the doctor typed for tests done elsewhere.
        $words = json_encode($line, JSON_UNESCAPED_UNICODE);
        foreach (['9123456780', 'thyroid trouble', 'MRI', 'Vitamin'] as $private) {
            $this->assertStringNotContainsString($private, $words);
        }

        // A doctor sees only their own patients; someone who does not work the front desk sees none of it.
        Sanctum::actingAs($rao);
        $this->assertSame(['Dr Rao · Lipid Profile, TSH'], array_column($this->todo()['front_desk.tests_not_ordered']['rows'], 'detail'));
        Sanctum::actingAs($devi);
        $this->assertSame(['Dr Devi · TSH'], array_column($this->todo()['front_desk.tests_not_ordered']['rows'], 'detail'));
        Sanctum::actingAs(User::factory()->create(['role' => 'lab_tech']));
        $this->assertSame([], $this->linesIn('front_desk'));

        // Once the rest are ordered, the visit leaves the line by itself.
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/lab/orders', ['patient_id' => $one->id, 'test_ids' => [$lipid['id'], $tsh['id']]])->assertCreated();
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->assertSame(['Dr Devi · TSH'], array_column($this->todo()['front_desk.tests_not_ordered']['rows'], 'detail'));
    }

    public function test_the_report_of_tests_not_done_knows_a_test_by_its_other_names_and_by_its_package(): void
    {
        $rao = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Rao']);
        Sanctum::actingAs($this->admin);
        $cbp = $this->labTest('Complete Blood Picture - CBP');
        $sugar = $this->labTest('RBS');
        $creatinine = $this->labTest('Serum Creatinine');
        $kidney = $this->postJson('/api/lab/packages', ['name' => 'Kidney Check', 'price' => 400, 'test_ids' => [$sugar['id'], $creatinine['id']]])->assertCreated()->json();

        $count = Patient::factory()->create(['name' => 'Blood Count']);
        $package = Patient::factory()->create(['name' => 'Kidney Patient']);
        $mistake = Patient::factory()->create(['name' => 'Bill Cancelled']);
        $this->visit($rao, $count, 'Haemogram');
        $this->visit($rao, $package, 'Kidney check, Urine culture');
        $this->visit($rao, $mistake, 'RBS');

        Sanctum::actingAs($this->admin);
        $this->postJson('/api/lab/orders', ['patient_id' => $count->id, 'test_ids' => [$cbp['id']]])->assertCreated();
        $this->postJson('/api/lab/orders', ['patient_id' => $package->id, 'package_ids' => [$kidney['id']]])->assertCreated();
        // An order whose bill was cancelled as a mistake was never done.
        $wrong = $this->postJson('/api/lab/orders', ['patient_id' => $mistake->id, 'test_ids' => [$sugar['id']]])->assertCreated()->json();
        $this->postJson("/api/bills/{$wrong['bill_id']}/cancel", ['reason' => 'Ordered for the wrong patient'])->assertOk();

        $rows = collect($this->getJson('/api/reports/list/tests-not-done?from=2026-11-24&to=2026-11-24')->assertOk()->json('rows'))->keyBy('patient');
        $this->assertSame(['Kidney Patient', 'Bill Cancelled'], $rows->keys()->all());
        $this->assertSame(['Urine culture', 2, 1], [$rows['Kidney Patient']['tests'], $rows['Kidney Patient']['advised'], $rows['Kidney Patient']['missing']]);
        $this->assertSame('RBS', $rows['Bill Cancelled']['tests']);
    }

    public function test_a_test_sent_to_an_outside_lab_waits_on_its_own_line_until_its_result_is_entered(): void
    {
        $rao = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Rao']);
        $devi = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Devi']);
        $tech = User::factory()->create(['role' => 'lab_tech']);
        Sanctum::actingAs($this->admin);
        $culture = $this->labTest('Blood culture', ['kind' => 'text', 'price' => 900, 'outside_lab' => 'City Diagnostics', 'outside_cost' => 550]);
        $sugar = $this->labTest('RBS');
        $patient = Patient::factory()->create(['name' => 'Sample Patient']);
        $visit = $this->booking($rao, $patient);
        $ordered = $this->postJson('/api/lab/orders', ['patient_id' => $patient->id, 'appointment_id' => $visit['id'], 'test_ids' => [$culture['id'], $sugar['id']]])->assertCreated()->json();
        // Made at the lab counter: it is no doctor's patient here.
        $counter = $this->postJson('/api/lab/orders', ['patient_id' => Patient::factory()->create(['name' => 'Counter Patient'])->id, 'test_ids' => [$culture['id']]])->assertCreated()->json();
        $item = fn (array $order, string $name) => collect($order['items'])->firstWhere('test_name', $name)['id'];

        Sanctum::actingAs($tech);
        $this->putJson("/api/lab/orders/{$ordered['id']}/items/{$item($ordered, 'Blood culture')}/outside", ['sent' => true])->assertOk();
        $this->putJson("/api/lab/orders/{$counter['id']}/items/{$item($counter, 'Blood culture')}/outside", ['sent' => true])->assertOk();
        $this->putJson("/api/lab/orders/{$ordered['id']}/items/{$item($ordered, 'RBS')}/collect", ['collected' => true])->assertOk();

        // Each test is counted once: the sugar waits to be entered here, the two cultures at the outside lab.
        $lab = $this->todo();
        $this->assertSame(1, $lab['lab.results']['count']);
        $this->assertStringContainsString('RBS', $lab['lab.results']['rows'][0]['detail']);
        [$mine, $theirs] = [LabOrder::find($ordered['id'])->order_no, LabOrder::find($counter['id'])->order_no];
        $this->assertSame([
            ['text' => "{$mine} · Sample Patient", 'detail' => 'Blood culture at City Diagnostics · sent 24 Nov, 10:00 AM', 'to' => "/lab?open={$ordered['id']}"],
            ['text' => "{$theirs} · Counter Patient", 'detail' => 'Blood culture at City Diagnostics · sent 24 Nov, 10:00 AM', 'to' => "/lab?open={$counter['id']}"],
        ], $lab['lab.outside']['rows']);
        $this->assertSame([2, false], [$lab['lab.outside']['count'], $lab['lab.outside']['urgent']]);
        // What the outside lab charges the hospital is never shown.
        $this->assertStringNotContainsString('550', json_encode($lab['lab.outside']));

        // A doctor sees their own patient's test only; the front desk none of the lab's lines.
        Sanctum::actingAs($rao);
        $this->assertSame(["{$mine} · Sample Patient"], array_column($this->todo()['lab.outside']['rows'], 'text'));
        Sanctum::actingAs($devi);
        $this->assertArrayNotHasKey('lab.outside', $this->todo());
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->assertSame([], $this->linesIn('lab'));

        // Out for more than two days, it needs chasing.
        $this->travelTo('2026-11-27 10:00');
        Sanctum::actingAs($tech);
        $this->assertTrue($this->todo()['lab.outside']['urgent']);

        // The result comes back and is entered: the test leaves the line.
        $this->putJson("/api/lab/orders/{$ordered['id']}/items/{$item($ordered, 'Blood culture')}", ['results' => [], 'report_text' => 'No growth after 48 hours.', 'complete' => true])->assertOk();
        $left = $this->todo()['lab.outside'];
        $this->assertSame(["{$theirs} · Counter Patient"], array_column($left['rows'], 'text'));
        $this->assertStringNotContainsString('No growth', json_encode($this->todo()));
        Sanctum::actingAs($rao);
        $this->assertArrayNotHasKey('lab.outside', $this->todo());
    }

    public function test_a_supplier_order_not_come_by_its_day_is_listed_until_it_is_received(): void
    {
        $pharmacist = User::factory()->create(['role' => 'pharmacist']);
        Sanctum::actingAs($pharmacist);
        $supplier = Stockist::create(['name' => 'City Pharma', 'lead_days' => 2]);
        $tablet = Medicine::create(['name' => 'Sample Tablet 500', 'gst_percent' => 12, 'units_per_pack' => 10]);
        $order = $this->postJson('/api/pharmacy/purchase-orders', ['stockist_id' => $supplier->id, 'order_date' => '2026-11-24', 'items' => [['medicine_id' => $tablet->id, 'units_per_pack' => 10, 'packs' => 5]]])
            ->assertCreated()->json();
        // A suggested order nobody has approved is never late: nothing has been asked of the supplier yet.
        $this->postJson('/api/pharmacy/manager/orders', ['lines' => [['medicine_id' => $tablet->id, 'stockist_id' => $supplier->id, 'packs' => 8]]])->assertCreated();
        $this->assertArrayNotHasKey('pharmacy.orders_late', $this->todo());

        // Expected two days after it was ordered, and two days later still nothing.
        $this->travelTo('2026-11-28 10:00');
        $line = $this->todo()['pharmacy.orders_late'];
        $this->assertSame([['text' => "{$order['order_no']} · City Pharma", 'detail' => 'Expected 26 Nov · 2 days late · 1 medicine', 'to' => "/pharmacy/purchase-orders?open={$order['id']}"]], $line['rows']);
        $this->assertSame([1, false], [$line['count'], $line['urgent']]);

        // Only those who run the pharmacy are given it; a manager who may look at the pharmacy is not.
        foreach (['frontdesk', 'manager', 'lab_tech', 'nurse'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->assertSame([], $this->linesIn('pharmacy'), $role);
        }

        Sanctum::actingAs($pharmacist);
        $this->putJson("/api/pharmacy/purchase-orders/{$order['id']}", ['status' => 'received'])->assertOk();
        $this->assertArrayNotHasKey('pharmacy.orders_late', $this->todo());
    }

    public function test_consultants_with_fees_still_due_for_a_finished_month_are_named_without_any_amount(): void
    {
        $here = Branch::create(['name' => 'Sample Hospital']);
        $user = fn (string $role, array $more = []) => User::factory()->create($more + ['role' => $role, 'branch_id' => $here->id]);
        $admin = $user('admin');
        $nurse = $user('nurse');
        $own = $user('doctor', ['name' => 'Dr Own Case', 'share_ipd' => 10]);
        $visiting = $user('doctor', ['name' => 'Dr Visiting']);
        $admission = Admission::create(['branch_id' => $here->id, 'patient_id' => Patient::factory()->create(['name' => 'Sample Patient'])->id, 'doctor_id' => $own->id, 'admitted_at' => '2026-11-20 09:00', 'status' => 'admitted', 'created_by' => $admin->id]);
        $wardVisit = fn (string $day) => $this->postJson("/api/ipd/admissions/{$admission->id}/consultant-visits", ['doctor_id' => $visiting->id, 'visited_on' => $day])->assertCreated();
        Sanctum::actingAs($nurse);
        $wardVisit('2026-11-22');
        $wardVisit('2026-11-23');

        // November is still being earned.
        Sanctum::actingAs($admin);
        $this->assertArrayNotHasKey('office.consultants', $this->todo());

        // December: November is finished. A visit in December is not owed yet.
        $this->travelTo('2026-12-02 10:00');
        Sanctum::actingAs($nurse);
        $wardVisit('2026-12-01');
        Sanctum::actingAs($admin);
        $line = $this->todo()['office.consultants'];
        $this->assertSame([['text' => 'Dr Visiting', 'detail' => 'November 2026 · nothing paid yet · 2 ward visits', 'to' => '/workforce/consultants?month=2026-11']], $line['rows']);
        $this->assertSame(['/workforce/consultants?month=2026-11', false], [$line['to'], $line['urgent']]);
        $words = json_encode($line, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('₹', $words);
        $this->assertDoesNotMatchRegularExpression('/1,?500|1,?000/', $words);

        // Only whoever looks after consultants' fees: not a doctor, not a manager; the owner's doctor login once ticked.
        Sanctum::actingAs($own);
        $this->assertArrayNotHasKey('office.consultants', $this->todo());
        Sanctum::actingAs($user('manager'));
        $this->assertArrayNotHasKey('office.consultants', $this->todo());
        $own->update(['permissions' => ['canPayConsultants' => true]]);
        Sanctum::actingAs($own->fresh());
        $this->assertSame(1, $this->todo()['office.consultants']['count']);

        // Part paid, then the rest: the line goes. A payment taken back brings it back.
        Sanctum::actingAs($admin);
        $pay = fn (int $amount) => $this->postJson("/api/consultants/{$visiting->id}/payments", ['month' => '2026-11', 'paid_on' => '2026-12-02', 'amount' => $amount, 'mode' => 'cash'])->assertCreated()->json('id');
        $pay(1000);
        $this->assertSame('November 2026 · part paid · 2 ward visits', $this->todo()['office.consultants']['rows'][0]['detail']);
        $rest = $pay(500);
        $this->assertArrayNotHasKey('office.consultants', $this->todo());
        $this->travelTo('2026-12-02 10:05');
        $this->postJson("/api/consultants/payments/{$rest}/cancel", ['reason' => 'Written down twice'])->assertOk();
        $this->assertSame('November 2026 · part paid · 2 ward visits', $this->todo()['office.consultants']['rows'][0]['detail']);
    }

    public function test_a_payroll_waits_for_each_person_at_their_own_step_and_names_no_pay(): void
    {
        $this->travelTo('2026-05-05 10:00');
        $here = Branch::create(['name' => 'Sample Hospital']);
        // A second hospital, so that a login with no branch of its own belongs to neither.
        Branch::create(['name' => 'Other Hospital']);
        $user = fn (string $role, array $more = []) => User::factory()->create($more + ['role' => $role, 'branch_id' => $here->id, 'password' => bcrypt('Sample-Passw0rd')]);
        // HR prepares and reviews; this HR login may approve too, but never a payroll it prepared or reviewed.
        $hr = $user('hr', ['permissions' => ['wf:payroll:approve' => true]]);
        $accountant = $user('accountant');
        $admin = $user('admin');
        $asha = $this->employee($here, 'Asha Sample', 30000);
        $ravi = $this->employee($here, 'Ravi Sample', 18000);

        Sanctum::actingAs($hr);
        $run = $this->postJson('/api/workforce/payroll/runs', ['period' => '2026-04'])->assertCreated()->json();
        $slip = fn (Employee $e) => collect($run['payslips'])->firstWhere('employee.id', $e->id)['id'];
        $rows = fn (string $detail) => [['text' => 'April 2026 payroll', 'detail' => $detail, 'to' => "/workforce/payroll?run={$run['id']}"]];
        $this->assertSame($rows('2 staff · to check'), $this->todo()['office.payroll']['rows']);
        Sanctum::actingAs($accountant);
        $this->assertArrayNotHasKey('office.payroll', $this->todo());

        // Reviewed: the next step is someone else's.
        Sanctum::actingAs($hr);
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/step", ['to' => 'reviewed'])->assertOk();
        $this->assertArrayNotHasKey('office.payroll', $this->todo());
        Sanctum::actingAs($accountant);
        $line = $this->todo()['office.payroll'];
        $this->assertSame($rows('2 staff · to approve'), $line['rows']);
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/step", ['to' => 'approved'])->assertOk();
        $this->assertArrayNotHasKey('office.payroll', $this->todo());

        Sanctum::actingAs($admin);
        $this->assertSame($rows('2 staff · to finalise'), $this->todo()['office.payroll']['rows']);
        $this->postJson('/api/workforce/confirm-password', ['password' => 'Sample-Passw0rd'])->assertOk();
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/finalise", ['checked' => true])->assertOk();
        $todo = $this->todo();
        $this->assertArrayNotHasKey('office.payroll', $todo);
        $this->assertSame($rows('2 payslips not marked paid'), $todo['office.salaries_unpaid']['rows']);
        $this->assertSame(2, $todo['office.salaries_unpaid']['count']);
        // Counts and names of payrolls only: nobody's pay, and nobody's name from inside the run.
        $words = json_encode([$line, $todo['office.salaries_unpaid']], JSON_UNESCAPED_UNICODE);
        foreach (['₹', '30000', '18000', '48000', 'Asha', 'Ravi'] as $private) {
            $this->assertStringNotContainsString($private, $words);
        }

        // A manager sees the runs but takes no step in them; a login with no branch sees neither hospital's payroll.
        foreach ([$user('manager'), User::factory()->create(['role' => 'accountant'])] as $who) {
            Sanctum::actingAs($who);
            $todo = $this->todo();
            $this->assertArrayNotHasKey('office.payroll', $todo);
            $this->assertArrayNotHasKey('office.salaries_unpaid', $todo);
        }

        // One salary written down as paid; the other held back on purpose, which is not "waiting to be paid".
        Sanctum::actingAs($accountant);
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/pay", ['payslip_ids' => [$slip($asha)], 'status' => 'paid', 'paid_on' => '2026-05-04', 'payment_mode' => 'bank'])->assertOk();
        $this->assertSame($rows('1 payslip not marked paid'), $this->todo()['office.salaries_unpaid']['rows']);
        $this->postJson("/api/workforce/payroll/runs/{$run['id']}/pay", ['payslip_ids' => [$slip($ravi)], 'status' => 'held', 'reason' => 'Bank details being checked'])->assertOk();
        $todo = $this->todo();
        $this->assertArrayNotHasKey('office.salaries_unpaid', $todo);
        $this->assertStringNotContainsString('Bank details', json_encode($todo));
    }

    /** A member of staff with an approved salary and a month of attendance behind it. */
    private function employee(Branch $branch, string $name, int $basic): Employee
    {
        return Tenant::run($branch->id, function () use ($name, $basic) {
            $employee = Employee::create(['employee_no' => 'E'.random_int(10000, 99999), 'name' => $name, 'joined_on' => '2025-01-01', 'weekly_off' => ['sun']]);
            $part = SalaryComponent::query()->firstOrCreate(['code' => 'BASIC'], ['name' => 'Basic pay', 'kind' => 'earning', 'basis' => 'fixed']);
            SalaryStructure::create(['employee_id' => $employee->id, 'effective_from' => '2026-01-01', 'pay_basis' => 'monthly', 'approved_at' => now()])->lines()->create(['component_id' => $part->id, 'amount' => $basic]);
            for ($day = CarbonImmutable::parse('2026-04-01'); $day->format('Y-m') === '2026-04'; $day = $day->addDay()) {
                if (! $day->isSunday()) {
                    Attendance::create(['employee_id' => $employee->id, 'work_date' => $day->toDateString(), 'check_in' => $day->toDateString().' 09:00:00', 'check_out' => $day->toDateString().' 17:00:00', 'state' => 'completed', 'day_status' => 'present', 'worked_minutes' => 480, 'origin' => 'manual']);
                }
            }

            return $employee;
        });
    }

    public function test_visits_booked_from_the_patient_app_reach_the_desk_and_are_marked_for_the_doctor(): void
    {
        $this->travelTo('2026-11-24 09:00');
        $rao = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Rao']);
        $devi = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Devi']);
        $register = function (string $name, string $mobile): PatientAccount {
            $this->postJson('/api/app/register', ['name' => $name, 'mobile' => $mobile, 'gender' => 'female', 'date_of_birth' => '1970-05-01', 'password' => 'Health2026', 'password_confirmation' => 'Health2026'])->assertCreated();

            return PatientAccount::where('mobile', $mobile)->first();
        };
        $mother = $register('Sample Mother', '9876543210');
        $aunt = $register('Sample Aunt', '9000000001');

        Sanctum::actingAs($mother);
        $tomorrow = $this->postJson('/api/app/appointments', ['doctor_id' => $rao->id, 'scheduled_at' => '2026-11-25 10:45', 'notes' => 'Sugar check'])->assertCreated()->json();
        Sanctum::actingAs($aunt);
        $this->postJson('/api/app/appointments', ['doctor_id' => $rao->id, 'scheduled_at' => '2026-11-24 10:45'])->assertCreated();
        // A visit the desk itself booked is not listed.
        Sanctum::actingAs($this->admin);
        $this->postJson('/api/appointments', ['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $rao->id, 'scheduled_at' => '2026-11-25 11:00', 'status' => 'booked', 'service_id' => Service::factory()->create(['price' => 200])->id])
            ->assertCreated();

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $line = $this->todo()['front_desk.app_bookings'];
        $this->assertSame([
            ['text' => 'Sample Aunt', 'detail' => 'Dr Rao · Today 10:45 AM · Token 1', 'to' => "/patients/{$aunt->patient_id}"],
            ['text' => 'Sample Mother', 'detail' => 'Dr Rao · Tomorrow 10:45 AM · Token 1', 'to' => "/patients/{$mother->patient_id}"],
        ], $line['rows']);
        $this->assertFalse($line['urgent']);
        // Never the note the patient typed, nor their number.
        $words = json_encode($line, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Sugar check', $words);
        $this->assertStringNotContainsString('9876543210', $words);

        // The doctor sees their own, and that today's patient booked from the app; another doctor sees none.
        Sanctum::actingAs($rao);
        $mine = $this->todo();
        $this->assertSame(2, $mine['front_desk.app_bookings']['count']);
        $this->assertSame('Booked for 10:45 AM · from the patient app', $mine['doctor.to_come']['rows'][0]['detail']);
        Sanctum::actingAs($devi);
        $this->assertArrayNotHasKey('front_desk.app_bookings', $this->todo());

        // Once today's time has gone it is under "not arrived" instead, and a booking cancelled in the app goes.
        $this->travelTo('2026-11-24 11:00');
        Sanctum::actingAs($mother);
        $this->deleteJson("/api/app/appointments/{$tomorrow['id']}")->assertOk();
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $desk = $this->todo();
        $this->assertArrayNotHasKey('front_desk.app_bookings', $desk);
        $this->assertSame(1, $desk['front_desk.late']['count']);
    }

    public function test_stock_counts_handed_in_reach_whoever_approves_them_even_one_who_only_looks_at_the_pharmacy(): void
    {
        $count = StockCount::create(['title' => 'Rack A count', 'scope' => 'rack', 'status' => 'submitted', 'submitted_at' => now()]);
        $rows = [['text' => 'Rack A count', 'detail' => 'Handed in 24 Nov', 'to' => "/pharmacy/audit/counts/{$count->id}"]];
        // A supplier order well past its day: a line only for those who run the pharmacy.
        PurchaseOrder::create(['stockist_id' => Stockist::create(['name' => 'City Pharma'])->id, 'order_date' => '2026-11-10', 'status' => 'open']);

        // A manager trusted with the audit: the count and nothing else of the pharmacy.
        $approver = User::factory()->create(['role' => 'manager', 'permissions' => ['canAuditPharmacy' => true]]);
        Sanctum::actingAs($approver);
        $this->assertSame(['pharmacy.stock_counts'], $this->linesIn('pharmacy'));
        $this->assertSame($rows, $this->todo()['pharmacy.stock_counts']['rows']);

        // The admin gets it with the rest; a pharmacist counts but does not approve; a manager without the tick, and
        // anyone who may not even look at the pharmacy, get no pharmacy line at all.
        Sanctum::actingAs($this->admin);
        $admin = $this->todo();
        $this->assertSame($rows, $admin['pharmacy.stock_counts']['rows']);
        $this->assertArrayHasKey('pharmacy.orders_late', $admin);
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->assertContains('pharmacy.orders_late', $this->linesIn('pharmacy'));
        $this->assertNotContains('pharmacy.stock_counts', $this->linesIn('pharmacy'));
        foreach ([User::factory()->create(['role' => 'manager']), User::factory()->create(['role' => 'nurse', 'permissions' => ['canAuditPharmacy' => true]]), User::factory()->create(['role' => 'frontdesk'])] as $who) {
            Sanctum::actingAs($who);
            $this->assertSame([], $this->linesIn('pharmacy'), $who->role->value);
        }

        // Approved, it leaves the line.
        Sanctum::actingAs($approver);
        $this->postJson("/api/pharmacy/audit/counts/{$count->id}/approve")->assertOk();
        $this->assertSame([], $this->linesIn('pharmacy'));
    }
}
