<?php

namespace Tests\Feature;

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
            ->assertJsonPath('counts.inpatients_now', 1);

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
}
