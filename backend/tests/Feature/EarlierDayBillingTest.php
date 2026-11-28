<?php

namespace Tests\Feature;

use App\Models\AdmissionCharge;
use App\Models\AuditLog;
use App\Models\Bed;
use App\Models\Bill;
use App\Models\DayClose;
use App\Models\IpdService;
use App\Models\LabOrder;
use App\Models\LabTest;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\PatientDeposit;
use App\Models\Payment;
use App\Models\PharmacyPayment;
use App\Models\PharmacySale;
use App\Models\Service;
use App\Models\Stockist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A bill or a payment that was missed is entered on a later day and still counts on the day it belongs to. */
class EarlierDayBillingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Patient $patient;

    private string $then;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs($this->admin = User::factory()->create(['role' => 'admin']));
        $this->patient = Patient::factory()->create();
        $this->then = today()->subDays(3)->toDateString();
    }

    /** What the organisation report says of one day. */
    private function report(string $day): array
    {
        return $this->getJson("/api/reports/summary?from={$day}&to={$day}")->assertOk()->json('departments');
    }

    private function close(string $day): DayClose
    {
        return DayClose::create([
            'day' => $day, 'closed_by' => $this->admin->id, 'closed_by_name' => $this->admin->name, 'closed_at' => now(),
            'expected_cash' => 0, 'counted_cash' => 0, 'variance' => 0, 'snapshot' => [],
        ]);
    }

    /** One batch of 200 tablets at ₹2.80 each, in date. */
    private function stock(): int
    {
        $medicine = Medicine::create(['name' => 'Paracetamol 650 Tablet', 'gst_percent' => 12, 'units_per_pack' => 10, 'reorder_level' => 50]);

        return $this->postJson('/api/pharmacy/purchases', [
            'stockist_id' => Stockist::create(['name' => 'Sample Distributors'])->id, 'invoice_no' => 'INV-1', 'invoice_date' => now()->toDateString(),
            'items' => [['medicine_id' => $medicine->id, 'batch_no' => 'B001', 'expiry' => now()->addYear()->format('Y-m'), 'units_per_pack' => 10, 'packs' => 20, 'pack_price' => 15, 'pack_mrp' => 28, 'gst_percent' => 12]],
        ])->assertCreated()->json('items.0.stock_batch.id') ?? \App\Models\StockBatch::value('id');
    }

    public function test_front_desk_charges_and_their_money_count_on_the_day_they_belong_to(): void
    {
        $service = Service::factory()->create(['price' => 200]);
        $test = LabTest::create(['name' => 'CBP', 'price' => 300]);
        $test->parameters()->create(['name' => 'Haemoglobin']);

        $made = $this->postJson('/api/front-desk/charges', [
            'patient_id' => $this->patient->id, 'services' => [['service_id' => $service->id]], 'test_ids' => [$test->id], 'billed_on' => $this->then,
        ])->assertCreated()->assertJsonCount(2, 'made')->assertJsonCount(2, 'due')->json();

        foreach (Bill::all() as $bill) {
            $this->assertSame($this->then, $bill->created_at->toDateString());
            // When it was really typed in is kept beside it.
            $this->assertSame(today()->toDateString(), $bill->entered_at->toDateString());
        }
        $this->assertSame($this->then, LabOrder::first()->ordered_at->toDateString());

        // Billed on that day in the reports, and nothing today.
        $this->assertEquals(200, $this->report($this->then)['consultation']['billed']);
        $this->assertEquals(300, $this->report($this->then)['lab']['billed']);
        $this->assertEquals(0, $this->report(today()->toDateString())['consultation']['billed']);
        // The day list of that day has the bills; they are asked for by that day.
        $this->getJson("/api/front-desk/due?patient_id={$this->patient->id}&date={$this->then}")->assertOk()->assertJsonCount(2, 'due');
        $this->getJson("/api/front-desk/due?patient_id={$this->patient->id}")->assertOk()->assertJsonCount(0, 'due');

        // The money was taken that day too.
        $this->postJson('/api/front-desk/collect', ['patient_id' => $this->patient->id, 'bill_ids' => $made['made'], 'amount' => 500, 'mode' => 'cash', 'paid_on' => $this->then, 'date' => $this->then])
            ->assertOk()->assertJsonCount(0, 'due');
        $this->assertSame([$this->then], Payment::all()->map(fn (Payment $p) => $p->created_at->toDateString())->unique()->values()->all());
        $this->assertNotNull(Payment::first()->entered_at);
        $this->assertEquals(200, $this->report($this->then)['consultation']['collected']['cash']);
        $this->assertEquals(300, $this->report($this->then)['lab']['collected']['cash']);
        $this->assertEquals(0, $this->report(today()->toDateString())['consultation']['collected_total']);

        // Every entry dated back is written in the activity log with both days.
        $lines = AuditLog::where('action', 'billing.earlier_day')->pluck('summary');
        $this->assertCount(4, $lines);
        $this->assertStringContainsString('entered on '.now()->format('j M Y').' for '.today()->subDays(3)->format('j M Y'), $lines[0]);
        // And the owner can read them all in one report, by the day they were typed in.
        $today = today()->toDateString();
        $this->getJson("/api/reports/list/earlier-day?from={$today}&to={$today}")->assertOk()->assertJsonCount(4, 'rows')
            ->assertJsonPath('rows.0.who', $this->admin->name)->assertJsonPath('rows.0.patient', $this->patient->uhid.' '.$this->patient->name);
        $this->getJson("/api/reports/list/earlier-day?from={$this->then}&to={$this->then}")->assertOk()->assertJsonCount(0, 'rows');
    }

    public function test_an_entry_for_today_is_an_ordinary_one_and_money_of_an_old_bill_can_be_taken_today(): void
    {
        $service = Service::factory()->create(['price' => 200]);
        $bill = $this->postJson('/api/bills', ['patient_id' => $this->patient->id, 'service_id' => $service->id, 'billed_on' => today()->toDateString()])->assertCreated()->json('id');
        $this->assertNull(Bill::find($bill)->entered_at);
        $this->assertSame(today()->toDateString(), Bill::find($bill)->created_at->toDateString());

        // A bill of an earlier day whose money comes in today: the money is today's.
        $old = $this->postJson('/api/bills', ['patient_id' => $this->patient->id, 'service_id' => $service->id, 'billed_on' => $this->then])->assertCreated()->json('id');
        $this->postJson("/api/bills/{$old}/payments", ['type' => 'payment', 'amount' => 200, 'mode' => 'upi'])->assertCreated();
        $this->assertEquals(200, $this->report(today()->toDateString())['consultation']['collected']['upi']);
        $this->assertEquals(0, $this->report($this->then)['consultation']['collected_total']);
        $this->assertSame(0, AuditLog::where('action', 'billing.earlier_day')->where('summary', 'like', '%received%')->count());
    }

    public function test_the_date_cannot_be_ahead_too_far_back_or_put_money_before_its_bill(): void
    {
        $service = Service::factory()->create(['price' => 200]);
        $ask = ['patient_id' => $this->patient->id, 'service_id' => $service->id];

        $this->postJson('/api/bills', $ask + ['billed_on' => today()->addDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('billed_on');
        $this->postJson('/api/bills', $ask + ['billed_on' => today()->subDays(400)->toDateString()])->assertStatus(422)->assertJsonValidationErrors('billed_on');
        $this->postJson('/api/front-desk/charges', ['patient_id' => $this->patient->id, 'services' => [['service_id' => $service->id]], 'billed_on' => today()->addDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('billed_on');
        $this->assertSame(0, Bill::count());

        $bill = $this->postJson('/api/bills', $ask + ['billed_on' => $this->then])->assertCreated()->json('id');
        $this->postJson("/api/bills/{$bill}/payments", ['type' => 'payment', 'amount' => 200, 'mode' => 'cash', 'paid_on' => today()->subDays(5)->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('paid_on');
        $this->postJson("/api/bills/{$bill}/payments", ['type' => 'payment', 'amount' => 200, 'mode' => 'cash', 'paid_on' => today()->addDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('paid_on');
        $this->assertSame(0, Payment::count());
    }

    public function test_a_closed_day_refuses_an_entry_dated_into_it_until_it_is_reopened(): void
    {
        $service = Service::factory()->create(['price' => 200]);
        $close = $this->close($this->then);

        $this->postJson('/api/front-desk/charges', ['patient_id' => $this->patient->id, 'services' => [['service_id' => $service->id]], 'billed_on' => $this->then])->assertStatus(423);
        $this->postJson('/api/bills', ['patient_id' => $this->patient->id, 'service_id' => $service->id, 'billed_on' => $this->then])->assertStatus(423);
        $this->assertSame(0, Bill::count());

        // A bill of an open day cannot have its money dated into the closed one either.
        $bill = $this->postJson('/api/bills', ['patient_id' => $this->patient->id, 'service_id' => $service->id, 'billed_on' => today()->subDays(4)->toDateString()])->assertCreated()->json('id');
        $this->postJson("/api/bills/{$bill}/payments", ['type' => 'payment', 'amount' => 200, 'mode' => 'cash', 'paid_on' => $this->then])->assertStatus(423);
        $this->assertSame(0, Payment::count());

        $close->update(['reopened_at' => now()]);
        $this->postJson("/api/bills/{$bill}/payments", ['type' => 'payment', 'amount' => 200, 'mode' => 'cash', 'paid_on' => $this->then])->assertCreated();
        $this->postJson('/api/bills', ['patient_id' => $this->patient->id, 'service_id' => $service->id, 'billed_on' => $this->then])->assertCreated();
    }

    public function test_a_visit_entered_for_an_earlier_day_carries_its_bill_and_money_on_that_day(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $visit = $this->postJson('/api/appointments', [
            'patient_id' => $this->patient->id, 'doctor_id' => $doctor->id, 'scheduled_at' => "{$this->then} 11:00", 'status' => 'arrived',
            'service_id' => Service::factory()->create(['price' => 400])->id, 'paid' => 400, 'payment_mode' => 'cash',
        ])->assertCreated()->json();

        $bill = Bill::find($visit['bills'][0]['id']);
        $this->assertSame($this->then, $bill->created_at->toDateString());
        $this->assertNotNull($bill->entered_at);
        $this->assertSame($this->then, $bill->payments()->first()->created_at->toDateString());
        $this->assertEquals(400, $this->report($this->then)['consultation']['collected']['cash']);
        $this->assertEquals(0, $this->report(today()->toDateString())['consultation']['billed']);

        // A visit of today, or one booked ahead, is billed as before.
        $next = $this->postJson('/api/appointments', [
            'patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now()->format('Y-m-d H:i'),
            'service_id' => Service::factory()->create(['price' => 100])->id,
        ])->assertCreated()->json();
        $this->assertNull(Bill::find($next['bills'][0]['id'])->entered_at);
    }

    public function test_a_pharmacy_bill_of_an_earlier_day_and_money_taken_later(): void
    {
        $batch = $this->stock();

        $sale = $this->postJson('/api/pharmacy/sales', [
            'customer_name' => 'Walk-in Customer', 'paid' => 10, 'payment_mode' => 'cash', 'sold_on' => $this->then,
            'items' => [['stock_batch_id' => $batch, 'quantity' => 10]],
        ])->assertCreated()->json();

        $this->assertSame($this->then, PharmacySale::find($sale['id'])->sold_at->toDateString());
        $first = PharmacyPayment::first();
        $this->assertSame($this->then, $first->created_at->toDateString());
        $this->assertNotNull($first->entered_at);
        $this->assertEquals(28, $this->report($this->then)['pharmacy']['billed']);
        $this->assertEquals(10, $this->report($this->then)['pharmacy']['collected']['cash']);
        $this->assertEquals(0, $this->report(today()->toDateString())['pharmacy']['billed']);

        // The rest is entered as taken the day after; it cannot be said to have come before the bill.
        $this->postJson("/api/pharmacy/sales/{$sale['id']}/payments", ['amount' => 8, 'mode' => 'upi', 'paid_on' => today()->subDays(4)->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors('paid_on');
        $next = today()->subDays(2)->toDateString();
        $this->postJson("/api/pharmacy/sales/{$sale['id']}/payments", ['amount' => 8, 'mode' => 'upi', 'paid_on' => $next])->assertCreated();
        $this->postJson('/api/pharmacy/collect', ['sale_ids' => [$sale['id']], 'amount' => 10, 'mode' => 'card', 'paid_on' => $next])->assertOk();
        $this->assertEquals(8, $this->report($next)['pharmacy']['collected']['upi']);
        $this->assertEquals(10, $this->report($next)['pharmacy']['collected']['card']);
        $this->assertEquals(0, $this->report(today()->toDateString())['pharmacy']['collected_total']);

        // A closed day refuses the bill, and the stock stays where it was.
        $this->close($this->then);
        $before = \App\Models\StockBatch::find($batch)->units_in_stock;
        $this->postJson('/api/pharmacy/sales', ['customer_name' => 'Walk-in Customer', 'paid' => 0, 'payment_mode' => 'cash', 'sold_on' => $this->then, 'items' => [['stock_batch_id' => $batch, 'quantity' => 5]]])
            ->assertStatus(423);
        $this->assertSame($before, \App\Models\StockBatch::find($batch)->units_in_stock);
    }

    public function test_a_deposit_can_be_entered_for_the_day_it_was_taken(): void
    {
        $this->postJson("/api/patients/{$this->patient->id}/deposits", ['type' => 'deposit', 'amount' => 1000, 'mode' => 'cash', 'paid_on' => $this->then])
            ->assertCreated()->assertJsonPath('deposit_balance', 1000);

        $deposit = PatientDeposit::first();
        $this->assertSame($this->then, $deposit->created_at->toDateString());
        $this->assertNotNull($deposit->entered_at);
        $this->assertEquals(1000, $this->report($this->then)['consultation']['collected']['cash']);
    }

    public function test_inpatient_charges_from_a_template_and_from_the_pharmacy_carry_the_earlier_day(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $case = $this->postJson('/api/ipd/admissions', [
            'patient_id' => $this->patient->id, 'doctor_id' => $doctor->id, 'bed_id' => Bed::create(['ward' => 'General Ward', 'bed_no' => '1', 'daily_charge' => 0])->id,
            'admitted_at' => today()->subDays(4)->format('Y-m-d').' 09:00', 'reason' => 'Fever for evaluation',
        ])->assertCreated()->json('id');

        $service = IpdService::create(['name' => 'Nursing charges', 'category' => 'Nursing', 'price' => 500]);
        $template = $this->postJson('/api/ipd/templates', ['name' => 'Daily care', 'items' => [['ipd_service_id' => $service->id, 'quantity' => 1]]])->assertCreated()->json('id');
        $this->postJson("/api/ipd/admissions/{$case}/templates/{$template}", ['charged_on' => $this->then])->assertCreated();
        $this->postJson("/api/ipd/admissions/{$case}/templates/{$template}", ['charged_on' => today()->addDay()->toDateString()])->assertStatus(422);
        $this->assertSame($this->then, AdmissionCharge::where('ipd_service_id', $service->id)->first()->charged_on->toDateString());

        // Medicines sent to the ward that day, billed now: the line on the IPD bill is of that day.
        $batch = $this->stock();
        $sale = ['customer_name' => $this->patient->name, 'patient_id' => $this->patient->id, 'admission_id' => $case, 'paid' => 0, 'payment_mode' => 'cash', 'items' => [['stock_batch_id' => $batch, 'quantity' => 10]]];
        $this->postJson('/api/pharmacy/sales', $sale + ['sold_on' => $this->then])->assertCreated();
        $this->assertSame($this->then, AdmissionCharge::where('source_type', 'pharmacy')->first()->charged_on->toDateString());

        // Nothing of a day before the patient was admitted can go on the case.
        $this->postJson('/api/pharmacy/sales', $sale + ['sold_on' => today()->subDays(6)->toDateString()])->assertStatus(422)->assertJsonValidationErrors('sold_on');
    }
}
