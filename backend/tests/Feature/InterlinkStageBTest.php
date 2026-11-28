<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Bed;
use App\Models\DoctorBlock;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Stage B of interlinking: every name and number opens what it stands for. */
class InterlinkStageBTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_doctors_name_opens_their_card_with_what_each_person_may_see(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Sample Card', 'phone' => '9000000001', 'department' => 'General Medicine',
            'availability' => [strtolower(now()->format('D')) => [['from' => '09:00', 'to' => '13:00']]]]);
        $waiting = Appointment::create(['patient_id' => Patient::factory()->create(['name' => 'Sample Waiting'])->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now(), 'status' => 'arrived', 'token_no' => 3]);
        Appointment::create(['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now(), 'status' => 'reviewed', 'token_no' => 1]);
        Appointment::create(['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now(), 'status' => 'cancelled']);
        $bed = Bed::create(['ward' => 'General Ward', 'bed_no' => '7', 'daily_charge' => 800]);
        $stay = Admission::create(['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctor->id, 'bed_id' => $bed->id, 'admitted_at' => now()->subDay(), 'status' => 'admitted']);
        DoctorBlock::create(['doctor_id' => $doctor->id, 'from_date' => today()->addDays(3), 'to_date' => today()->addDays(4), 'reason' => 'Conference']);

        // The front desk: the day, hours, phone, leave and inpatients; not today's money.
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $card = $this->getJson("/api/doctors/{$doctor->id}/card")->assertOk()
            ->assertJsonPath('doctor.name', 'Dr Sample Card')->assertJsonPath('doctor.phone', '9000000001')->assertJsonPath('doctor.hours_today.0.from', '09:00')
            ->assertJsonPath('today.waiting', 1)->assertJsonPath('today.seen', 1)->assertJsonPath('today.booked', 0)
            ->assertJsonPath('today.queue.0.id', $waiting->id)->assertJsonPath('today.queue.0.patient.name', 'Sample Waiting')
            ->assertJsonPath('away.0.reason', 'Conference')->assertJsonPath('inpatients.0.id', $stay->id)->assertJsonPath('inpatients.0.bed', 'General Ward · Bed 7')
            ->assertJsonPath('reps_waiting', [])->json();
        $this->assertArrayNotHasKey('money_today', $card);
        $this->assertArrayNotHasKey('results_unseen', $card);

        // The admin also sees what the doctor has brought in today.
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson("/api/doctors/{$doctor->id}/card")->assertOk()->assertJsonPath('money_today.collected', 0)->assertJsonPath('results_unseen', 0);

        // A login that only keeps its own attendance cannot open it; a name that is not a doctor's is not a doctor card.
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $this->getJson("/api/doctors/{$doctor->id}/card")->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson('/api/doctors/'.User::factory()->create(['role' => 'nurse'])->id.'/card')->assertNotFound();
    }
}
