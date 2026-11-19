<?php

namespace Tests\Feature;

use App\Models\Admission;
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
        $this->getJson('/api/lab/orders')->assertOk()->assertJsonPath('0.bill.due', 0)->assertJsonPath('0.bill.moved_to_ipd', 200);
        $this->postJson("{$url}/transfer", ['type' => 'bill', 'id' => $labBill])->assertStatus(422);

        // The transfer is not money received: lab collections stay at the 100 paid in cash.
        $this->getJson('/api/reports/summary')->assertOk()->assertJsonPath('departments.lab.collected_total', 100);

        // Removing the IPD charge gives the amount back to the lab bill.
        $this->deleteJson("{$url}/charges/{$charges[0]['id']}")->assertOk()->assertJsonPath('total', 50);
        $this->assertEquals(200, Bill::find($labBill)->due);
    }

    public function test_medicines_returned_from_a_bill_on_the_ipd_bill_come_off_the_ipd_bill(): void
    {
        $admission = $this->postJson('/api/ipd/admissions', [
            'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'bed_id' => Bed::create(['ward' => 'General', 'bed_no' => '1'])->id,
            'admitted_at' => now()->subHour()->format('Y-m-d H:i'),
        ])->json('id');
        $url = "/api/ipd/admissions/{$admission}";
        $batch = $this->stock('Cetirizine 10mg Tablet');

        // Ten tablets for 50: 20 paid at the counter, the other 30 put on the IPD bill.
        $sale = $this->postJson('/api/pharmacy/sales', [
            'patient_id' => $this->patient->id, 'customer_name' => $this->patient->name, 'paid' => 20, 'payment_mode' => 'cash',
            'items' => [['stock_batch_id' => $batch->id, 'quantity' => 10]],
        ])->assertCreated()->json();
        $charge = $this->postJson("{$url}/transfer", ['type' => 'pharmacy', 'id' => $sale['id']])->assertCreated()->assertJsonPath('total', 30)->json('charges.0.id');

        // Four tablets (20) come back: nothing was overpaid at the counter, so the 20 comes off the IPD bill and no money is handed back.
        $this->postJson("/api/pharmacy/sales/{$sale['id']}/returns", ['refund_mode' => 'cash', 'items' => [['id' => $sale['items'][0]['id'], 'quantity' => 4]]])
            ->assertCreated()->assertJsonPath('returned', 20)->assertJsonPath('ipd_transfer', 10)->assertJsonPath('paid', 20)->assertJsonPath('returns.0.refunded', 0);
        $case = $this->getJson($url)->assertOk()->assertJsonPath('total', 10)->json();
        $credit = collect($case['charges'])->firstWhere('source_type', 'pharmacy_return');
        $this->assertEquals(-20, $credit['amount']);
        $this->assertStringContainsString($sale['bill_no'], $credit['description']);
        $this->assertSame(44, $batch->fresh()->units_in_stock);

        // The rest comes back too: the last 10 leaves the IPD bill, and the 20 paid in cash is refunded.
        $this->postJson("/api/pharmacy/sales/{$sale['id']}/returns", ['refund_mode' => 'cash', 'items' => [['id' => $sale['items'][0]['id'], 'quantity' => 6]]])
            ->assertCreated()->assertJsonPath('ipd_transfer', 0)->assertJsonPath('paid', 0);
        $this->getJson($url)->assertOk()->assertJsonPath('total', 0);

        // The return lines cannot be removed on their own; they go with the pharmacy bill's line.
        $this->deleteJson("{$url}/charges/{$credit['id']}")->assertStatus(422)->assertJsonValidationErrors('charge');
        $this->deleteJson("{$url}/charges/{$charge}")->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'charges');
        $this->assertEquals(0, PharmacySale::find($sale['id'])->due);
    }

    public function test_the_counter_bills_an_inpatients_medicines_straight_to_the_ipd_bill(): void
    {
        $admission = $this->postJson('/api/ipd/admissions', [
            'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'bed_id' => Bed::create(['ward' => 'General', 'bed_no' => '1'])->id,
            'admitted_at' => now()->subHour()->format('Y-m-d H:i'),
        ])->json('id');
        $url = "/api/ipd/admissions/{$admission}";
        $cetirizine = $this->stock('Cetirizine 10mg Tablet');
        $pantoprazole = $this->stock('Pantoprazole 40mg Tablet');

        // The counter sees who is admitted, and where.
        $this->getJson('/api/pharmacy/inpatients')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.admission_id', $admission)->assertJsonPath('0.patient.id', $this->patient->id)->assertJsonPath('0.bed', 'General · Bed 1')->assertJsonPath('0.bill_approved', false);

        // Ten of one medicine and four of another for the ward, nothing paid at the counter: 50 + 20 = 70 on the IPD bill at once.
        $sale = $this->postJson('/api/pharmacy/sales', [
            'patient_id' => $this->patient->id, 'customer_name' => $this->patient->name, 'paid' => 0, 'payment_mode' => 'cash', 'admission_id' => $admission,
            'items' => [['stock_batch_id' => $cetirizine->id, 'quantity' => 10], ['stock_batch_id' => $pantoprazole->id, 'quantity' => 4]],
        ])->assertCreated()->assertJsonPath('total', 70)->assertJsonPath('ipd_transfer', 70)->assertJsonPath('due', 0)->assertJsonPath('paid', 0)->json();

        // One line of money on the IPD bill, with each medicine listed under it.
        $case = $this->getJson($url)->assertOk()->assertJsonPath('total', 70)->assertJsonCount(1, 'charges')->json();
        $line = $case['charges'][0];
        $this->assertCount(2, $line['medicines']);

        // Another charge dated before it sits above it on the bill; the medicines are still listed under their own line.
        $this->postJson("{$url}/charges", ['description' => 'Dressing', 'charged_on' => today()->subDay()->toDateString(), 'quantity' => 1, 'unit_price' => 0])->assertCreated();
        $charges = collect($this->getJson($url)->assertOk()->json('charges'));
        $this->assertSame('Dressing', $charges->first()['description']);
        $this->assertArrayNotHasKey('medicines', $charges->firstWhere('description', 'Dressing'));
        $this->assertCount(2, $charges->firstWhere('id', $line['id'])['medicines']);
        $this->assertSame("Pharmacy bill {$sale['bill_no']}", $line['description']);
        $this->assertSame('pharmacy', $line['source_type']);
        $this->assertSame(['Cetirizine 10mg Tablet', 'Pantoprazole 40mg Tablet'], array_column($line['medicines'], 'name'));
        $this->assertEquals([10, 4], array_column($line['medicines'], 'quantity'));
        $this->assertEquals([50, 20], array_column($line['medicines'], 'amount'));
        $this->getJson("{$url}/outstanding")->assertOk()->assertJsonCount(0);
        // It is not money received at the pharmacy.
        $this->getJson('/api/reports/summary')->assertOk()->assertJsonPath('departments.pharmacy.collected_total', 0);

        // Part paid at the counter: only the rest goes on the IPD bill.
        $this->postJson('/api/pharmacy/sales', [
            'patient_id' => $this->patient->id, 'customer_name' => $this->patient->name, 'paid' => 20, 'payment_mode' => 'cash', 'admission_id' => $admission,
            'items' => [['stock_batch_id' => $cetirizine->id, 'quantity' => 10]],
        ])->assertCreated()->assertJsonPath('paid', 20)->assertJsonPath('ipd_transfer', 30)->assertJsonPath('due', 0);
        $this->getJson($url)->assertJsonPath('total', 100)->assertJsonCount(3, 'charges');

        // Medicines taken back show under the line, and come off the IPD bill as before.
        $this->postJson("/api/pharmacy/sales/{$sale['id']}/returns", ['refund_mode' => 'cash', 'items' => [['id' => $sale['items'][0]['id'], 'quantity' => 4]]])->assertCreated();
        $after = $this->getJson($url)->assertJsonPath('total', 80)->json('charges');
        $this->assertSame(4, collect($after)->firstWhere('id', $line['id'])['medicines'][0]['returned']);

        // Not onto another patient's case, not onto an approved bill, and not after the patient has left: nothing is sold in those cases.
        $other = Patient::factory()->create();
        $try = fn (array $with) => $this->postJson('/api/pharmacy/sales', $with + [
            'patient_id' => $this->patient->id, 'customer_name' => 'X', 'paid' => 0, 'payment_mode' => 'cash', 'admission_id' => $admission,
            'items' => [['stock_batch_id' => $pantoprazole->id, 'quantity' => 1]],
        ]);
        $try(['patient_id' => $other->id])->assertStatus(422)->assertJsonValidationErrors('admission_id');
        Admission::whereKey($admission)->update(['bill_approved_at' => now()]);
        $try([])->assertStatus(422)->assertJsonValidationErrors('admission_id');
        Admission::whereKey($admission)->update(['bill_approved_at' => null, 'status' => 'discharged', 'discharged_at' => now()]);
        $try([])->assertStatus(422)->assertJsonValidationErrors('admission_id');
        $this->assertSame(46, $pantoprazole->fresh()->units_in_stock);
        $this->getJson('/api/pharmacy/inpatients')->assertOk()->assertJsonCount(0);
    }

    public function test_a_return_is_refused_while_the_ipd_bill_it_sits_on_is_approved(): void
    {
        $admission = $this->postJson('/api/ipd/admissions', [
            'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'bed_id' => Bed::create(['ward' => 'General', 'bed_no' => '1'])->id,
            'admitted_at' => now()->subHour()->format('Y-m-d H:i'),
        ])->json('id');
        $batch = $this->stock('Cetirizine 10mg Tablet');
        $sale = $this->postJson('/api/pharmacy/sales', [
            'patient_id' => $this->patient->id, 'customer_name' => $this->patient->name, 'paid' => 0, 'payment_mode' => 'cash',
            'items' => [['stock_batch_id' => $batch->id, 'quantity' => 10]],
        ])->assertCreated()->json();
        $this->postJson("/api/ipd/admissions/{$admission}/transfer", ['type' => 'pharmacy', 'id' => $sale['id']])->assertCreated();
        Admission::whereKey($admission)->update(['bill_approved_at' => now()]);

        $this->postJson("/api/pharmacy/sales/{$sale['id']}/returns", ['refund_mode' => 'cash', 'items' => [['id' => $sale['items'][0]['id'], 'quantity' => 4]]])
            ->assertStatus(422)->assertJsonValidationErrors('items');
        $this->assertSame(40, $batch->fresh()->units_in_stock);
        $this->assertEquals(50, PharmacySale::find($sale['id'])->ipd_transfer);
    }
}
