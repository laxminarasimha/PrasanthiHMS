<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ConsultationTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->doctor = User::factory()->create(['role' => 'doctor']);
        $this->appointment = $this->appointmentFor($this->doctor);
        Sanctum::actingAs($this->doctor);
    }

    private function appointmentFor(User $doctor, ?Patient $patient = null): Appointment
    {
        return Appointment::create([
            'patient_id' => ($patient ?? Patient::factory()->create())->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => now(),
            'status' => 'arrived',
        ]);
    }

    private function url(?Appointment $appointment = null): string
    {
        return '/api/appointments/'.($appointment ?? $this->appointment)->id.'/consultation';
    }

    public function test_blank_visit_pad_is_returned_before_anything_is_saved(): void
    {
        $this->getJson($this->url())->assertOk()
            ->assertJsonPath('consultation', null)
            ->assertJsonPath('patient.id', $this->appointment->patient_id)
            ->assertJsonPath('appointment.scheduled_at', $this->appointment->scheduled_at->format('Y-m-d H:i'));
    }

    public function test_saving_a_draft_marks_the_visit_ongoing(): void
    {
        $this->putJson($this->url(), ['complaints' => 'Fever for 3 days', 'bp_systolic' => 120, 'bp_diastolic' => 80])
            ->assertOk()
            ->assertJsonPath('consultation.complaints', 'Fever for 3 days')
            ->assertJsonPath('consultation.completed_at', null)
            ->assertJsonPath('appointment.status', 'ongoing');
    }

    public function test_completing_saves_medicines_in_order_and_marks_reviewed(): void
    {
        $response = $this->putJson($this->url(), [
            'diagnosis' => 'Viral fever',
            'complete' => true,
            'medicines' => [
                ['medicine' => 'Paracetamol 650', 'dose' => '1 tablet', 'frequency' => '1-1-1', 'duration' => '3 days'],
                ['medicine' => 'Cetirizine 10', 'frequency' => '0-0-1', 'duration' => '5 days'],
            ],
        ])->assertOk()->assertJsonPath('appointment.status', 'reviewed');

        $this->assertNotNull($response->json('consultation.completed_at'));
        $this->assertSame(['Paracetamol 650', 'Cetirizine 10'], array_column($response->json('consultation.medicines'), 'medicine'));

        // Saving again replaces the list rather than adding to it.
        $this->putJson($this->url(), ['medicines' => [['medicine' => 'Paracetamol 650']]])
            ->assertOk()->assertJsonCount(1, 'consultation.medicines');
    }

    public function test_invalid_vitals_and_empty_medicine_rows_are_rejected(): void
    {
        $this->putJson($this->url(), ['bp_systolic' => 120, 'spo2' => 140, 'medicines' => [['medicine' => '']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bp_diastolic', 'spo2', 'medicines.0.medicine']);
    }

    public function test_front_desk_staff_cannot_write_notes_but_nurses_can(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->putJson($this->url(), ['complaints' => 'x'])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'nurse']));
        $this->putJson($this->url(), ['pulse' => 78])->assertOk()->assertJsonPath('consultation.pulse', 78);
    }

    public function test_doctor_queue_shows_only_their_patients_with_past_visit_counts(): void
    {
        $this->appointmentFor(User::factory()->create(['role' => 'doctor']));
        $this->putJson($this->url(), ['complete' => true])->assertOk();
        $second = $this->appointmentFor($this->doctor, $this->appointment->patient);

        $queue = $this->getJson('/api/doctor/queue')->assertOk()->assertJsonCount(2)->json();

        $this->assertSame(1, $queue[0]['visits']);
        $this->assertSame(now()->toDateString(), $queue[0]['last_visit']);
        $this->assertSame($second->id, $queue[1]['id']);
    }

    public function test_history_lists_completed_visits_and_medicines_are_suggested(): void
    {
        $this->putJson($this->url(), ['complete' => true, 'diagnosis' => 'Gastritis', 'medicines' => [['medicine' => 'Pantoprazole 40']]])->assertOk();
        $draft = $this->appointmentFor($this->doctor, $this->appointment->patient);
        $this->putJson($this->url($draft), ['complaints' => 'still a draft'])->assertOk();

        $this->getJson("/api/patients/{$this->appointment->patient_id}/consultations")
            ->assertOk()->assertJsonCount(1)->assertJsonPath('0.diagnosis', 'Gastritis');
        $this->getJson('/api/medicines/suggest?q=panto')->assertOk()->assertExactJson(['Pantoprazole 40']);
    }
}
