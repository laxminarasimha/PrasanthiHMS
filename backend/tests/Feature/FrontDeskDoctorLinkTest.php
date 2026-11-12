<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FrontDeskDoctorLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $desk;

    private User $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->desk = User::factory()->create(['role' => 'frontdesk']);
        $this->doctor = User::factory()->create(['role' => 'doctor']);
        $this->patient = Patient::factory()->create();
        Sanctum::actingAs($this->desk);
    }

    private function book(string $when): int
    {
        return $this->postJson('/api/appointments', ['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'scheduled_at' => $when])
            ->assertCreated()->json('id');
    }

    public function test_the_desk_sees_what_the_doctor_decided(): void
    {
        $id = $this->book(now()->format('Y-m-d').' 10:30');
        $next = now()->addDays(5)->format('Y-m-d');

        $this->getJson('/api/appointments')->assertOk()->assertJsonPath('data.0.consultation', null);

        Sanctum::actingAs($this->doctor);
        $this->putJson("/api/appointments/{$id}/consultation", [
            'diagnosis' => 'Viral fever', 'tests_advised' => 'CBC', 'follow_up_on' => $next, 'refer_to' => 'ENT', 'complete' => true,
            'medicines' => [['medicine' => 'Paracetamol 500', 'frequency' => '1-0-1', 'duration' => '3 days']],
        ])->assertOk();

        Sanctum::actingAs($this->desk);
        $this->getJson('/api/appointments')->assertOk()
            ->assertJsonPath('data.0.status', 'reviewed')
            ->assertJsonPath('data.0.consultation.tests_advised', 'CBC')
            ->assertJsonPath('data.0.consultation.follow_up_on', $next)
            ->assertJsonPath('data.0.consultation.refer_to', 'ENT')
            ->assertJsonPath('data.0.consultation.medicines_count', 1)
            ->assertJsonMissingPath('data.0.consultation.diagnosis');
    }

    public function test_follow_ups_due_are_listed_until_they_are_booked(): void
    {
        $id = $this->book(now()->format('Y-m-d').' 10:30');
        $next = now()->addDays(5)->format('Y-m-d');

        Sanctum::actingAs($this->doctor);
        $this->putJson("/api/appointments/{$id}/consultation", ['diagnosis' => 'Viral fever', 'follow_up_on' => $next, 'complete' => true])->assertOk();

        Sanctum::actingAs($this->desk);
        $this->getJson('/api/front-desk/follow-ups?date='.now()->format('Y-m-d'))->assertOk()->assertJsonCount(0);
        $this->getJson('/api/front-desk/follow-ups?date='.$next)->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.patient.id', $this->patient->id)
            ->assertJsonPath('0.doctor.id', $this->doctor->id)
            ->assertJsonPath('0.booked', false);

        $this->book($next.' 11:00');
        $this->getJson('/api/front-desk/follow-ups?date='.$next)->assertJsonPath('0.booked', true);
    }

    public function test_the_doctor_sees_when_vitals_are_ready(): void
    {
        $id = $this->book(now()->format('Y-m-d').' 10:30');

        Sanctum::actingAs($this->doctor);
        $this->getJson('/api/doctor/queue')->assertOk()->assertJsonPath('0.vitals_ready', false)->assertJsonPath('0.token_no', 1);

        Sanctum::actingAs($this->desk);
        $this->putJson("/api/appointments/{$id}/vitals", ['pulse' => 82, 'weight_kg' => 64])->assertOk();

        Sanctum::actingAs($this->doctor);
        $this->getJson('/api/doctor/queue')->assertOk()->assertJsonPath('0.vitals_ready', true);
    }
}
