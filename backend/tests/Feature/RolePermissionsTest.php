<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Bed;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * What each role may do. true = allowed, false = refused with 403.
     * Columns: change setup, book appointments, work IPD, write visit notes, lab results, sell medicines, staff and reports.
     */
    public static function roles(): array
    {
        return [
            'admin' => ['admin', [true, true, true, true, true, true, true]],
            'doctor' => ['doctor', [true, true, true, true, true, true, true]],
            'nurse' => ['nurse', [false, true, true, true, false, false, false]],
            'frontdesk' => ['frontdesk', [false, true, true, false, false, false, false]],
            'lab technician' => ['lab_tech', [false, false, false, false, true, false, false]],
            'pharmacist' => ['pharmacist', [false, false, false, false, false, true, false]],
        ];
    }

    #[DataProvider('roles')]
    public function test_each_role_can_do_only_its_own_work(string $role, array $allowed): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $patient = Patient::factory()->create();
        $appointment = Appointment::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now(), 'status' => 'arrived']);
        Bed::create(['ward' => 'General', 'bed_no' => '1']);
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        // Each request is deliberately incomplete: a permitted role gets a validation error (422), a barred one gets 403.
        $attempts = [
            fn () => $this->postJson('/api/services', []),
            fn () => $this->postJson('/api/appointments', []),
            fn () => $this->postJson('/api/ipd/admissions', []),
            fn () => $this->putJson("/api/appointments/{$appointment->id}/consultation", ['spo2' => 999]),
            fn () => $this->postJson('/api/lab/tests', []),
            fn () => $this->postJson('/api/pharmacy/sales', []),
            fn () => $this->postJson('/api/staff', []),
        ];

        foreach ($attempts as $index => $attempt) {
            $attempt()->assertStatus($allowed[$index] ? 422 : 403);
        }

        $this->getJson('/api/ipd/beds')->assertOk();
        $this->getJson('/api/reports/summary')->assertStatus($allowed[6] ? 200 : 403);
        $this->getJson('/api/me')->assertOk()
            ->assertJsonPath('can_manage_setup', $allowed[0])
            ->assertJsonPath('can_front_desk', $allowed[1])
            ->assertJsonPath('can_ipd', $allowed[2]);
    }
}
