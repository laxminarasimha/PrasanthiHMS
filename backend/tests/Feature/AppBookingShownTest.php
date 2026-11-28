<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A visit the patient booked on the patient app carries that mark to every staff list the web shows it on, so the
 * screens can say so. The public waiting-room board never carries it, nor the note the patient typed.
 */
class AppBookingShownTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visit_booked_from_the_patient_app_is_marked_wherever_staff_see_it(): void
    {
        $this->travelTo('2026-11-24 09:00');
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Sample Rao']);
        $this->postJson('/api/app/register', [
            'name' => 'Sample Mother', 'mobile' => '9876543210', 'gender' => 'female', 'date_of_birth' => '1970-05-01', 'password' => 'Health2026', 'password_confirmation' => 'Health2026',
        ])->assertCreated();
        $account = PatientAccount::where('mobile', '9876543210')->first();
        Sanctum::actingAs($account);
        $fromApp = $this->postJson('/api/app/appointments', ['doctor_id' => $doctor->id, 'scheduled_at' => '2026-11-24 10:45', 'notes' => 'Sample fever for three days'])->assertCreated()->json('id');

        // The desk books another patient with the same doctor.
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $walkIn = Patient::factory()->create(['name' => 'Sample Walk In']);
        $atDesk = $this->postJson('/api/appointments', [
            'patient_id' => $walkIn->id, 'doctor_id' => $doctor->id, 'scheduled_at' => '2026-11-24 11:00', 'service_id' => Service::factory()->create(['price' => 200])->id,
        ])->assertCreated()->json('id');
        $this->assertNull(Appointment::find($atDesk)->booked_via);

        $via = fn (array $rows) => collect($rows)->mapWithKeys(fn (array $row) => [$row['id'] => $row['booked_via']])->all();
        $expected = [$fromApp => 'app', $atDesk => null];

        // The front desk's day list and the calendar.
        $this->assertSame($expected, $via($this->getJson('/api/appointments?date=2026-11-24')->assertOk()->json('data')));
        $this->assertSame($expected, $via($this->getJson('/api/appointments/calendar?from=2026-11-24&days=1')->assertOk()->json('data')));
        // Each patient's page.
        $this->getJson("/api/patients/{$account->patient_id}/overview")->assertOk()->assertJsonPath('visits.0.id', $fromApp)->assertJsonPath('visits.0.booked_via', 'app');
        $this->getJson("/api/patients/{$walkIn->id}/overview")->assertOk()->assertJsonPath('visits.0.id', $atDesk)->assertJsonPath('visits.0.booked_via', null);

        // The doctor's own queue.
        Sanctum::actingAs($doctor);
        $this->assertSame($expected, $via($this->getJson('/api/doctor/queue?date=2026-11-24')->assertOk()->json()));

        // Once both are here, the waiting-room board shows a token and a name only: no mark, no note.
        Appointment::whereKey([$fromApp, $atDesk])->update(['status' => 'arrived', 'arrived_at' => '2026-11-24 10:30']);
        $board = $this->getJson('/api/front-desk/queue?date=2026-11-24')->assertOk()->json();
        $this->assertSame(['token', 'name'], array_keys($board[0]['waiting'][0]));
        $this->assertCount(2, $board[0]['waiting']);
        $words = json_encode($board);
        $this->assertStringNotContainsString('booked_via', $words);
        $this->assertStringNotContainsString('Sample fever', $words);
    }
}
