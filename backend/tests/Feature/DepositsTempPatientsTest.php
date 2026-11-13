<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DepositsTempPatientsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_deposit_is_taken_used_against_a_bill_and_the_rest_refunded(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $patient = Patient::factory()->create();
        $doctor = User::factory()->create(['role' => 'doctor']);
        $deposits = "/api/patients/{$patient->id}/deposits";

        $this->postJson($deposits, ['type' => 'refund', 'amount' => 100, 'mode' => 'cash'])->assertStatus(422);
        $this->postJson($deposits, ['type' => 'deposit', 'amount' => 1000, 'mode' => 'upi', 'reference' => 'Advance'])->assertCreated()->assertJsonPath('deposit_balance', 1000);

        $bill = $this->postJson('/api/appointments', [
            'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now()->format('Y-m-d H:i'),
            'service_id' => Service::factory()->create(['price' => 600])->id,
        ])->assertCreated()->json('bills.0.id');
        $this->getJson("/api/bills/{$bill}")->assertJsonPath('deposit_balance', 1000);

        $this->postJson("/api/bills/{$bill}/use-deposit", ['amount' => 700])->assertStatus(422)->assertJsonValidationErrors('amount'); // more than is due
        $this->postJson("/api/bills/{$bill}/use-deposit", ['amount' => 600])->assertCreated()->assertJsonPath('due', 0)->assertJsonPath('deposit_balance', 400)->assertJsonPath('payments.0.mode', 'deposit');
        $this->postJson("/api/bills/{$bill}/use-deposit", ['amount' => 1])->assertStatus(422);

        $this->postJson($deposits, ['type' => 'refund', 'amount' => 500, 'mode' => 'cash'])->assertStatus(422);
        $this->postJson($deposits, ['type' => 'refund', 'amount' => 400, 'mode' => 'cash'])->assertCreated()->assertJsonPath('deposit_balance', 0)->assertJsonCount(3, 'deposits');
        $this->getJson("/api/patients/{$patient->id}/overview")->assertOk()->assertJsonPath('deposit_balance', 0)->assertJsonCount(3, 'deposits');

        // The money counts once: 1000 in by UPI, 400 back in cash; paying the bill from the deposit adds nothing.
        $this->getJson('/api/reports/summary')->assertOk()
            ->assertJsonPath('departments.consultation.collected.upi', 1000)->assertJsonPath('departments.consultation.collected.cash', -400)
            ->assertJsonPath('departments.consultation.collected_total', 600)->assertJsonPath('departments.consultation.billed', 600);
        $this->getJson('/api/front-desk/summary')->assertOk()->assertJsonPath('deposits.received', 1000)->assertJsonPath('deposits.used', 600)->assertJsonPath('bills.all.collected', 0);
        $collections = $this->getJson('/api/reports/list/collections')->assertOk()->json();
        $this->assertCount(2, $collections['rows']);
        $this->assertEquals(600, $collections['totals']['amount']);

        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->postJson($deposits, ['type' => 'deposit', 'amount' => 100, 'mode' => 'cash'])->assertForbidden();
    }

    public function test_a_temporary_patient_is_booked_with_a_name_and_number_and_completed_later(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->postJson('/api/patients/temporary', ['name' => 'Sample Caller'])->assertStatus(422);
        $patient = $this->postJson('/api/patients/temporary', ['name' => 'Sample Caller', 'mobile' => '9000000009'])->assertCreated()
            ->assertJsonPath('is_temporary', true)->assertJsonPath('age', null)->assertJsonPath('age_label', '—')->json();
        $this->assertNotEmpty($patient['uhid']);

        // They can be booked and appear at the desk like anyone else.
        $doctor = User::factory()->create(['role' => 'doctor']);
        $this->postJson('/api/appointments', ['patient_id' => $patient['id'], 'doctor_id' => $doctor->id, 'scheduled_at' => now()->addDay()->format('Y-m-d 11:00')])->assertCreated()->assertJsonPath('patient.age_label', '—');
        $this->getJson('/api/patients?q=Sample Caller')->assertOk()->assertJsonPath('data.0.is_temporary', true);

        // Filling in the registration makes them a full patient.
        $this->putJson("/api/patients/{$patient['id']}", ['name' => 'Sample Caller', 'gender' => 'male', 'age' => 40, 'mobile' => '9000000009'])
            ->assertOk()->assertJsonPath('is_temporary', false)->assertJsonPath('age', 40);
    }

    public function test_all_bills_can_be_listed_filtered_and_searched(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $doctor = User::factory()->create(['role' => 'doctor']);
        $service = Service::factory()->create(['price' => 300]);
        $first = Patient::factory()->create(['name' => 'Asha Sample']);
        $second = Patient::factory()->create(['name' => 'Bala Sample']);
        $paid = $this->postJson('/api/appointments', ['patient_id' => $first->id, 'doctor_id' => $doctor->id, 'service_id' => $service->id, 'scheduled_at' => now()->format('Y-m-d H:i'), 'paid' => 300, 'payment_mode' => 'cash'])->json('bills.0');
        $this->postJson('/api/appointments', ['patient_id' => $second->id, 'doctor_id' => $doctor->id, 'service_id' => $service->id, 'scheduled_at' => now()->format('Y-m-d H:i')])->assertCreated();
        $this->postJson('/api/bills', ['patient_id' => $second->id, 'description' => 'Dressing', 'unit_price' => 150])->assertCreated();

        $this->getJson('/api/bills')->assertOk()->assertJsonCount(3)->assertJsonStructure([['bill_no', 'total', 'paid', 'due', 'kind', 'patient' => ['name']]]);
        $this->getJson('/api/bills?kind=other')->assertJsonCount(1);
        $this->getJson('/api/bills?due=1')->assertJsonCount(2);
        $this->getJson('/api/bills?q=Asha')->assertJsonCount(1)->assertJsonPath('0.bill_no', $paid['bill_no']);
        $this->getJson('/api/bills?q='.$paid['bill_no'])->assertJsonCount(1);
        $this->getJson('/api/bills?from='.now()->subDays(3)->toDateString().'&to='.now()->subDay()->toDateString())->assertJsonCount(0);
    }
}
