<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DoctorLeaveTest extends TestCase
{
    use RefreshDatabase;

    private function book(User $doctor, string $when)
    {
        return $this->postJson('/api/appointments', ['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctor->id, 'scheduled_at' => $when]);
    }

    public function test_a_doctor_on_leave_cannot_be_booked_until_the_leave_is_removed(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $doctor = User::factory()->create(['role' => 'doctor']);
        $other = User::factory()->create(['role' => 'doctor']);
        $this->book($doctor, '2026-11-17 11:00')->assertCreated();

        $block = $this->postJson('/api/doctor-blocks', ['doctor_id' => $doctor->id, 'from_date' => '2026-11-17', 'to_date' => '2026-11-18', 'reason' => 'Conference'])
            ->assertCreated()->assertJsonPath('booked', 1)->assertJsonPath('doctor.id', $doctor->id);

        $this->book($doctor, '2026-11-18 18:30')->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
        $this->book($doctor, '2026-11-19 10:30')->assertCreated();
        $this->book($other, '2026-11-18 18:30')->assertCreated();

        $this->getJson('/api/appointments/calendar?from=2026-11-16&days=7')->assertJsonCount(1, 'blocks')->assertJsonPath('blocks.0.reason', 'Conference');
        $this->getJson('/api/appointments/calendar?from=2026-11-19&days=3')->assertJsonCount(0, 'blocks');

        $this->deleteJson('/api/doctor-blocks/'.$block->json('id'))->assertNoContent();
        $this->book($doctor, '2026-11-18 18:30')->assertCreated();
    }

    public function test_part_of_a_day_can_be_blocked_and_moves_into_it_are_refused(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $doctor = User::factory()->create(['role' => 'doctor']);
        $this->postJson('/api/doctor-blocks', ['doctor_id' => $doctor->id, 'from_date' => '2026-11-17', 'to_date' => '2026-11-17', 'start_time' => '10:30', 'end_time' => '12:00'])->assertCreated();

        $this->book($doctor, '2026-11-17 11:45')->assertStatus(422);
        $id = $this->book($doctor, '2026-11-17 12:00')->assertCreated()->json('id');

        $this->putJson("/api/appointments/{$id}", ['scheduled_at' => '2026-11-17 10:30'])->assertStatus(422);
        // Edits that do not move the visit are still allowed.
        $this->putJson("/api/appointments/{$id}", ['notes' => 'Bring old reports'])->assertOk();

        $this->postJson('/api/doctor-blocks', ['doctor_id' => $doctor->id, 'from_date' => '2026-11-17', 'to_date' => '2026-11-16'])->assertStatus(422);
        $this->postJson('/api/doctor-blocks', ['doctor_id' => $doctor->id, 'from_date' => '2026-11-17', 'to_date' => '2026-11-17', 'start_time' => '12:00', 'end_time' => '11:00'])->assertStatus(422);

        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->postJson('/api/doctor-blocks', ['doctor_id' => $doctor->id, 'from_date' => '2026-11-20', 'to_date' => '2026-11-20'])->assertForbidden();
    }

    public function test_a_slot_takes_only_as_many_patients_as_the_limit_allows(): void
    {
        $hours = ['slot_minutes' => 15, 'slot_limit' => 2, 'morning_start' => '10:30', 'morning_end' => '14:00', 'evening_start' => '18:00', 'evening_end' => '21:00'];
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson('/api/appointments/calendar-settings', $hours)->assertOk()->assertJsonPath('slot_limit', 2);
        $doctor = User::factory()->create(['role' => 'doctor']);

        $first = $this->book($doctor, '2026-11-17 10:30')->assertCreated()->json('id');
        $this->book($doctor, '2026-11-17 10:40')->assertCreated();
        $this->book($doctor, '2026-11-17 10:44')->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
        $this->book($doctor, '2026-11-17 10:45')->assertCreated();
        $this->book(User::factory()->create(['role' => 'doctor']), '2026-11-17 10:30')->assertCreated();

        // A cancelled visit gives its place back.
        $this->putJson("/api/appointments/{$first}", ['status' => 'cancelled'])->assertOk();
        $this->book($doctor, '2026-11-17 10:44')->assertCreated();

        $this->putJson('/api/appointments/calendar-settings', ['slot_limit' => 0] + $hours)->assertOk();
        $this->book($doctor, '2026-11-17 10:31')->assertCreated();
    }

    public function test_patients_can_be_looked_up_by_exact_mobile_number(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        Patient::factory()->create(['mobile' => '9000000001']);
        Patient::factory()->create(['mobile' => '9000000002', 'mobile_alt' => '9000000001']);
        Patient::factory()->create(['mobile' => '9000000003']);

        $this->getJson('/api/patients?mobile=9000000001')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/patients?mobile=900000000')->assertOk()->assertJsonCount(0, 'data');
    }
}
