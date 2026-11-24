<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The owner's batch: money by doctor, a doctor's share of collections, tests advised and not done,
 * and the whole patient list as a file.
 */
class OwnerBatchThreeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 11:00');
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Owner']);
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function report(string $type): array
    {
        return $this->getJson("/api/reports/list/{$type}?from=2026-11-01&to=2026-11-30")->assertOk()->json();
    }

    /** A visit to the doctor, billed at the given fee, with this much paid at the desk. */
    private function visit(User $doctor, float $fee, float $paid, ?Patient $patient = null): array
    {
        $service = Service::firstOrCreate(['name' => 'Consultation'], ['type' => 'appointment', 'price' => 500]);

        return $this->postJson('/api/appointments', [
            'patient_id' => ($patient ?? Patient::factory()->create())->id, 'doctor_id' => $doctor->id, 'service_id' => $service->id,
            'scheduled_at' => '2026-11-24 11:00', 'status' => 'arrived', 'unit_price' => $fee, 'quantity' => 1,
        ] + ($paid > 0 ? ['paid' => $paid, 'payment_mode' => 'cash'] : []))->assertCreated()->json();
    }

    public function test_money_is_reported_doctor_by_doctor_and_a_share_is_worked_out(): void
    {
        $rao = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Rao']);
        $devi = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Devi']);
        $sugar = $this->postJson('/api/lab/tests', ['name' => 'RBS', 'price' => 200, 'parameters' => [['name' => 'Glucose']]])->assertCreated()->json();

        // Dr Rao: two visits of ₹500, one paid in full and one half paid; and lab tests ordered from one of them.
        $first = $this->visit($rao, 500, 500);
        $this->visit($rao, 500, 250);
        $order = $this->postJson('/api/lab/orders', ['patient_id' => $first['patient_id'], 'appointment_id' => $first['id'], 'test_ids' => [$sugar['id']]])->assertCreated()->json();
        $this->postJson("/api/bills/{$order['bill_id']}/payments", ['type' => 'payment', 'amount' => 200, 'mode' => 'upi'])->assertSuccessful();
        // Dr Devi: one visit of ₹300, unpaid. And a lab order for a walk-in, with no doctor.
        $this->visit($devi, 300, 0);
        $walkIn = $this->postJson('/api/lab/orders', ['patient_id' => Patient::factory()->create()->id, 'test_ids' => [$sugar['id']]])->assertCreated()->json();
        $this->postJson("/api/bills/{$walkIn['bill_id']}/payments", ['type' => 'payment', 'amount' => 200, 'mode' => 'cash'])->assertSuccessful();

        $rows = collect($this->report('doctor-collections')['rows'])->keyBy('doctor');
        $this->assertSame(['Dr Rao', 'Dr Devi', 'No doctor on the bill'], $rows->keys()->all());
        $this->assertEquals([1000, 750, 200, 200, 1200, 950], array_values(array_intersect_key($rows['Dr Rao'], array_flip(['front_desk_billed', 'front_desk_collected', 'lab_billed', 'lab_collected', 'billed', 'collected']))));
        $this->assertEquals([300, 0], [$rows['Dr Devi']['front_desk_billed'], $rows['Dr Devi']['collected']]);
        $this->assertEquals([200, 200], [$rows['No doctor on the bill']['lab_billed'], $rows['No doctor on the bill']['lab_collected']]);
        // The same money as the organisation report shows for the two counters.
        $summary = $this->getJson('/api/reports/summary?from=2026-11-01&to=2026-11-30')->assertOk()->json('departments');
        $this->assertEquals($summary['consultation']['collected_total'] + $summary['lab']['collected_total'], $rows->sum('collected'));

        // No share is set: the report says so and works out nothing.
        $payout = collect($this->report('doctor-payout')['rows'])->keyBy('doctor');
        $this->assertSame(['Dr Rao', 'Dr Devi'], $payout->keys()->all());
        $this->assertSame('Not set', $payout['Dr Rao']['front_desk_percent']);
        $this->assertEquals(0, $payout['Dr Rao']['share']);

        // The owner sets Dr Rao's share: 60% of consultations, 12.5% of lab.
        $this->putJson("/api/staff/{$rao->id}", ['name' => 'Dr Rao', 'email' => $rao->email, 'role' => 'doctor', 'gender' => 'male', 'phone' => '9000000001', 'share_front_desk' => 60, 'share_lab' => 12.5, 'share_ipd' => null])
            ->assertOk()->assertJsonPath('share_front_desk', 60)->assertJsonPath('share_lab', 12.5);
        $payout = collect($this->report('doctor-payout')['rows'])->keyBy('doctor');
        $this->assertSame(['60%', '12.5%', 'Not set'], [$payout['Dr Rao']['front_desk_percent'], $payout['Dr Rao']['lab_percent'], $payout['Dr Rao']['ipd_percent']]);
        $this->assertEquals([450, 25, 475], [$payout['Dr Rao']['front_desk_share'], $payout['Dr Rao']['lab_share'], $payout['Dr Rao']['share']]);

        // Money put towards a bill from the patient's deposit counts for the doctor. The entry that moves a bill
        // onto an inpatient bill does not: that money comes in with the inpatient bill.
        $billId = DB::table('bills')->where('appointment_id', $first['id'])->whereNotIn('id', DB::table('lab_orders')->select('bill_id'))->value('id');
        foreach ([['deposit', 50], ['ipd', 100]] as [$mode, $amount]) {
            DB::table('payments')->insert(['bill_id' => $billId, 'type' => 'payment', 'amount' => $amount, 'mode' => $mode, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->assertEquals(800, collect($this->report('doctor-collections')['rows'])->firstWhere('doctor', 'Dr Rao')['front_desk_collected']);
    }

    public function test_only_an_admin_sees_or_sets_a_doctors_share(): void
    {
        $rao = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Rao', 'share_front_desk' => 60]);
        $this->assertSame(60, collect($this->getJson('/api/staff')->assertOk()->json('data'))->firstWhere('id', $rao->id)['share_front_desk']);
        // The share is not part of what other screens are sent about a doctor.
        $this->assertArrayNotHasKey('share_front_desk', $rao->fresh()->toArray());

        // A manager keeps the staff list, but the share is not shown to them and their change to it is dropped.
        // The report of shares is not offered to them either, though the other reports are.
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));
        $this->getJson('/api/reports/list/doctor-payout')->assertForbidden();
        $this->assertArrayNotHasKey('doctor-payout', $this->getJson('/api/reports/list/doctor-collections')->assertOk()->json('reports'));
        $this->assertArrayNotHasKey('share_front_desk', collect($this->getJson('/api/staff')->assertOk()->json('data'))->firstWhere('id', $rao->id));
        $this->putJson("/api/staff/{$rao->id}", ['name' => 'Dr Rao', 'email' => $rao->email, 'role' => 'doctor', 'gender' => 'male', 'phone' => '9000000001', 'share_front_desk' => 90])
            ->assertOk()->assertJsonMissingPath('share_front_desk');
        $this->assertEquals(60, $rao->fresh()->share_front_desk);
    }

    public function test_tests_advised_and_not_done_here_are_listed_visit_by_visit(): void
    {
        $rao = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Rao']);
        $cbp = $this->postJson('/api/lab/tests', ['name' => 'Complete Blood Picture - CBP', 'price' => 300, 'parameters' => [['name' => 'Haemoglobin']]])->assertCreated()->json();
        $done = Patient::factory()->create(['name' => 'Did All']);
        $half = Patient::factory()->create(['name' => 'Did Half', 'mobile' => '9123456780']);
        $none = Patient::factory()->create(['name' => 'Did None']);

        Sanctum::actingAs($rao);
        foreach ([[$done, "CBP"], [$half, "CBP\nLipid profile, TSH"], [$none, 'Urine culture']] as [$patient, $advised]) {
            $visit = $this->visit($rao, 500, 0, $patient);
            $this->putJson("/api/appointments/{$visit['id']}/consultation", ['tests_advised' => $advised, 'complete' => true])->assertSuccessful();
        }
        Sanctum::actingAs($this->admin);
        // Two of them have the blood count done in our lab afterwards.
        foreach ([$done, $half] as $patient) {
            $this->postJson('/api/lab/orders', ['patient_id' => $patient->id, 'test_ids' => [$cbp['id']]])->assertCreated();
        }

        $rows = collect($this->report('tests-not-done')['rows'])->keyBy('patient');
        $this->assertSame(['Did Half', 'Did None'], $rows->keys()->all());
        $this->assertSame(['Lipid profile, TSH', 3, 2, '9123456780', 'Dr Rao'], [$rows['Did Half']['tests'], $rows['Did Half']['advised'], $rows['Did Half']['missing'], $rows['Did Half']['mobile'], $rows['Did Half']['doctor']]);
        $this->assertSame('Urine culture', $rows['Did None']['tests']);
    }

    public function test_the_whole_patient_list_can_be_taken_as_a_file_by_those_allowed(): void
    {
        $rao = User::factory()->create(['role' => 'doctor']);
        $regular = Patient::factory()->create(['name' => 'Regular Patient', 'gender' => 'female', 'date_of_birth' => '1990-05-01', 'city' => 'Vijayawada', 'referred_by' => 'Dr Outside']);
        Patient::factory()->create(['name' => 'Merged Away', 'merged_into_id' => $regular->id]);
        $this->visit($rao, 500, 0, $regular);

        $file = $this->getJson('/api/patients/export')->assertOk()->json();
        $this->assertSame(['Patient ID', 'Title', 'Name'], array_slice($file['columns'], 0, 3));
        $this->assertCount(1, $file['rows']);
        $row = array_combine($file['columns'], $file['rows'][0]);
        $this->assertSame(['Regular Patient', 'Female', '36y', '1990-05-01', 'Vijayawada', 'Dr Outside', 1, '2026-11-24'], [$row['Name'], $row['Sex'], $row['Age'], $row['Date of birth'], $row['City'], $row['Referred by'], $row['Visits'], $row['Last visit']]);
        $this->assertSame('1 patients, the whole list', AuditLog::where('action', 'patients.exported')->value('summary'));

        // A manager is not allowed to export unless given the permission.
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));
        $this->getJson('/api/patients/export')->assertForbidden();
    }
}
