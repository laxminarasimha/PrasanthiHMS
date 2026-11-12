<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    private function book(User $doctor, string $when): void
    {
        $this->postJson('/api/appointments', [
            'patient_id' => Patient::factory()->create()->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $when,
        ])->assertCreated();
    }

    public function test_calendar_lists_a_week_and_can_be_narrowed_to_one_doctor(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $first = User::factory()->create(['role' => 'doctor']);
        $second = User::factory()->create(['role' => 'doctor']);

        $this->book($first, '2026-11-16 10:30');
        $this->book($first, '2026-11-18 18:15');
        $this->book($second, '2026-11-22 23:45');
        $this->book($second, '2026-11-23 00:00'); // the day after the week ends
        $this->book($second, '2026-11-15 20:00'); // the day before it starts

        $this->getJson('/api/appointments/calendar?from=2026-11-16&days=7')->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.scheduled_at', '2026-11-16 10:30')
            ->assertJsonPath('settings.slot_minutes', 15)
            ->assertJsonPath('settings.morning_start', '10:30');

        $this->getJson('/api/appointments/calendar?from=2026-11-16&days=7&doctor_id='.$second->id)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/appointments/calendar?from=2026-11-16')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/appointments/calendar')->assertStatus(422);
    }

    public function test_opd_hours_can_be_changed_by_those_who_manage_setup_only(): void
    {
        $hours = ['slot_minutes' => 10, 'morning_start' => '09:00', 'morning_end' => '13:00', 'evening_start' => '17:00', 'evening_end' => '20:30'];

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->putJson('/api/appointments/calendar-settings', $hours)->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson('/api/appointments/calendar-settings', $hours)->assertOk()->assertJsonPath('slot_minutes', 10);
        $this->getJson('/api/appointments/calendar?from=2026-11-16')->assertJsonPath('settings.evening_end', '20:30');

        // Sessions must run forwards and not overlap.
        $this->putJson('/api/appointments/calendar-settings', ['morning_end' => '08:00'] + $hours)->assertStatus(422);
        $this->putJson('/api/appointments/calendar-settings', ['evening_start' => '12:00'] + $hours)->assertStatus(422);
        $this->putJson('/api/appointments/calendar-settings', ['slot_minutes' => 7] + $hours)->assertStatus(422);
    }
}
