<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Bed;
use App\Models\LabTest;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\Service;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_splits_billing_and_collections_by_department_and_mode(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $doctor = User::factory()->create(['role' => 'doctor']);
        $patient = Patient::factory()->create();

        // Front desk: 200 billed, 150 collected in cash.
        $bill = $this->postJson('/api/appointments', [
            'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now()->format('Y-m-d H:i'),
            'service_id' => Service::factory()->create(['price' => 200])->id,
        ])->json('bills.0.id');
        $this->postJson("/api/bills/{$bill}/payments", ['type' => 'payment', 'amount' => 150, 'mode' => 'cash'])->assertCreated();

        // Lab: 300 billed, 300 collected by UPI.
        $test = LabTest::create(['name' => 'CBP', 'price' => 300]);
        $test->parameters()->create(['name' => 'Haemoglobin']);
        $labBill = $this->postJson('/api/lab/orders', ['patient_id' => $patient->id, 'test_ids' => [$test->id]])->json('bill.id');
        $this->postJson("/api/bills/{$labBill}/payments", ['type' => 'payment', 'amount' => 300, 'mode' => 'upi'])->assertCreated();

        // Pharmacy: 28 billed and paid by card.
        $medicine = Medicine::create(['name' => 'Paracetamol', 'gst_percent' => 12, 'units_per_pack' => 10]);
        $batch = StockBatch::create([
            'medicine_id' => $medicine->id, 'batch_no' => 'B1', 'expiry' => now()->addYear(), 'units_per_pack' => 10, 'packs' => 5,
            'pack_price' => 15, 'pack_mrp' => 28, 'unit_mrp' => 2.8, 'gst_percent' => 12, 'units_received' => 50, 'units_in_stock' => 50,
        ]);
        $this->postJson('/api/pharmacy/sales', [
            'customer_name' => 'Walk-in', 'paid' => 28, 'payment_mode' => 'card', 'items' => [['stock_batch_id' => $batch->id, 'quantity' => 10]],
        ])->assertCreated();

        // IPD: 1000 charged, 5000 advance in cash less a 500 refund.
        $admission = $this->postJson('/api/ipd/admissions', [
            'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'bed_id' => Bed::create(['ward' => 'General', 'bed_no' => '1'])->id,
            'admitted_at' => now()->format('Y-m-d H:i'), // not an hour ago: just after midnight that would be yesterday,
        ])->json('id');
        $this->postJson("/api/ipd/admissions/{$admission}/charges", ['description' => 'Nursing', 'charged_on' => now()->toDateString(), 'quantity' => 2, 'unit_price' => 500])->assertCreated();
        $this->postJson("/api/ipd/admissions/{$admission}/payments", ['type' => 'advance', 'amount' => 5000, 'mode' => 'cash'])->assertCreated();
        $this->postJson("/api/ipd/admissions/{$admission}/payments", ['type' => 'refund', 'amount' => 500, 'mode' => 'cash'])->assertCreated();

        $this->getJson('/api/reports/summary')->assertOk()
            ->assertJsonPath('departments.consultation.billed', 200)
            ->assertJsonPath('departments.consultation.collected.cash', 150)
            ->assertJsonPath('departments.lab.billed', 300)
            ->assertJsonPath('departments.lab.collected.upi', 300)
            ->assertJsonPath('departments.lab.collected.cash', 0)
            ->assertJsonPath('departments.pharmacy.billed', 28)
            ->assertJsonPath('departments.pharmacy.collected.card', 28)
            ->assertJsonPath('departments.ipd.billed', 1000)
            ->assertJsonPath('departments.ipd.collected.cash', 4500)
            ->assertJsonPath('departments.ipd.collected_total', 4500)
            ->assertJsonPath('counts.new_patients', 1)
            ->assertJsonPath('counts.appointments', 1)
            ->assertJsonPath('counts.lab_orders', 1)
            ->assertJsonPath('counts.pharmacy_bills', 1)
            ->assertJsonPath('counts.admissions', 1)
            ->assertJsonPath('counts.inpatients_now', 1)
            ->assertJsonPath('counts.consultations', 0)
            ->assertJsonPath('counts.visit_pads', 0);

        // The desk marks the patient as seen by a doctor who wrote the notes elsewhere: the visit
        // counts as a consultation, though no visit pad was written.
        Appointment::firstOrFail()->update(['status' => 'reviewed']);
        $this->getJson('/api/reports/summary')->assertOk()
            ->assertJsonPath('counts.consultations', 1)
            ->assertJsonPath('counts.visit_pads', 0);
        $this->getJson('/api/reports/list/consultations')->assertOk()
            ->assertJsonPath('rows.0.total', 1)->assertJsonPath('rows.0.new', 1)->assertJsonPath('rows.0.repeat', 0)
            ->assertJsonPath('rows.0.doctor', $doctor->name);

        // Nothing happened last week.
        $lastWeek = now()->subWeek()->toDateString();
        $this->getJson("/api/reports/summary?from={$lastWeek}&to={$lastWeek}")->assertOk()
            ->assertJsonPath('departments.consultation.billed', 0)
            ->assertJsonPath('departments.ipd.collected_total', 0)
            ->assertJsonPath('counts.appointments', 0);
    }

    public function test_only_admin_and_doctor_can_view_reports(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson('/api/reports/summary')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'doctor']));
        $this->getJson('/api/reports/summary')->assertOk();
    }

    public function test_the_owner_overview_gives_the_trend_what_is_owed_and_each_doctors_work(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Sample']);
        $patient = Patient::factory()->create();

        // Front desk: 200 billed, 150 paid, so 50 is owed.
        $bill = $this->postJson('/api/appointments', [
            'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now()->format('Y-m-d H:i'),
            'service_id' => Service::factory()->create(['price' => 200])->id,
        ])->json('bills.0.id');
        $this->postJson("/api/bills/{$bill}/payments", ['type' => 'payment', 'amount' => 150, 'mode' => 'cash'])->assertCreated();

        // Lab: 300 billed, nothing paid.
        $test = LabTest::create(['name' => 'CBP', 'price' => 300]);
        $test->parameters()->create(['name' => 'Haemoglobin']);
        $this->postJson('/api/lab/orders', ['patient_id' => $patient->id, 'test_ids' => [$test->id]])->assertCreated();

        // Pharmacy: 28 billed, 8 paid.
        $medicine = Medicine::create(['name' => 'Paracetamol', 'gst_percent' => 12, 'units_per_pack' => 10]);
        $batch = StockBatch::create([
            'medicine_id' => $medicine->id, 'batch_no' => 'B1', 'expiry' => now()->addYear(), 'units_per_pack' => 10, 'packs' => 5,
            'pack_price' => 15, 'pack_mrp' => 28, 'unit_mrp' => 2.8, 'gst_percent' => 12, 'units_received' => 50, 'units_in_stock' => 50,
        ]);
        $this->postJson('/api/pharmacy/sales', ['customer_name' => 'Walk-in', 'paid' => 8, 'payment_mode' => 'cash', 'items' => [['stock_batch_id' => $batch->id, 'quantity' => 10]]])->assertCreated();

        // IPD: 1000 charged, 400 advance, still admitted.
        $admission = $this->postJson('/api/ipd/admissions', [
            'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'bed_id' => Bed::create(['ward' => 'General', 'bed_no' => '1'])->id, 'admitted_at' => now()->format('Y-m-d H:i'),
        ])->json('id');
        $this->postJson("/api/ipd/admissions/{$admission}/charges", ['description' => 'Nursing', 'charged_on' => now()->toDateString(), 'quantity' => 2, 'unit_price' => 500])->assertCreated();
        $this->postJson("/api/ipd/admissions/{$admission}/payments", ['type' => 'advance', 'amount' => 400, 'mode' => 'cash'])->assertCreated();

        $data = $this->getJson('/api/reports/owner?days=7')->assertOk()
            ->assertJsonCount(7, 'trend')
            ->assertJsonPath('trend.6.day', now()->toDateString())
            ->assertJsonPath('trend.6.billed', 1528)
            ->assertJsonPath('trend.6.collected', 558)
            ->assertJsonPath('trend.5.billed', 0)
            ->assertJsonPath('owed_total', 970)
            ->assertJsonPath('doctors.0.name', 'Dr Sample')
            ->assertJsonPath('doctors.0.booked', 1)
            ->assertJsonPath('doctors.0.billed', 200)
            ->assertJsonPath('doctors.0.inpatients', 1)
            ->json();
        $owed = collect($data['owed'])->pluck('owed', 'key');
        $this->assertEquals(['front_desk' => 50, 'lab' => 300, 'pharmacy' => 20, 'ipd_admitted' => 600, 'ipd_closed' => 0], $owed->all());

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson('/api/reports/owner')->assertForbidden();
    }
}
