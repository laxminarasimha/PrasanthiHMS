<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bed;
use App\Models\DayClose;
use App\Models\IpdService;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IpdTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private Bed $bed;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'nurse']));
        $this->doctor = User::factory()->create(['role' => 'doctor']);
        $this->bed = Bed::create(['ward' => 'General Ward', 'bed_no' => '1', 'daily_charge' => 800]);
    }

    private function admit(array $overrides = [])
    {
        return $this->postJson('/api/ipd/admissions', $overrides + [
            'patient_id' => Patient::factory()->create()->id,
            'doctor_id' => $this->doctor->id,
            'bed_id' => $this->bed->id,
            'admitted_at' => now()->subDays(2)->format('Y-m-d H:i'),
            'reason' => 'Fever for evaluation',
        ]);
    }

    public function test_an_ipd_payment_entered_under_the_wrong_mode_can_be_corrected_with_a_reason(): void
    {
        $case = $this->admit()->assertCreated()->json();
        $url = "/api/ipd/admissions/{$case['id']}/payments";
        $payment = $this->postJson($url, ['type' => 'advance', 'amount' => 5000, 'mode' => 'cash'])->assertCreated()->json('payments.0.id');

        $this->putJson("{$url}/{$payment}", ['mode' => 'upi'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->putJson("{$url}/{$payment}", ['mode' => 'cash', 'reason' => 'Wrong mode'])->assertStatus(422)->assertJsonValidationErrors('mode'); // nothing to change

        // Only the mode moves: the amount and what has been received stay as they were.
        $this->putJson("{$url}/{$payment}", ['mode' => 'upi', 'reference' => 'UPI 9911', 'reason' => 'Advance came by PhonePe, entered as cash'])->assertOk()
            ->assertJsonPath('payments.0.mode', 'upi')->assertJsonPath('payments.0.reference', 'UPI 9911')->assertJsonPath('payments.0.amount', 5000)
            ->assertJsonPath('payments.0.type', 'advance')->assertJsonPath('paid', 5000);
        $log = AuditLog::where('action', 'ipd.payment_mode')->sole();
        $this->assertStringContainsString('advance of ₹5000', $log->summary);
        $this->assertStringContainsString('changed from cash to upi (UPI 9911). Reason: Advance came by PhonePe, entered as cash', $log->summary);

        // Not through another case, and not by someone who does not work in IPD.
        $other = $this->admit(['bed_id' => null])->assertCreated()->json('id');
        $this->putJson("/api/ipd/admissions/{$other}/payments/{$payment}", ['mode' => 'card', 'reason' => 'Wrong case'])->assertNotFound();
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->putJson("{$url}/{$payment}", ['mode' => 'cash', 'reason' => 'Not my desk'])->assertForbidden();

        // Once the day's accounts are closed, its cash and bank totals cannot be moved.
        Sanctum::actingAs(User::factory()->create(['role' => 'nurse']));
        DayClose::create(['day' => today()->toDateString(), 'closed_by_name' => 'Admin', 'closed_at' => now(), 'expected_cash' => 0, 'counted_cash' => 0, 'variance' => 0, 'snapshot' => []]);
        $this->putJson("{$url}/{$payment}", ['mode' => 'cash', 'reason' => 'Back to cash'])->assertStatus(423);
    }

    public function test_beds_and_services_can_be_set_up_and_duplicate_beds_are_refused(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/ipd/beds', ['ward' => 'General Ward', 'bed_no' => '2', 'daily_charge' => 800])->assertCreated();
        $this->postJson('/api/ipd/beds', ['ward' => 'General Ward', 'bed_no' => '2'])->assertStatus(422)->assertJsonValidationErrors('bed_no');
        $this->postJson('/api/ipd/services', ['name' => 'Nursing charges', 'category' => 'Nursing', 'price' => 500])->assertCreated();

        $this->getJson('/api/ipd/beds')->assertOk()->assertJsonCount(2);
        $this->getJson('/api/ipd/services')->assertOk()->assertJsonCount(1);
    }

    public function test_admitting_gives_a_case_number_and_occupies_the_bed(): void
    {
        $admission = $this->admit()->assertCreated()->json();

        $this->assertMatchesRegularExpression('/^IP\d{5}$/', $admission['ipd_no']);
        $this->assertSame('admitted', $admission['status']);
        $this->getJson('/api/ipd/beds')->assertJsonPath('0.current_admission.ipd_no', $admission['ipd_no']);
        $this->getJson('/api/ipd/admissions')->assertOk()->assertJsonCount(1);
    }

    public function test_an_occupied_bed_or_an_already_admitted_patient_is_refused(): void
    {
        $first = $this->admit()->assertCreated()->json();

        $this->admit()->assertStatus(422)->assertJsonValidationErrors('bed_id');
        $this->admit(['patient_id' => $first['patient']['id'], 'bed_id' => null])->assertStatus(422)->assertJsonValidationErrors('patient_id');
    }

    public function test_running_bill_follows_charges_discount_advances_and_refunds(): void
    {
        $id = $this->admit()->json('id');
        $service = IpdService::create(['name' => 'Doctor visit', 'category' => 'Doctor', 'price' => 800]);
        $url = "/api/ipd/admissions/{$id}";

        $this->postJson("{$url}/payments", ['type' => 'advance', 'amount' => 5000, 'mode' => 'cash'])
            ->assertCreated()->assertJsonPath('paid', 5000)->assertJsonPath('due', -5000);

        $this->postJson("{$url}/charges", ['ipd_service_id' => $service->id, 'charged_on' => now()->toDateString(), 'quantity' => 2, 'unit_price' => 800])
            ->assertCreated()->assertJsonPath('total', 1600)->assertJsonPath('charges.0.description', 'Doctor visit');
        $bedCharge = $this->postJson("{$url}/charges", ['description' => 'Bed charges', 'charged_on' => now()->toDateString(), 'quantity' => 2, 'unit_price' => 800])
            ->assertCreated()->assertJsonPath('total', 3200)->json('charges.1.id');

        $this->putJson($url, ['discount' => 200])->assertOk()->assertJsonPath('net', 3000)->assertJsonPath('due', -2000);
        $this->putJson($url, ['discount' => 9999])->assertStatus(422)->assertJsonValidationErrors('discount');

        $this->postJson("{$url}/payments", ['type' => 'refund', 'amount' => 2000, 'mode' => 'cash'])
            ->assertCreated()->assertJsonPath('paid', 3000)->assertJsonPath('due', 0);
        $this->postJson("{$url}/payments", ['type' => 'refund', 'amount' => 4000, 'mode' => 'cash'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');

        $this->deleteJson("{$url}/charges/{$bedCharge}")->assertOk()->assertJsonPath('total', 1600)->assertJsonPath('due', -1600);
    }

    public function test_discharge_closes_the_case_frees_the_bed_and_cannot_be_repeated(): void
    {
        $id = $this->admit()->json('id');
        $url = "/api/ipd/admissions/{$id}/discharge";

        $this->postJson($url, ['status' => 'discharged', 'discharged_at' => now()->subDays(5)->format('Y-m-d H:i')])
            ->assertStatus(422)->assertJsonValidationErrors('discharged_at');

        $this->postJson($url, ['status' => 'discharged', 'discharged_at' => now()->format('Y-m-d H:i'), 'discharge_summary' => 'Recovered. Review in one week.'])
            ->assertOk()->assertJsonPath('status', 'discharged')->assertJsonPath('discharge_summary', 'Recovered. Review in one week.');

        $this->postJson($url, ['status' => 'lama', 'discharged_at' => now()->format('Y-m-d H:i')])->assertStatus(422);
        $this->getJson('/api/ipd/beds')->assertJsonPath('0.current_admission', null);
        $this->getJson('/api/ipd/admissions')->assertJsonCount(0);
        $this->getJson('/api/ipd/admissions?status=discharged')->assertJsonCount(1);
        // The freed bed can take a new patient.
        $this->admit()->assertCreated();
    }

    public function test_patient_can_be_moved_to_a_free_bed_but_not_an_occupied_one(): void
    {
        $other = Bed::create(['ward' => 'Special Room', 'bed_no' => '1', 'daily_charge' => 2000]);
        $first = $this->admit()->json('id');
        $second = $this->admit(['bed_id' => $other->id])->json('id');

        $this->putJson("/api/ipd/admissions/{$second}", ['bed_id' => $this->bed->id])->assertStatus(422)->assertJsonValidationErrors('bed_id');

        $third = Bed::create(['ward' => 'AC Room', 'bed_no' => '1']);
        $this->putJson("/api/ipd/admissions/{$first}", ['bed_id' => $third->id])->assertOk()->assertJsonPath('bed.ward', 'AC Room');
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson("/api/ipd/beds/{$third->id}", ['ward' => 'AC Room', 'bed_no' => '1', 'is_active' => false])
            ->assertStatus(422)->assertJsonValidationErrors('is_active');
    }
}
