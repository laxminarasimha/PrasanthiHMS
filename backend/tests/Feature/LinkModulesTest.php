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

    public function test_ward_orders_and_discharge_medicines_reach_the_pharmacy_and_the_follow_up_reaches_the_desk(): void
    {
        $bed = Bed::create(['ward' => 'General Ward', 'bed_no' => '4', 'daily_charge' => 800]);
        $case = $this->postJson('/api/ipd/admissions', ['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'bed_id' => $bed->id, 'admitted_at' => now()->subDays(3)->format('Y-m-d H:i')])->assertCreated()->json();
        $url = "/api/ipd/admissions/{$case['id']}";
        $batch = $this->stock('Inj Ceftriaxone 1g');

        // The doctor orders on the ward: one medicine the pharmacy stocks under that name, one it does not, and a diet (not a medicine).
        $this->travelTo(now()->subHours(3));
        $this->postJson("{$url}/chart/orders", ['kind' => 'medicine', 'title' => 'Inj Ceftriaxone 1g', 'dose' => '1 g', 'route' => 'IV', 'frequency' => '12 hourly'])->assertCreated();
        $stopped = collect($this->postJson("{$url}/chart/orders", ['kind' => 'medicine', 'title' => 'Inj Pantoprazole 40mg'])->assertCreated()->json('orders'))->firstWhere('title', 'Inj Pantoprazole 40mg')['id'];
        $this->postJson("{$url}/chart/orders", ['kind' => 'medicine', 'title' => 'Syp Lactulose'])->assertCreated();
        $this->postJson("{$url}/chart/orders", ['kind' => 'instruction', 'title' => 'Soft diet'])->assertCreated();
        $this->postJson("{$url}/chart/orders/{$stopped}/stop")->assertOk();
        $this->travelBack();

        // The pharmacy sees the running orders: on the inpatient picker, on To do, and as a card at the counter.
        Sanctum::actingAs($pharmacist = User::factory()->create(['role' => 'pharmacist']));
        $this->getJson('/api/pharmacy/inpatients')->assertOk()->assertJsonPath('0.orders_waiting', 2);
        $line = collect(collect($this->getJson('/api/todo')->assertOk()->json('waiting'))->firstWhere('key', 'pharmacy')['items'])->firstWhere('key', 'ward_orders');
        $this->assertSame(2, $line['count']);
        $this->assertTrue($line['urgent']);
        $this->assertSame('/pharmacy?patient='.$this->patient->id, $line['rows'][0]['to']);
        $this->assertSame('Inj Ceftriaxone 1g, Syp Lactulose', $line['rows'][0]['detail']);
        $card = $this->getJson('/api/pharmacy/prescriptions?patient_id='.$this->patient->id)->assertOk()->assertJsonCount(1)->assertJsonPath('0.kind', 'ward')->assertJsonPath('0.doctor', $this->doctor->name)->json('0');
        $this->assertSame(['Inj Ceftriaxone 1g', 'Syp Lactulose'], array_column($card['medicines'], 'medicine'));
        $this->assertSame('1 g · IV · 12 hourly', $card['medicines'][0]['directions']);
        $this->assertSame($batch->id, $card['medicines'][0]['match']['sellable_batches'][0]['id']);
        // A name the pharmacy does not stock exactly is never guessed.
        $this->assertNull($card['medicines'][1]['match']);
        $this->assertTrue($card['medicines'][0]['new']);

        // Once a pharmacy bill is made for the patient, nothing is waiting; the card stays, no longer marked new.
        $this->postJson('/api/pharmacy/sales', ['patient_id' => $this->patient->id, 'customer_name' => $this->patient->name, 'admission_id' => $case['id'], 'paid' => 0, 'payment_mode' => 'cash',
            'items' => [['stock_batch_id' => $batch->id, 'quantity' => 2]]])->assertCreated()->assertJsonPath('ipd_transfer', 10);
        $this->getJson('/api/pharmacy/inpatients')->assertJsonPath('0.orders_waiting', 0);
        $this->assertNull(collect(collect($this->getJson('/api/todo')->json('waiting'))->firstWhere('key', 'pharmacy')['items'] ?? [])->firstWhere('key', 'ward_orders'));
        $this->getJson('/api/pharmacy/prescriptions?patient_id='.$this->patient->id)->assertJsonPath('0.medicines.0.new', false);

        // The discharge summary: its medicines are offered at the counter even before it is approved.
        Sanctum::actingAs($this->doctor);
        $due = today()->subDays(2)->toDateString();
        $this->putJson("{$url}/summary", ['diagnosis' => 'Enteric fever', 'medicines' => [['medicine' => 'Cefixime 200mg', 'frequency' => '1-0-1', 'duration' => '5 days']], 'follow_up_on' => $due])->assertOk();
        $this->stock('Cefixime 200mg');
        Sanctum::actingAs($pharmacist);
        $cards = $this->getJson('/api/pharmacy/prescriptions?patient_id='.$this->patient->id)->assertOk()->assertJsonCount(2)->json();
        $home = collect($cards)->firstWhere('kind', 'discharge');
        $this->assertSame('Cefixime 200mg', $home['medicines'][0]['medicine']);
        $this->assertSame('1-0-1 · 5 days', $home['medicines'][0]['directions']);
        $this->assertNotNull($home['medicines'][0]['match']);
        $this->assertStringContainsString('not approved yet', $home['note']);

        // Still in the bed, the follow-up date on the summary is on nobody's list.
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson('/api/front-desk/follow-ups?date='.$due)->assertOk()->assertJsonCount(0);
        $this->getJson('/api/front-desk/not-returned')->assertOk()->assertJsonCount(0);

        // Gone home three days ago, due back two days ago, not come: on the day's list and on "not come back".
        Admission::whereKey($case['id'])->update(['status' => 'discharged', 'discharged_at' => now()->subDays(3)]);
        $this->getJson('/api/front-desk/follow-ups?date='.$due)->assertJsonCount(1)->assertJsonPath('0.source', 'discharge')->assertJsonPath('0.patient.id', $this->patient->id)
            ->assertJsonPath('0.doctor.id', $this->doctor->id)->assertJsonPath('0.booked', false)->assertJsonPath('0.follow_up_on', $due);
        $this->getJson('/api/front-desk/not-returned')->assertJsonCount(1)->assertJsonPath('0.source', 'discharge')->assertJsonPath('0.days_late', 2)->assertJsonPath('0.diagnosis', 'Enteric fever');
        $desk = collect($this->getJson('/api/todo')->json('waiting'))->firstWhere('key', 'front_desk');
        $this->assertSame(1, collect($desk['items'])->firstWhere('key', 'follow_ups')['count']);
        // The ward card has gone with the stay; the take-home medicines are still offered.
        Sanctum::actingAs($pharmacist);
        $this->getJson('/api/pharmacy/prescriptions?patient_id='.$this->patient->id)->assertJsonCount(1)->assertJsonPath('0.kind', 'discharge');

        // They come: no longer chased. A patient who died is never listed.
        Appointment::create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'scheduled_at' => now()->subDay(), 'status' => 'reviewed', 'arrived_at' => now()->subDay()]);
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson('/api/front-desk/not-returned')->assertJsonCount(0);
        Appointment::query()->delete();
        $this->getJson('/api/front-desk/not-returned')->assertJsonCount(1);
        Admission::whereKey($case['id'])->update(['status' => 'died']);
        $this->getJson('/api/front-desk/not-returned')->assertJsonCount(0);
        $this->getJson('/api/front-desk/follow-ups?date='.$due)->assertJsonCount(0);
    }
}
