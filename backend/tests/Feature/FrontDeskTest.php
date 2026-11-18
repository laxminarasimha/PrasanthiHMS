<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\DayClose;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\Payment;
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
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/services', ['name' => 'ECG', 'type' => 'other', 'price' => 300, 'gst_percent' => 5])
            ->assertCreated()->assertJsonPath('price', 300);

        $this->getJson('/api/services')->assertOk()->assertJsonCount(2);
        $this->getJson('/api/doctors')->assertOk()->assertJsonCount(1);

        // The price list is read-only for front desk staff.
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->postJson('/api/services', ['name' => 'X-ray', 'type' => 'other', 'price' => 400])->assertForbidden();
        $this->getJson('/api/services')->assertOk()->assertJsonCount(2);
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

    public function test_a_payment_entered_under_the_wrong_mode_can_be_corrected_with_a_reason(): void
    {
        $billId = $this->book()['bills'][0]['id'];
        $bill = $this->postJson("/api/bills/{$billId}/payments", ['type' => 'payment', 'amount' => 200, 'mode' => 'cash'])->assertCreated()->json();
        $payment = $bill['payments'][0]['id'];
        $url = "/api/bills/{$billId}/payments/{$payment}";

        $this->putJson($url, ['mode' => 'upi'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->putJson($url, ['mode' => 'cash', 'reason' => 'Wrong mode'])->assertStatus(422)->assertJsonValidationErrors('mode'); // nothing to change
        $this->putJson($url, ['mode' => 'deposit', 'reason' => 'Wrong mode'])->assertStatus(422)->assertJsonValidationErrors('mode');

        // Only the mode moves: the amount, what is paid and what is due stay as they were.
        $this->putJson($url, ['mode' => 'upi', 'reference' => 'UPI 7788', 'reason' => 'Paid by GPay, entered as cash'])->assertOk()
            ->assertJsonPath('payments.0.mode', 'upi')->assertJsonPath('payments.0.reference', 'UPI 7788')->assertJsonPath('payments.0.amount', 200)
            ->assertJsonPath('paid', 200)->assertJsonPath('due', 0);
        $log = AuditLog::where('action', 'bill.payment_mode')->sole();
        $this->assertStringContainsString('changed from cash to upi (UPI 7788). Reason: Paid by GPay, entered as cash', $log->summary);

        // Not through another bill, not money that came from a deposit, and not by someone who does not handle bills.
        $other = $this->book(['patient_id' => Patient::factory()->create()->id])['bills'][0]['id'];
        $this->putJson("/api/bills/{$other}/payments/{$payment}", ['mode' => 'card', 'reason' => 'Wrong bill'])->assertNotFound();
        Payment::whereKey($payment)->update(['mode' => 'deposit']);
        $this->putJson($url, ['mode' => 'cash', 'reason' => 'Was a deposit'])->assertStatus(422)->assertJsonValidationErrors('mode');
        Payment::whereKey($payment)->update(['mode' => 'upi']);
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->putJson($url, ['mode' => 'cash', 'reason' => 'Not my desk'])->assertForbidden();

        // Once the day's accounts are closed, its cash and bank totals cannot be moved.
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        DayClose::create(['day' => today()->toDateString(), 'closed_by_name' => 'Admin', 'closed_at' => now(), 'expected_cash' => 0, 'counted_cash' => 0, 'variance' => 0, 'snapshot' => []]);
        $this->putJson($url, ['mode' => 'cash', 'reason' => 'Back to cash'])->assertStatus(423);
    }

    public function test_only_front_desk_staff_change_bills_and_lab_staff_only_lab_bills(): void
    {
        $bill = $this->book()['bills'][0];
        $url = "/api/bills/{$bill['id']}";
        $this->postJson("{$url}/payments", ['type' => 'payment', 'amount' => 200, 'mode' => 'cash'])->assertCreated();
        $item = $this->getJson($url)->json('items.0.id');
        $labTest = LabTest::create(['name' => 'CBP', 'price' => 300]);
        $labBill = $this->postJson('/api/lab/orders', ['patient_id' => $this->patient->id, 'test_ids' => [$labTest->id]])->assertCreated()->json('bill.id');

        // A pharmacist is signed in, but bills are not their work: nothing on the bill moves.
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->postJson('/api/bills', ['patient_id' => $this->patient->id, 'description' => 'Dressing', 'unit_price' => 100])->assertForbidden();
        $this->postJson("{$url}/payments", ['type' => 'refund', 'amount' => 200, 'mode' => 'cash'])->assertForbidden();
        $this->putJson($url, ['discount' => 200, 'refund_excess' => true, 'refund_mode' => 'cash'])->assertForbidden();
        $this->postJson("{$url}/items", ['description' => 'Dressing', 'unit_price' => 100])->assertForbidden();
        $this->deleteJson("{$url}/items/{$item}")->assertForbidden();
        $this->postJson("/api/bills/{$labBill}/payments", ['type' => 'payment', 'amount' => 300, 'mode' => 'cash'])->assertForbidden();
        $this->assertEquals(200, Bill::find($bill['id'])->paid);
        $this->assertEquals(0, Bill::find($bill['id'])->discount);

        // Lab staff take money on lab bills, and leave front desk bills alone.
        Sanctum::actingAs(User::factory()->create(['role' => 'lab_tech']));
        $this->postJson("/api/bills/{$labBill}/payments", ['type' => 'payment', 'amount' => 300, 'mode' => 'cash'])->assertCreated()->assertJsonPath('due', 0);
        $this->postJson("{$url}/payments", ['type' => 'refund', 'amount' => 200, 'mode' => 'cash'])->assertForbidden();
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
