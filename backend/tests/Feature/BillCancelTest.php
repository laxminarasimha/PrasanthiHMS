<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\DayClose;
use App\Models\LabOrder;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillCancelTest extends TestCase
{
    use RefreshDatabase;

    private Patient $patient;

    private Service $ecg;

    private LabTest $cbp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->patient = Patient::factory()->create();
        $this->ecg = Service::create(['name' => 'ECG', 'type' => 'other', 'price' => 300, 'is_active' => true]);
        $this->cbp = LabTest::create(['name' => 'CBP', 'price' => 350]);
        $this->cbp->parameters()->create(['name' => 'Haemoglobin']);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function bill(array $what): Bill
    {
        $made = $this->postJson('/api/front-desk/charges', ['patient_id' => $this->patient->id] + $what)->assertCreated()->json('made');

        return Bill::findOrFail($made[0]);
    }

    public function test_a_wrong_bill_is_cancelled_refunded_and_counts_for_nothing(): void
    {
        $bill = $this->bill(['services' => [['service_id' => $this->ecg->id]]]);
        $this->postJson("/api/bills/{$bill->id}/payments", ['type' => 'payment', 'amount' => 120, 'mode' => 'upi'])->assertCreated();

        // The desk cannot cancel a bill unless it has been trusted to.
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->postJson("/api/bills/{$bill->id}/cancel", ['reason' => 'Wrong patient', 'refund_mode' => 'upi'])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'name' => 'Owner']));
        $this->postJson("/api/bills/{$bill->id}/cancel", ['refund_mode' => 'upi'])->assertStatus(422)->assertJsonValidationErrors('reason');
        // Money was taken, so how it goes back has to be said.
        $this->postJson("/api/bills/{$bill->id}/cancel", ['reason' => 'Wrong patient'])->assertStatus(422)->assertJsonValidationErrors('refund_mode');

        $this->postJson("/api/bills/{$bill->id}/cancel", ['reason' => 'Wrong patient', 'refund_mode' => 'upi'])->assertOk()
            ->assertJsonPath('total', 0)->assertJsonPath('paid', 0)->assertJsonPath('due', 0)
            ->assertJsonPath('cancel_reason', 'Wrong patient')->assertJsonPath('canceller.name', 'Owner')
            ->assertJsonCount(1, 'items');
        $refund = Payment::latest('id')->first();
        $this->assertSame(['refund', 120.0, 'upi'], [$refund->type, (float) $refund->amount, $refund->mode]);
        $this->assertTrue(AuditLog::where('action', 'bill.cancelled')->where('patient_id', $this->patient->id)->exists());

        // The day's figures no longer count it: nothing billed, and what came in went back out.
        $this->getJson('/api/reports/summary')->assertOk()->assertJsonPath('departments.consultation.billed', 0)->assertJsonPath('departments.consultation.collected_total', 0);
        $this->getJson('/api/front-desk/bills')->assertOk()->assertJsonCount(0);
        $this->getJson("/api/front-desk/day-bills?patient_id={$this->patient->id}")->assertOk()->assertJsonPath('total', 0)->assertJsonPath('bills.0.cancel_reason', 'Wrong patient');
        $this->assertCount(0, $this->getJson('/api/reports/list/bills')->assertOk()->json('rows'));

        // A cancelled bill is finished: no more money, lines or a second cancelling.
        $this->postJson("/api/bills/{$bill->id}/payments", ['type' => 'payment', 'amount' => 10, 'mode' => 'cash'])->assertStatus(422);
        $this->postJson("/api/bills/{$bill->id}/items", ['service_id' => $this->ecg->id])->assertStatus(422);
        $this->postJson("/api/bills/{$bill->id}/cancel", ['reason' => 'Again'])->assertStatus(422);
    }

    public function test_a_lab_bill_takes_its_untouched_order_with_it_but_not_one_the_lab_has_started(): void
    {
        $untouched = $this->bill(['test_ids' => [$this->cbp->id]]);
        $started = $this->bill(['test_ids' => [$this->cbp->id]]);
        $order = LabOrder::where('bill_id', $started->id)->firstOrFail();
        $this->putJson("/api/lab/orders/{$order->id}/collect", ['all' => true])->assertOk();

        $this->postJson("/api/bills/{$started->id}/cancel", ['reason' => 'Ordered twice'])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertNull($started->fresh()->cancelled_at);

        $this->postJson("/api/bills/{$untouched->id}/cancel", ['reason' => 'Ordered twice'])->assertOk()->assertJsonPath('total', 0);
        $this->assertSame(0, LabOrder::where('bill_id', $untouched->id)->count());
        $this->assertSame(1, LabOrder::count());
    }

    public function test_a_bill_paid_from_a_deposit_or_of_a_closed_day_is_not_cancelled(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $visit = Appointment::create(['patient_id' => $this->patient->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now()->format('Y-m-d H:i'), 'status' => 'arrived', 'token_no' => 1]);
        $bill = $this->bill(['appointment_id' => $visit->id, 'services' => [['service_id' => $this->ecg->id]]]);
        $this->postJson("/api/patients/{$this->patient->id}/deposits", ['type' => 'deposit', 'amount' => 500, 'mode' => 'cash'])->assertSuccessful();
        $this->postJson("/api/bills/{$bill->id}/use-deposit", ['amount' => 100])->assertCreated();
        $this->postJson("/api/bills/{$bill->id}/cancel", ['reason' => 'Wrong service', 'refund_mode' => 'cash'])->assertStatus(422)->assertJsonValidationErrors('reason');

        // The visit's list no longer carries a bill once it is cancelled, so another can be made for it.
        $other = $this->bill(['appointment_id' => $visit->id, 'services' => [['service_id' => $this->ecg->id]]]);
        $this->postJson("/api/bills/{$other->id}/cancel", ['reason' => 'Entered twice'])->assertOk();
        $this->assertSame([$bill->id], collect($this->getJson('/api/appointments')->assertOk()->json('data.0.bills'))->pluck('id')->all());

        // Once the day is closed its bills are left as they were.
        $third = $this->bill(['services' => [['service_id' => $this->ecg->id]]]);
        DayClose::create(['day' => today()->toDateString(), 'closed_by_name' => 'Admin', 'closed_at' => now(), 'expected_cash' => 0, 'counted_cash' => 0, 'variance' => 0, 'snapshot' => []]);
        $this->postJson("/api/bills/{$third->id}/cancel", ['reason' => 'Entered twice'])->assertStatus(423);
        $this->assertNull($third->fresh()->cancelled_at);
    }
}
