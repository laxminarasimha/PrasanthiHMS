<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FrontDeskTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private Patient $patient;

    private Service $consultation;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->doctor = User::factory()->create(['role' => 'doctor']);
        $this->patient = Patient::factory()->create();
        $this->consultation = Service::factory()->create(['price' => 200]);
    }

    private function book(array $overrides = []): array
    {
        return $this->postJson('/api/appointments', $overrides + [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'service_id' => $this->consultation->id,
            'scheduled_at' => now()->format('Y-m-d').' 10:30',
        ])->assertCreated()->json();
    }

    public function test_services_can_be_added_and_listed(): void
    {
        $this->postJson('/api/services', ['name' => 'ECG', 'type' => 'other', 'price' => 300, 'gst_percent' => 5])
            ->assertCreated()->assertJsonPath('price', 300);

        $this->getJson('/api/services')->assertOk()->assertJsonCount(2);
        $this->getJson('/api/doctors')->assertOk()->assertJsonCount(1);
    }

    public function test_booking_an_appointment_opens_a_bill_for_the_service(): void
    {
        $appointment = $this->book();

        $this->assertSame('booked', $appointment['status']);
        $this->assertEquals(200, $appointment['bills'][0]['total']);
        $this->assertEquals(0, $appointment['bills'][0]['paid']);
    }

    public function test_appointment_must_be_with_an_active_doctor(): void
    {
        $nurse = User::factory()->create(['role' => 'nurse']);

        $this->postJson('/api/appointments', [
            'patient_id' => $this->patient->id, 'doctor_id' => $nurse->id, 'scheduled_at' => now()->toDateTimeString(),
        ])->assertStatus(422)->assertJsonValidationErrors('doctor_id');
    }

    public function test_day_list_filters_by_date_and_status_and_counts_each_status(): void
    {
        $first = $this->book();
        $this->book();
        $this->book(['scheduled_at' => now()->addDay()->format('Y-m-d').' 09:00']);

        $this->putJson("/api/appointments/{$first['id']}", ['status' => 'arrived'])
            ->assertOk()->assertJsonPath('status', 'arrived');

        $this->getJson('/api/appointments')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('counts.arrived', 1)
            ->assertJsonPath('counts.booked', 1);
        $this->getJson('/api/appointments?status=arrived')->assertJsonCount(1, 'data');
        $this->getJson('/api/appointments?date='.now()->addDay()->toDateString())->assertJsonCount(1, 'data');
    }

    public function test_bill_totals_follow_items_discount_and_payments(): void
    {
        $billId = $this->book()['bills'][0]['id'];
        $ecg = Service::factory()->create(['name' => 'ECG', 'type' => 'other', 'price' => 300, 'gst_percent' => 10]);

        // 200 + (2 x 300) + 10% GST on the ECGs = 860
        $this->postJson("/api/bills/{$billId}/items", ['service_id' => $ecg->id, 'quantity' => 2])
            ->assertCreated()->assertJsonPath('subtotal', 800)->assertJsonPath('tax', 60)->assertJsonPath('total', 860);

        $this->putJson("/api/bills/{$billId}", ['discount' => 60])->assertOk()->assertJsonPath('total', 800);

        $this->postJson("/api/bills/{$billId}/payments", ['type' => 'payment', 'amount' => 500, 'mode' => 'cash'])
            ->assertCreated()->assertJsonPath('paid', 500)->assertJsonPath('due', 300);

        $this->postJson("/api/bills/{$billId}/payments", ['type' => 'refund', 'amount' => 100, 'mode' => 'cash'])
            ->assertCreated()->assertJsonPath('paid', 400)->assertJsonPath('due', 400);
    }

    public function test_overpayment_and_excess_refund_and_discount_are_rejected(): void
    {
        $billId = $this->book()['bills'][0]['id'];

        $this->postJson("/api/bills/{$billId}/payments", ['type' => 'payment', 'amount' => 250, 'mode' => 'upi'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson("/api/bills/{$billId}/payments", ['type' => 'refund', 'amount' => 50, 'mode' => 'cash'])
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->putJson("/api/bills/{$billId}", ['discount' => 500])
            ->assertStatus(422)->assertJsonValidationErrors('discount');
    }

    public function test_paid_item_cannot_be_removed_until_refunded(): void
    {
        $bill = $this->getJson('/api/bills/'.$this->book()['bills'][0]['id'])->json();
        $itemId = $bill['items'][0]['id'];

        $this->postJson("/api/bills/{$bill['id']}/payments", ['type' => 'payment', 'amount' => 200, 'mode' => 'card'])->assertCreated();
        $this->deleteJson("/api/bills/{$bill['id']}/items/{$itemId}")->assertStatus(422);

        $this->postJson("/api/bills/{$bill['id']}/payments", ['type' => 'refund', 'amount' => 200, 'mode' => 'card'])->assertCreated();
        $this->deleteJson("/api/bills/{$bill['id']}/items/{$itemId}")->assertOk()->assertJsonPath('total', 0);
    }
}
