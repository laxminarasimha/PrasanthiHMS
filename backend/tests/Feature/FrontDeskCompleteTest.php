<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\LabTest;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FrontDeskCompleteTest extends TestCase
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

    private function book(array $extra = [])
    {
        return $this->postJson('/api/appointments', $extra + [
            'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'service_id' => $this->consultation->id,
            'scheduled_at' => now()->format('Y-m-d H:i'), 'status' => 'arrived',
        ]);
    }

    public function test_booking_can_change_the_charge_and_take_payment_and_gives_tokens_in_order(): void
    {
        // 2 x 250 less 100 discount = 400, of which 300 is paid now.
        $first = $this->book(['unit_price' => 250, 'quantity' => 2, 'discount' => 100, 'paid' => 300, 'payment_mode' => 'upi'])->assertCreated()->json();
        $this->assertSame(1, $first['token_no']);
        $this->assertEquals(400, $first['bills'][0]['total']);
        $this->assertEquals(300, $first['bills'][0]['paid']);
        $this->assertSame('appointment', $first['bills'][0]['kind']);

        $second = $this->book(['patient_id' => Patient::factory()->create()->id])->assertCreated()->json();
        $this->assertSame(2, $second['token_no']);
        $this->assertEquals(200, $second['bills'][0]['total']);

        $this->book(['patient_id' => Patient::factory()->create()->id, 'discount' => 900])->assertStatus(422)->assertJsonValidationErrors('discount');
        $this->book(['patient_id' => Patient::factory()->create()->id, 'paid' => 900, 'payment_mode' => 'cash'])->assertStatus(422)->assertJsonValidationErrors('paid');
    }

    public function test_billing_can_be_skipped_when_booking(): void
    {
        $this->book(['skip_billing' => true])->assertCreated()->assertJsonCount(0, 'bills');
    }

    public function test_bill_lines_can_have_their_own_price_quantity_discount_and_free_text(): void
    {
        $billId = $this->book()->json('bills.0.id');
        $dressing = Service::factory()->create(['name' => 'Dressing', 'type' => 'other', 'price' => 150, 'gst_percent' => 10]);

        // 3 x 120 less 60 = 300, plus 10% GST = 330. Bill: 200 + 300 = 500, tax 30.
        $this->postJson("/api/bills/{$billId}/items", ['service_id' => $dressing->id, 'unit_price' => 120, 'quantity' => 3, 'line_discount' => 60])
            ->assertCreated()->assertJsonPath('subtotal', 500)->assertJsonPath('tax', 30)->assertJsonPath('total', 530)
            ->assertJsonPath('items.1.discount', 60)->assertJsonPath('items.1.amount', 300);

        $this->postJson("/api/bills/{$billId}/items", ['description' => 'Ambulance charge', 'unit_price' => 800])
            ->assertCreated()->assertJsonPath('total', 1330)->assertJsonPath('items.2.description', 'Ambulance charge');

        $this->postJson("/api/bills/{$billId}/items", ['description' => 'No price given'])->assertStatus(422)->assertJsonValidationErrors('unit_price');
        $this->postJson("/api/bills/{$billId}/items", [])->assertStatus(422)->assertJsonValidationErrors('service_id');
        $this->postJson("/api/bills/{$billId}/items", ['service_id' => $dressing->id, 'line_discount' => 999])->assertStatus(422)->assertJsonValidationErrors('line_discount');
    }

    public function test_discount_after_payment_can_refund_the_excess_in_one_step(): void
    {
        $billId = $this->book(['paid' => 200, 'payment_mode' => 'cash'])->json('bills.0.id');

        $this->putJson("/api/bills/{$billId}", ['discount' => 50])->assertStatus(422)->assertJsonValidationErrors('discount');
        $this->putJson("/api/bills/{$billId}", ['discount' => 50, 'refund_excess' => true, 'refund_mode' => 'cash'])
            ->assertOk()->assertJsonPath('total', 150)->assertJsonPath('paid', 150)->assertJsonPath('due', 0)->assertJsonCount(2, 'payments');
    }

    public function test_more_bills_and_walk_in_bills_show_up_under_the_right_department(): void
    {
        $appointment = $this->book()->json();
        $ecg = Service::factory()->create(['name' => 'ECG', 'type' => 'other', 'price' => 300]);

        // A second bill on the same visit, and a walk-in who only wants an ECG.
        $this->postJson('/api/bills', ['patient_id' => $this->patient->id, 'appointment_id' => $appointment['id'], 'service_id' => $ecg->id])
            ->assertCreated()->assertJsonPath('kind', 'appointment')->assertJsonPath('total', 300);
        $walkIn = Patient::factory()->create();
        $other = $this->postJson('/api/bills', ['patient_id' => $walkIn->id, 'service_id' => $ecg->id, 'quantity' => 2])
            ->assertCreated()->assertJsonPath('kind', 'other')->assertJsonPath('total', 600)->json();
        $this->postJson("/api/bills/{$other['id']}/payments", ['type' => 'payment', 'amount' => 600, 'mode' => 'card'])->assertCreated();

        // A lab-only walk-in.
        $test = LabTest::create(['name' => 'CBP', 'price' => 350]);
        $test->parameters()->create(['name' => 'Haemoglobin']);
        $this->postJson('/api/lab/orders', ['patient_id' => $walkIn->id, 'test_ids' => [$test->id]])->assertCreated()->assertJsonPath('bill.total', 350);

        $this->getJson('/api/appointments')->assertOk()->assertJsonCount(2, 'data.0.bills');
        $walkIns = $this->getJson('/api/front-desk/bills')->assertOk()->assertJsonCount(2)->json();
        $this->assertEqualsCanonicalizing(['other', 'lab'], array_column($walkIns, 'kind'));

        $this->getJson('/api/front-desk/summary')->assertOk()
            ->assertJsonPath('bills.appointment.billed', 500)->assertJsonPath('bills.appointment.orders', 2)->assertJsonPath('bills.appointment.outstanding', 500)
            ->assertJsonPath('bills.other.billed', 600)->assertJsonPath('bills.other.collected', 600)->assertJsonPath('bills.other.outstanding', 0)
            ->assertJsonPath('bills.lab.billed', 350)->assertJsonPath('bills.lab.orders', 1)
            ->assertJsonPath('bills.all.billed', 1450)->assertJsonPath('bills.all.collected', 600)
            ->assertJsonPath('appointments.0.total', 1)->assertJsonPath('appointments.0.arrived', 1);
    }

    public function test_front_desk_can_record_vitals_without_touching_the_rest_of_the_visit(): void
    {
        $appointment = $this->book()->json();

        $this->putJson("/api/appointments/{$appointment['id']}/vitals", ['bp_systolic' => 130])->assertStatus(422)->assertJsonValidationErrors('bp_diastolic');
        $this->putJson("/api/appointments/{$appointment['id']}/vitals", ['weight_kg' => 64, 'bp_systolic' => 130, 'bp_diastolic' => 84, 'pulse' => 80])
            ->assertOk()->assertJsonPath('pulse', 80);

        // The doctor sees them on opening the pad, and the visit is still waiting.
        Sanctum::actingAs($this->doctor);
        $this->getJson("/api/appointments/{$appointment['id']}/consultation")->assertOk()
            ->assertJsonPath('consultation.bp_systolic', 130)->assertJsonPath('appointment.status', 'arrived');

        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->putJson("/api/appointments/{$appointment['id']}/vitals", ['pulse' => 70])->assertForbidden();
    }

    public function test_waiting_room_queue_lists_tokens_per_doctor(): void
    {
        $first = $this->book()->json();
        $second = $this->book(['patient_id' => Patient::factory()->create()->id])->json();
        $this->book(['patient_id' => Patient::factory()->create()->id, 'status' => 'booked']);
        $this->putJson("/api/appointments/{$first['id']}", ['status' => 'ongoing'])->assertOk();

        $this->getJson('/api/front-desk/queue')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.doctor', $this->doctor->name)
            ->assertJsonPath('0.now.0.token', 1)
            ->assertJsonPath('0.waiting.0.token', $second['token_no'])
            ->assertJsonCount(1, '0.waiting');
    }

    public function test_attachments_are_stored_privately_and_can_be_downloaded_and_removed(): void
    {
        Storage::fake('local');

        $this->postJson("/api/patients/{$this->patient->id}/attachments", [
            'title' => 'Old ECG', 'document_date' => now()->toDateString(), 'file' => UploadedFile::fake()->create('virus.exe', 10, 'application/octet-stream'),
        ])->assertStatus(422)->assertJsonValidationErrors('file');

        $attachment = $this->postJson("/api/patients/{$this->patient->id}/attachments", [
            'title' => 'Old ECG', 'document_date' => now()->toDateString(), 'file' => UploadedFile::fake()->create('ecg.pdf', 120, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('title', 'Old ECG')->assertJsonMissingPath('path')->json();

        $this->assertCount(1, Storage::disk('local')->allFiles("attachments/{$this->patient->id}"));
        $this->getJson("/api/patients/{$this->patient->id}/attachments")->assertOk()->assertJsonCount(1);
        $this->get("/api/attachments/{$attachment['id']}/download")->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->deleteJson("/api/attachments/{$attachment['id']}")->assertOk();
        $this->assertCount(0, Storage::disk('local')->allFiles("attachments/{$this->patient->id}"));
    }

    public function test_patient_overview_brings_visits_bills_and_dues_together(): void
    {
        $this->book(['paid' => 50, 'payment_mode' => 'cash']);

        $this->getJson("/api/patients/{$this->patient->id}/overview")->assertOk()
            ->assertJsonPath('patient.id', $this->patient->id)
            ->assertJsonCount(1, 'visits')->assertJsonCount(1, 'bills')
            ->assertJsonPath('outstanding', 150)
            ->assertJsonStructure(['lab_orders', 'pharmacy_bills', 'admissions', 'attachments']);
        $this->assertSame(1, Bill::count());
    }

    public function test_services_keep_a_priority_that_orders_the_pick_lists(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/services', ['name' => 'Urgent consultation', 'type' => 'appointment', 'price' => 500, 'priority' => 1])->assertCreated()->assertJsonPath('priority', 1);

        $names = array_column($this->getJson('/api/services')->json(), 'name');
        $this->assertSame(['Urgent consultation', 'Consultation'], $names);
    }
}
