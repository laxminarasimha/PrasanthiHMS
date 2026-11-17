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
            'services' => [['service_id' => $ecg->id], ['service_id' => $dressing->id, 'quantity' => 2, 'line_discount' => 50], ['description' => 'Injection charge', 'unit_price' => 40]],
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
}
