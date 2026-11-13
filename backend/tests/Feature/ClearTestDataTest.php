<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Bed;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClearTestDataTest extends TestCase
{
    use RefreshDatabase;

    private function practiceData(): User
    {
        $admin = User::factory()->create(['role' => 'admin', 'email' => 'owner@hospital.example']);
        $sample = User::factory()->create(['role' => 'doctor', 'email' => 'doctor@prasanthi.test']);
        Sanctum::actingAs($admin);
        $patient = Patient::factory()->create();
        $service = Service::create(['name' => 'Consultation', 'price' => 400, 'gst_percent' => 0]);
        $this->postJson('/api/appointments', ['patient_id' => $patient->id, 'doctor_id' => $sample->id, 'service_id' => $service->id, 'scheduled_at' => now()->addHour()->format('Y-m-d H:i'), 'paid' => 400, 'payment_mode' => 'cash'])->assertCreated();
        LabOrder::create(['patient_id' => $patient->id, 'status' => 'pending', 'ordered_at' => now()])->items()->create(['test_name' => 'RBS', 'price' => 80]);
        $bed = Bed::create(['ward' => 'General Ward', 'bed_no' => '1', 'daily_charge' => 800]);
        $this->postJson('/api/ipd/admissions', ['patient_id' => $patient->id, 'doctor_id' => $sample->id, 'bed_id' => $bed->id, 'admitted_at' => now()->format('Y-m-d H:i'), 'reason' => 'Practice'])->assertCreated();

        return $admin;
    }

    public function test_without_go_it_only_counts(): void
    {
        $this->practiceData();

        $this->artisan('hms:clear-test-data')->expectsOutputToContain('Nothing was changed')->assertExitCode(0);
        $this->assertSame(1, Patient::count());
        $this->assertSame(1, Appointment::count());
    }

    public function test_it_needs_the_word_clear_and_then_removes_patient_records_but_keeps_setup(): void
    {
        $admin = $this->practiceData();

        $this->artisan('hms:clear-test-data --go')->expectsQuestion('This cannot be undone. Type CLEAR to delete these records from '.\DB::connection()->getDatabaseName(), 'yes')->assertExitCode(1);
        $this->assertSame(1, Patient::count());

        $this->artisan('hms:clear-test-data --go')->expectsQuestion('This cannot be undone. Type CLEAR to delete these records from '.\DB::connection()->getDatabaseName(), 'CLEAR')->assertExitCode(0);

        foreach (['patients', 'appointments', 'bills', 'bill_items', 'payments', 'lab_orders', 'lab_order_items', 'admissions'] as $table) {
            $this->assertSame(0, \DB::table($table)->count(), $table);
        }
        // Setup stays, the real login stays, the practice login goes.
        $this->assertSame(1, Service::count());
        $this->assertSame(1, Bed::count());
        $this->assertSame([$admin->email], User::pluck('email')->all());

        // Numbers start again from 1, and the freed bed can be used.
        $this->assertSame(1, Patient::factory()->create()->id);
    }
}
