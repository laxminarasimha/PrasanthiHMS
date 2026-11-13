<?php

namespace Tests\Feature;

use App\Models\Bed;
use App\Models\IpdService;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IpdRecordsTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private User $nurse;

    private int $case;

    protected function setUp(): void
    {
        parent::setUp();
        $this->nurse = User::factory()->create(['role' => 'nurse']);
        $this->doctor = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs($this->nurse);
        $bed = Bed::create(['ward' => 'General Ward', 'bed_no' => '1', 'daily_charge' => 800]);
        $this->case = $this->postJson('/api/ipd/admissions', [
            'patient_id' => Patient::factory()->create()->id, 'doctor_id' => $this->doctor->id, 'bed_id' => $bed->id,
            'admitted_at' => now()->subDays(2)->format('Y-m-d H:i'), 'reason' => 'Fever for evaluation',
        ])->assertCreated()->json('id');
    }

    public function test_clinical_nursing_and_operation_notes_are_kept_with_the_stay(): void
    {
        $url = "/api/ipd/admissions/{$this->case}/notes";
        $now = now()->format('Y-m-d H:i');

        $this->postJson($url, ['kind' => 'clinical', 'noted_at' => $now])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->postJson($url, ['kind' => 'gossip', 'noted_at' => $now, 'body' => 'x'])->assertStatus(422);
        $this->postJson($url, ['kind' => 'clinical', 'noted_at' => now()->addDay()->format('Y-m-d H:i'), 'body' => 'x'])->assertStatus(422);

        $this->postJson($url, ['kind' => 'clinical', 'noted_at' => $now, 'body' => 'Afebrile today. Continue antibiotics.'])->assertCreated()->assertJsonCount(1);
        // Only the fields that belong to the kind of note are kept.
        $this->postJson($url, ['kind' => 'nursing', 'noted_at' => $now, 'details' => ['bp' => '120/80', 'pulse' => '82', 'surgeon' => 'nobody']])
            ->assertCreated()->assertJsonCount(2);
        $notes = $this->postJson($url, ['kind' => 'ot', 'noted_at' => $now, 'body' => 'Uneventful.', 'details' => ['procedure' => 'Appendicectomy', 'surgeon' => 'Dr Sample', 'anaesthesia' => 'Spinal']])
            ->assertCreated()->assertJsonCount(3)->json();

        $byKind = collect($notes)->keyBy('kind');
        $this->assertSame(['bp' => '120/80', 'pulse' => '82'], $byKind['nursing']['details']);
        $this->assertSame('Appendicectomy', $byKind['ot']['details']['procedure']);
        $this->assertSame($this->nurse->name, $byKind['clinical']['author']['name']);

        $id = $byKind['clinical']['id'];
        $this->putJson("{$url}/{$id}", ['noted_at' => $now, 'body' => 'Afebrile. Plan discharge tomorrow.'])->assertOk();
        $this->assertDatabaseHas('admission_notes', ['id' => $id, 'kind' => 'clinical', 'body' => 'Afebrile. Plan discharge tomorrow.', 'updated_by' => $this->nurse->id]);
        $this->deleteJson("{$url}/{$id}")->assertOk()->assertJsonCount(2);

        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->getJson($url)->assertOk()->assertJsonCount(2);
        $this->postJson($url, ['kind' => 'clinical', 'noted_at' => $now, 'body' => 'x'])->assertForbidden();
    }

    public function test_discharge_summary_is_written_in_parts_then_locked_by_approval(): void
    {
        $url = "/api/ipd/admissions/{$this->case}/summary";
        // Before anything is written, the reason for admission is offered as the diagnosis.
        $this->getJson($url)->assertOk()->assertJsonPath('id', null)->assertJsonPath('diagnosis', 'Fever for evaluation');
        $this->postJson("{$url}/approve", ['approved' => true])->assertForbidden();

        $body = ['diagnosis' => 'Enteric fever', 'course' => 'Treated with IV antibiotics; fever settled on day 2.', 'condition_at_discharge' => 'Stable',
            'medicines' => [['medicine' => 'Cefixime 200mg', 'frequency' => '1-0-1', 'duration' => '5 days']], 'follow_up_on' => now()->addWeek()->toDateString()];
        $this->putJson($url, ['medicines' => [['dose' => '1']]])->assertStatus(422);
        $this->putJson($url, $body)->assertOk()->assertJsonPath('diagnosis', 'Enteric fever')->assertJsonPath('medicines.0.medicine', 'Cefixime 200mg')
            ->assertJsonPath('preparer.name', $this->nurse->name)->assertJsonPath('approved_at', null);

        Sanctum::actingAs($this->doctor);
        $this->postJson("{$url}/approve", ['approved' => true])->assertOk()->assertJsonPath('approver.name', $this->doctor->name);
        $this->putJson($url, ['diagnosis' => 'Changed'] + $body)->assertStatus(422)->assertJsonValidationErrors('summary');

        $this->postJson("{$url}/approve", ['approved' => false])->assertOk()->assertJsonPath('approved_at', null);
        $this->putJson($url, ['diagnosis' => 'Typhoid fever'] + $body)->assertOk()->assertJsonPath('diagnosis', 'Typhoid fever');
    }

    public function test_a_bill_template_adds_its_services_and_an_approved_bill_is_locked(): void
    {
        Sanctum::actingAs($this->doctor);
        $room = IpdService::create(['name' => 'Nursing charges', 'category' => 'Nursing', 'price' => 500]);
        $ot = IpdService::create(['name' => 'OT charges', 'category' => 'Surgery', 'price' => 8000]);

        $this->postJson('/api/ipd/templates', ['name' => 'Surgery package', 'items' => []])->assertStatus(422);
        $template = $this->postJson('/api/ipd/templates', ['name' => 'Surgery package', 'items' => [['ipd_service_id' => $room->id, 'quantity' => 3], ['ipd_service_id' => $ot->id, 'quantity' => 1]]])
            ->assertCreated()->json('id');
        $this->postJson('/api/ipd/templates', ['name' => 'Surgery package', 'items' => [['ipd_service_id' => $room->id, 'quantity' => 1]]])->assertStatus(422);
        $this->getJson('/api/ipd/templates')->assertOk()->assertJsonCount(1);

        $case = "/api/ipd/admissions/{$this->case}";
        $this->postJson("{$case}/templates/{$template}")->assertCreated()->assertJsonCount(2, 'charges')->assertJsonPath('total', 9500);

        $this->postJson("{$case}/approve-bill", ['approved' => true])->assertOk()->assertJsonPath('bill_approver.name', $this->doctor->name);
        $charge = ['description' => 'Dressing', 'charged_on' => now()->toDateString(), 'quantity' => 1, 'unit_price' => 200];
        $this->postJson("{$case}/charges", $charge)->assertStatus(422)->assertJsonValidationErrors('bill');
        $this->putJson($case, ['discount' => 100])->assertStatus(422);
        $this->postJson("{$case}/templates/{$template}")->assertStatus(422);
        // Money can still be taken on an approved bill.
        $this->postJson("{$case}/payments", ['type' => 'payment', 'amount' => 5000, 'mode' => 'cash'])->assertCreated();

        $this->postJson("{$case}/approve-bill", ['approved' => false])->assertOk()->assertJsonPath('bill_approved_at', null);
        $this->postJson("{$case}/charges", $charge)->assertCreated()->assertJsonPath('total', 9700);

        Sanctum::actingAs($this->nurse);
        $this->postJson("{$case}/approve-bill", ['approved' => true])->assertForbidden();
        $this->postJson('/api/ipd/templates', ['name' => 'Another', 'items' => [['ipd_service_id' => $room->id, 'quantity' => 1]]])->assertForbidden();
        $this->deleteJson("/api/ipd/templates/{$template}")->assertForbidden();
    }

    public function test_case_bed_and_payment_reports(): void
    {
        $case = "/api/ipd/admissions/{$this->case}";
        $this->postJson("{$case}/charges", ['description' => 'Bed charges', 'charged_on' => now()->toDateString(), 'quantity' => 2, 'unit_price' => 800])->assertCreated();
        $this->postJson("{$case}/payments", ['type' => 'advance', 'amount' => 2000, 'mode' => 'cash'])->assertCreated();
        $this->postJson("{$case}/payments", ['type' => 'refund', 'amount' => 300, 'mode' => 'cash'])->assertCreated();
        $range = '?from='.now()->subDays(3)->toDateString().'&to='.now()->toDateString();

        $cases = $this->getJson("/api/ipd/reports/cases{$range}")->assertOk()->assertJsonCount(1, 'rows')->json();
        $this->assertEquals(1600, $cases['rows'][0]['total']);
        $this->assertEquals(100, $cases['rows'][0]['advance']);
        $this->assertEquals(1700, $cases['totals']['paid']);

        $beds = $this->getJson("/api/ipd/reports/beds{$range}")->assertOk()->assertJsonCount(1, 'rows')->json('rows.0');
        $this->assertSame('Occupied', $beds['status']);
        $this->assertGreaterThan(40, $beds['usage']);
        $this->assertLessThanOrEqual(100, $beds['usage']);

        $payments = $this->getJson("/api/ipd/reports/payments{$range}")->assertOk()->assertJsonCount(2, 'rows')->json();
        $this->assertEquals(1700, $payments['totals']['amount']);
        $this->assertSame($this->nurse->name, $payments['rows'][0]['person']);

        $this->getJson('/api/ipd/reports/nothing')->assertNotFound();
    }

    public function test_charges_take_a_discount_and_remarks_can_be_corrected_and_deleted_ones_are_kept(): void
    {
        $case = "/api/ipd/admissions/{$this->case}";
        $line = ['description' => 'Nursing charges', 'charged_on' => now()->toDateString(), 'quantity' => 2, 'unit_price' => 500];
        $this->postJson("{$case}/charges", $line + ['discount' => 1500])->assertStatus(422)->assertJsonValidationErrors('discount');
        $charge = $this->postJson("{$case}/charges", $line + ['discount' => 100, 'remarks' => 'Senior citizen'])->assertCreated()
            ->assertJsonPath('total', 900)->assertJsonPath('charges.0.discount', 100)->assertJsonPath('charges.0.remarks', 'Senior citizen')
            ->assertJsonPath('charges.0.creator.name', $this->nurse->name)->json('charges.0.id');

        $this->putJson("{$case}/charges/{$charge}", ['charged_on' => now()->toDateString(), 'quantity' => 3, 'unit_price' => 500, 'discount' => 0])->assertOk()->assertJsonPath('total', 1500)->assertJsonPath('charges.0.remarks', null);
        $this->putJson("{$case}/charges/{$charge}", ['charged_on' => now()->toDateString(), 'quantity' => 1, 'unit_price' => 100, 'discount' => 500])->assertStatus(422);

        $this->deleteJson("{$case}/charges/{$charge}")->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'charges')
            ->assertJsonCount(1, 'deleted_charges')->assertJsonPath('deleted_charges.0.deleter.name', $this->nurse->name)->assertJsonPath('deleted_charges.0.amount', 1500);
        $this->getJson("/api/ipd/reports/cases?from=".now()->subDays(3)->toDateString().'&to='.now()->toDateString())->assertJsonPath('rows.0.total', 0);
    }

    public function test_payments_can_be_back_dated_with_remarks_and_a_mistaken_one_deleted(): void
    {
        $case = "/api/ipd/admissions/{$this->case}";
        $yesterday = now()->subDay()->toDateString();
        $this->postJson("{$case}/payments", ['type' => 'advance', 'amount' => 5000, 'mode' => 'cash', 'paid_on' => now()->subDays(10)->toDateString()])->assertStatus(422)->assertJsonValidationErrors('paid_on');
        $payment = $this->postJson("{$case}/payments", ['type' => 'advance', 'amount' => 5000, 'mode' => 'cash', 'paid_on' => $yesterday, 'remarks' => 'Hospital advance'])
            ->assertCreated()->assertJsonPath('paid', 5000)->assertJsonPath('payments.0.remarks', 'Hospital advance')->json('payments.0');
        $this->assertStringStartsWith($yesterday, \App\Models\AdmissionPayment::find($payment['id'])->created_at->toDateString());
        // It is counted on the day it was received.
        $this->getJson("/api/ipd/reports/payments?from={$yesterday}&to={$yesterday}")->assertJsonCount(1, 'rows');

        $this->postJson("{$case}/payments", ['type' => 'refund', 'amount' => 4000, 'mode' => 'cash'])->assertCreated()->assertJsonPath('paid', 1000);
        $this->deleteJson("{$case}/payments/{$payment['id']}")->assertForbidden(); // a nurse may not delete money entries

        Sanctum::actingAs($this->doctor);
        $this->deleteJson("{$case}/payments/{$payment['id']}")->assertStatus(422); // would leave more refunded than received
        $refund = \App\Models\AdmissionPayment::where('type', 'refund')->value('id');
        $this->deleteJson("{$case}/payments/{$refund}")->assertOk()->assertJsonPath('paid', 5000);
        $this->deleteJson("{$case}/payments/{$payment['id']}")->assertOk()->assertJsonPath('paid', 0)->assertJsonCount(0, 'payments');
    }

    public function test_case_details_longer_summary_and_investigations_by_day(): void
    {
        $case = "/api/ipd/admissions/{$this->case}";
        $this->putJson($case, ['aadhaar_no' => '1234'])->assertStatus(422);
        $this->putJson($case, ['aadhaar_no' => '123412341234', 'gst_no' => '37ABCDE1234F1Z5'])->assertOk()->assertJsonPath('aadhaar_no', '123412341234')->assertJsonPath('gst_no', '37ABCDE1234F1Z5');

        $this->putJson("{$case}/summary", ['diagnosis' => 'Enteric fever', 'treatment_given' => 'IV antibiotics', 'operative_findings' => 'None', 'tests_requested' => 'Repeat CBP in a week', 'other_instructions' => 'Soft diet', 'acknowledgement' => 'Explained to the attendant'])
            ->assertOk()->assertJsonPath('treatment_given', 'IV antibiotics')->assertJsonPath('acknowledgement', 'Explained to the attendant');

        // Two results for the same test on two days make one row with two dated values.
        Sanctum::actingAs(User::factory()->create(['role' => 'lab_tech']));
        $test = \App\Models\LabTest::create(['name' => 'Random Blood Sugar', 'price' => 100]);
        $test->parameters()->create(['name' => 'RBS', 'unit' => 'mg/dL', 'reference_range' => '70 - 140']);
        $patient = \App\Models\Admission::find($this->case)->patient_id;
        foreach ([['182', 'high', now()->subDay()], ['128', null, now()]] as [$value, $flag, $when]) {
            $order = $this->postJson('/api/lab/orders', ['patient_id' => $patient, 'test_ids' => [$test->id]])->assertCreated()->json();
            $item = $order['items'][0];
            $this->putJson("/api/lab/orders/{$order['id']}/items/{$item['id']}", ['complete' => true, 'results' => [['id' => $item['results'][0]['id'], 'value' => $value, 'flag' => $flag]]])->assertOk();
            \App\Models\LabOrderItem::whereKey($item['id'])->update(['reported_at' => $when]);
        }
        $chart = $this->getJson("{$case}/investigations")->assertOk()->assertJsonCount(2, 'dates')->assertJsonCount(1, 'rows')->json();
        $this->assertSame('RBS', $chart['rows'][0]['parameter']);
        $this->assertSame(['182', '128'], array_column(array_values($chart['rows'][0]['values']), 'value'));
        $this->assertSame('high', $chart['rows'][0]['values'][now()->subDay()->toDateString()]['flag']);
    }
}
