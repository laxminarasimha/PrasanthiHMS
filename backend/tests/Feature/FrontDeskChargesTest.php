<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Bill;
use App\Models\LabOrder;
use App\Models\LabPackage;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FrontDeskChargesTest extends TestCase
{
    use RefreshDatabase;

    public function test_services_and_lab_tests_are_billed_together_and_paid_in_one_step(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $patient = Patient::factory()->create();
        $other = Patient::factory()->create();
        $visit = Appointment::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now()->format('Y-m-d H:i'), 'status' => 'arrived', 'token_no' => 1]);
        $ecg = Service::create(['name' => 'ECG', 'type' => 'other', 'price' => 300, 'is_active' => true]);
        $dressing = Service::create(['name' => 'Dressing', 'type' => 'other', 'price' => 150, 'is_active' => true]);
        $cbp = LabTest::create(['name' => 'CBP', 'price' => 350]);
        $cbp->parameters()->create(['name' => 'Haemoglobin']);
        $fbs = LabTest::create(['name' => 'FBS', 'price' => 80]);
        $fbs->parameters()->create(['name' => 'Fasting Blood Sugar']);
        $lft = LabTest::create(['name' => 'LFT', 'price' => 600]);
        $lft->parameters()->create(['name' => 'Bilirubin']);
        $package = LabPackage::create(['name' => 'Diabetes check', 'price' => 500, 'is_active' => true]);
        $package->tests()->sync([$fbs->id, $lft->id]);

        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->postJson('/api/front-desk/charges', ['patient_id' => $patient->id, 'services' => [['service_id' => $ecg->id]]])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk', 'permissions' => ['canChangePrice' => false]]));
        $this->postJson('/api/front-desk/charges', ['patient_id' => $patient->id])->assertStatus(422)->assertJsonValidationErrors('services');
        $this->postJson('/api/front-desk/charges', ['patient_id' => $other->id, 'appointment_id' => $visit->id, 'services' => [['service_id' => $ecg->id]]])->assertStatus(422)->assertJsonValidationErrors('appointment_id');

        // A price this person may not change stops the whole thing: no bill, no lab order.
        $this->postJson('/api/front-desk/charges', ['patient_id' => $patient->id, 'appointment_id' => $visit->id, 'services' => [['service_id' => $ecg->id], ['service_id' => $dressing->id, 'unit_price' => 50]], 'test_ids' => [$cbp->id]])
            ->assertStatus(422)->assertJsonValidationErrors('unit_price');
        $this->assertSame([0, 0], [Bill::count(), LabOrder::count()]);

        $saved = $this->postJson('/api/front-desk/charges', [
            'patient_id' => $patient->id, 'appointment_id' => $visit->id, 'referred_by' => 'Dr Rao',
            'services' => [['service_id' => $ecg->id], ['service_id' => $dressing->id, 'quantity' => 2, 'line_discount' => 50], ['description' => 'Injection charge', 'unit_price' => 40]], 'discount_reason' => 'Staff family', 'discount_allowed_by' => 'Dr Rao',
            'test_ids' => [$cbp->id, $fbs->id], 'package_ids' => [$package->id],
        ])->assertCreated()->assertJsonCount(2, 'made')->assertJsonCount(2, 'due')->json();

        // One services bill and one lab bill, as if each had been made on its own screen.
        [$services, $lab] = [Bill::find($saved['made'][0]), Bill::find($saved['made'][1])];
        $this->assertSame(['appointment', 590.0, 'lab', 850.0], [$services->kind, (float) $services->total, $lab->kind, (float) $lab->total]);
        $this->assertSame(['ECG', 'Dressing', 'Injection charge'], $services->items->pluck('description')->all());
        $order = LabOrder::first();
        $this->assertSame([$lab->id, 'Dr Rao', $visit->id, 3], [$order->bill_id, $order->referred_by, $order->appointment_id, $order->items()->count()]);
        // A test inside a chosen package is not charged again.
        $this->assertSame(['Diabetes check (package)', 'CBP'], $lab->items->pluck('description')->all());
        $this->assertEquals([590, 850], array_column($saved['due'], 'due'));
        $this->assertSame($order->order_no, $saved['due'][1]['order_no']);

        // One payment covers both bills, oldest first; each bill keeps its own receipt line.
        $ids = array_column($saved['due'], 'id');
        $pay = ['patient_id' => $patient->id, 'appointment_id' => $visit->id, 'bill_ids' => $ids, 'mode' => 'upi', 'reference' => 'UPI-1'];
        $this->postJson('/api/front-desk/collect', ['amount' => 2000] + $pay)->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson('/api/front-desk/collect', ['patient_id' => $other->id, 'amount' => 100] + $pay)->assertStatus(422)->assertJsonValidationErrors('bill_ids');
        $this->assertSame(0, Payment::count());
        $this->postJson('/api/front-desk/collect', ['amount' => 1000] + $pay)->assertOk()->assertJsonCount(1, 'due')->assertJsonPath('due.0.id', $lab->id)->assertJsonPath('due.0.due', 440);
        $this->assertSame([[$services->id, 590.0, 'upi', 'UPI-1'], [$lab->id, 410.0, 'upi', 'UPI-1']], Payment::orderBy('id')->get()->map(fn ($p) => [$p->bill_id, (float) $p->amount, $p->mode, $p->reference])->all());

        $this->getJson("/api/front-desk/due?patient_id={$patient->id}&appointment_id={$visit->id}")->assertOk()->assertJsonCount(1, 'due');
        $this->postJson('/api/front-desk/collect', ['amount' => 440, 'mode' => 'cash'] + $pay)->assertOk()->assertJsonCount(0, 'due');
        $this->assertSame([0.0, 0.0], [$services->fresh()->due, $lab->fresh()->due]);

        // Lab tests alone, for someone with no visit today: what is due is today's bills.
        $this->postJson('/api/front-desk/charges', ['patient_id' => $other->id, 'test_ids' => [$cbp->id]])->assertCreated()->assertJsonCount(1, 'made')->assertJsonPath('due.0.kind', 'lab')->assertJsonPath('due.0.due', 350);
    }

    public function test_a_lab_test_done_more_than_once_is_billed_that_many_times_and_reported_each_time(): void
    {
        $patient = Patient::factory()->create();
        $cbp = LabTest::create(['name' => 'CBP', 'price' => 350]);
        $cbp->parameters()->create(['name' => 'Haemoglobin']);
        $fbs = LabTest::create(['name' => 'FBS', 'price' => 80]);
        $fbs->parameters()->create(['name' => 'Fasting Blood Sugar']);
        $rbs = LabTest::create(['name' => 'RBS', 'price' => 60]);
        $rbs->parameters()->create(['name' => 'Random Blood Sugar']);
        $package = LabPackage::create(['name' => 'Sugar check', 'price' => 100, 'is_active' => true]);
        $package->tests()->sync([$fbs->id]);

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->postJson('/api/front-desk/charges', ['patient_id' => $patient->id, 'test_ids' => [$cbp->id], 'test_counts' => [$cbp->id => 40]])->assertStatus(422)->assertJsonValidationErrors("test_counts.{$cbp->id}");
        $this->assertSame(0, LabOrder::count());

        // CBP twice, RBS once (nothing said), and FBS three times with a package that already has it once.
        $saved = $this->postJson('/api/front-desk/charges', [
            'patient_id' => $patient->id, 'test_ids' => [$cbp->id, $fbs->id, $rbs->id], 'package_ids' => [$package->id],
            'test_counts' => [$cbp->id => 2, $fbs->id => 3],
        ])->assertCreated()->assertJsonCount(1, 'made')->json();

        $bill = Bill::find($saved['made'][0]);
        $this->assertSame(
            [['Sugar check (package)', 1, 100.0], ['CBP', 2, 700.0], ['FBS', 2, 160.0], ['RBS', 1, 60.0]],
            $bill->items->map(fn ($i) => [$i->description, (int) $i->quantity, (float) $i->amount])->all(),
        );
        $this->assertSame(1020.0, (float) $bill->total);

        // The lab has a line for each time, with its own results to fill, numbered where there is more than one.
        $items = LabOrder::first()->items()->with('results')->orderBy('id')->get();
        $this->assertSame(
            [['FBS', 1, 'Sugar check', 0.0], ['CBP', 1, null, 350.0], ['CBP', 2, null, 350.0], ['FBS', 2, null, 80.0], ['FBS', 3, null, 80.0], ['RBS', null, null, 60.0]],
            $items->map(fn ($i) => [$i->test_name, $i->repeat_no, $i->package_name, (float) $i->price])->all(),
        );
        $this->assertSame([1, 1, 1, 1, 1, 1], $items->map(fn ($i) => $i->results->count())->all());
        $this->assertSame(['CBP (1st)', 'CBP (2nd)', 'RBS'], [$items[1]->label(), $items[2]->label(), $items[5]->label()]);

        // The lab's own order screen takes the same counts.
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $order = $this->postJson('/api/lab/orders', ['patient_id' => $patient->id, 'test_ids' => [$rbs->id], 'test_counts' => [$rbs->id => 3]])->assertCreated()->json();
        $this->assertSame([1, 2, 3], array_column($order['items'], 'repeat_no'));
        $this->assertSame(180.0, (float) Bill::find($order['bill_id'])->total);
    }

    public function test_all_of_a_patients_bills_for_the_day_come_back_together(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Sample']);
        $patient = Patient::factory()->create();
        $other = Patient::factory()->create();
        $visit = Appointment::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now()->format('Y-m-d H:i'), 'status' => 'arrived', 'token_no' => 1]);
        $ecg = Service::create(['name' => 'ECG', 'type' => 'other', 'price' => 300, 'is_active' => true]);
        $cbp = LabTest::create(['name' => 'CBP', 'price' => 350]);
        $cbp->parameters()->create(['name' => 'Haemoglobin']);

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        // Billed three times over the day: a service on the visit, a lab test on the visit, and a lab test with no visit.
        $this->postJson('/api/front-desk/charges', ['patient_id' => $patient->id, 'appointment_id' => $visit->id, 'services' => [['service_id' => $ecg->id, 'quantity' => 2]]])->assertCreated();
        $this->postJson('/api/front-desk/charges', ['patient_id' => $patient->id, 'appointment_id' => $visit->id, 'test_ids' => [$cbp->id]])->assertCreated();
        $this->postJson('/api/front-desk/charges', ['patient_id' => $patient->id, 'test_ids' => [$cbp->id]])->assertCreated();
        // Someone else's bill, and this patient's bill from another day, stay out of it.
        $this->postJson('/api/front-desk/charges', ['patient_id' => $other->id, 'services' => [['service_id' => $ecg->id]]])->assertCreated();
        $old = Bill::create(['patient_id' => $patient->id, 'kind' => 'other']);
        $old->forceFill(['created_at' => now()->subDays(3)])->save();

        $day = $this->getJson("/api/front-desk/day-bills?patient_id={$patient->id}")->assertOk()
            ->assertJsonCount(3, 'bills')->assertJsonPath('total', 1300)->assertJsonPath('paid', 0)->assertJsonPath('due', 1300)
            ->assertJsonPath('bills.0.items.0.description', 'ECG')->assertJsonPath('bills.0.items.0.quantity', 2)->assertJsonPath('bills.0.items.0.amount', 600)
            ->assertJsonPath('bills.0.doctor', 'Dr Sample')->assertJsonPath('bills.1.kind', 'lab')->assertJsonPath('bills.2.appointment_id', null)
            ->json();

        // One payment settles the lot, and the same page then shows it paid.
        $this->postJson('/api/front-desk/collect', ['patient_id' => $patient->id, 'bill_ids' => array_column($day['bills'], 'id'), 'amount' => 1300, 'mode' => 'upi'])->assertOk();
        $this->getJson("/api/front-desk/day-bills?patient_id={$patient->id}&date=".now()->toDateString())->assertOk()
            ->assertJsonPath('paid', 1300)->assertJsonPath('due', 0)->assertJsonPath('bills.1.payments.0.mode', 'upi');
        $this->getJson("/api/front-desk/day-bills?patient_id={$patient->id}&date=".now()->subDays(3)->toDateString())->assertOk()->assertJsonCount(1, 'bills');

        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->getJson("/api/front-desk/day-bills?patient_id={$patient->id}")->assertForbidden();
    }

    public function test_the_lab_takes_one_payment_for_several_lab_bills_but_not_for_other_bills(): void
    {
        $patient = Patient::factory()->create();
        $cbp = LabTest::create(['name' => 'CBP', 'price' => 350]);
        $cbp->parameters()->create(['name' => 'Haemoglobin']);
        $ecg = Service::create(['name' => 'ECG', 'type' => 'other', 'price' => 300, 'is_active' => true]);

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->postJson('/api/front-desk/charges', ['patient_id' => $patient->id, 'test_ids' => [$cbp->id]])->assertCreated();
        $this->postJson('/api/front-desk/charges', ['patient_id' => $patient->id, 'test_ids' => [$cbp->id]])->assertCreated();
        $this->postJson('/api/front-desk/charges', ['patient_id' => $patient->id, 'services' => [['service_id' => $ecg->id]]])->assertCreated();
        $lab = Bill::where('kind', 'lab')->pluck('id')->all();
        $services = Bill::where('kind', '!=', 'lab')->value('id');

        Sanctum::actingAs(User::factory()->create(['role' => 'lab_tech']));
        // A services bill in the lot stops all of it: nothing is paid.
        $this->postJson('/api/lab/collect', ['patient_id' => $patient->id, 'bill_ids' => [...$lab, $services], 'amount' => 1000, 'mode' => 'cash'])->assertForbidden();
        $this->assertSame(0, Payment::count());

        $this->postJson('/api/lab/collect', ['patient_id' => $patient->id, 'bill_ids' => $lab, 'amount' => 700, 'mode' => 'upi'])->assertOk();
        $this->assertSame([350.0, 350.0], Bill::whereIn('id', $lab)->get()->map(fn (Bill $b) => (float) $b->paid)->all());
        $this->assertSame(0.0, (float) Bill::find($services)->paid);
    }
}
