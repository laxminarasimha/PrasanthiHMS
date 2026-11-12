<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Bed;
use App\Models\Bill;
use App\Models\LabTest;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\PharmacySale;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LinkModulesTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->doctor = User::factory()->create(['role' => 'doctor']);
        $this->patient = Patient::factory()->create();
        Sanctum::actingAs($this->doctor);
    }

    private function stock(string $name): StockBatch
    {
        return StockBatch::create([
            'medicine_id' => Medicine::create(['name' => $name, 'gst_percent' => 12, 'units_per_pack' => 10])->id,
            'batch_no' => 'B1', 'expiry' => now()->addYear(), 'units_per_pack' => 10, 'packs' => 5, 'pack_price' => 15,
            'pack_mrp' => 50, 'unit_mrp' => 5, 'gst_percent' => 12, 'units_received' => 50, 'units_in_stock' => 50,
        ]);
    }

    private function labOrder(?int $appointmentId = null): array
    {
        $test = LabTest::create(['name' => 'CBP', 'price' => 300]);
        $test->parameters()->create(['name' => 'Haemoglobin', 'unit' => 'g/dL', 'reference_range' => '12 - 16']);

        return $this->postJson('/api/lab/orders', [
            'patient_id' => $this->patient->id, 'appointment_id' => $appointmentId, 'test_ids' => [$test->id], 'referred_by' => $this->doctor->name,
        ])->assertCreated()->json();
    }

    public function test_lab_order_from_a_visit_shows_on_the_front_desk_and_in_the_patients_lab_history(): void
    {
        $appointment = Appointment::create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'scheduled_at' => now(), 'status' => 'arrived']);
        $order = $this->labOrder($appointment->id);
        $item = $order['items'][0];
        $this->putJson("/api/lab/orders/{$order['id']}/items/{$item['id']}", ['complete' => true, 'results' => [['id' => $item['results'][0]['id'], 'value' => '9.5', 'flag' => 'low']]])->assertOk();

        $this->getJson('/api/appointments')->assertOk()
            ->assertJsonPath('data.0.bills.0.lab_order.order_no', $order['order_no'])
            ->assertJsonPath('data.0.bills.0.total', 300);

        $this->getJson("/api/lab/orders?patient_id={$this->patient->id}")->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.status', 'completed')
            ->assertJsonPath('0.items.0.results.0.value', '9.5')
            ->assertJsonPath('0.items.0.results.0.flag', 'low');
    }

    public function test_pharmacy_sees_the_prescription_matched_to_stock_and_suggestions_include_pharmacy_names(): void
    {
        $batch = $this->stock('Paracetamol 650mg Tablet');
        $appointment = Appointment::create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'scheduled_at' => now(), 'status' => 'arrived']);
        $this->putJson("/api/appointments/{$appointment->id}/consultation", ['complete' => true, 'medicines' => [
            ['medicine' => 'paracetamol 650mg tablet', 'frequency' => '1-1-1', 'duration' => '3 days'],
            ['medicine' => 'Something Not Stocked'],
        ]])->assertOk();

        $this->getJson("/api/pharmacy/prescriptions?patient_id={$this->patient->id}")->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.doctor', $this->doctor->name)
            ->assertJsonPath('0.medicines.0.directions', '1-1-1 · 3 days')
            ->assertJsonPath('0.medicines.0.match.sellable_batches.0.id', $batch->id)
            ->assertJsonPath('0.medicines.1.match', null);

        $suggested = $this->getJson('/api/medicines/suggest?q=para')->assertOk()->json();
        $this->assertSame(['Paracetamol 650mg Tablet'], $suggested); // one entry, not the pharmacy name and the prescribed spelling
    }

    public function test_unpaid_lab_and_pharmacy_bills_move_onto_the_ipd_bill_and_back(): void
    {
        $admission = $this->postJson('/api/ipd/admissions', [
            'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'bed_id' => Bed::create(['ward' => 'General', 'bed_no' => '1'])->id,
            'admitted_at' => now()->subHour()->format('Y-m-d H:i'),
        ])->json('id');
        $url = "/api/ipd/admissions/{$admission}";

        $labBill = $this->labOrder()['bill']['id'];
        $this->postJson("/api/bills/{$labBill}/payments", ['type' => 'payment', 'amount' => 100, 'mode' => 'cash'])->assertCreated();
        $sale = $this->postJson('/api/pharmacy/sales', [
            'patient_id' => $this->patient->id, 'customer_name' => $this->patient->name, 'paid' => 0, 'payment_mode' => 'cash',
            'items' => [['stock_batch_id' => $this->stock('Cetirizine 10mg Tablet')->id, 'quantity' => 10]],
        ])->assertCreated()->json('id');

        // 200 left on the lab bill, 50 on the pharmacy bill.
        $this->getJson("{$url}/outstanding")->assertOk()->assertJsonCount(2)
            ->assertJsonPath('0.due', 200)->assertJsonPath('1.due', 50);

        $charges = $this->postJson("{$url}/transfer", ['type' => 'bill', 'id' => $labBill])->assertCreated()->assertJsonPath('total', 200)->json('charges');
        $this->postJson("{$url}/transfer", ['type' => 'pharmacy', 'id' => $sale])->assertCreated()->assertJsonPath('total', 250);

        // Both source bills are settled, nothing is left to move, and moving again is refused.
        $this->assertEquals(0, Bill::find($labBill)->due);
        $this->assertEquals(0, PharmacySale::find($sale)->due);
        $this->getJson("{$url}/outstanding")->assertOk()->assertJsonCount(0);
        $this->postJson("{$url}/transfer", ['type' => 'bill', 'id' => $labBill])->assertStatus(422);

        // The transfer is not money received: lab collections stay at the 100 paid in cash.
        $this->getJson('/api/reports/summary')->assertOk()->assertJsonPath('departments.lab.collected_total', 100);

        // Removing the IPD charge gives the amount back to the lab bill.
        $this->deleteJson("{$url}/charges/{$charges[0]['id']}")->assertOk()->assertJsonPath('total', 50);
        $this->assertEquals(200, Bill::find($labBill)->due);
    }
}
