<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\DoctorLibraryController as Library;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DoctorPadExtrasTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_doctor_keeps_their_own_order_of_pad_sections(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs($doctor);
        $this->getJson('/api/doctor/library')->assertOk()->assertJsonPath('pad_section_order', Library::PAD_ORDER);

        $wanted = ['vitals', 'complaints', 'prescription', 'quick_notes', 'history', 'past_medication', 'general_exam', 'systemic_exam', 'diagnosis', 'assessment_plan', 'tests', 'results', 'advice', 'follow_up'];
        $order = $this->putJson('/api/doctor/pad-sections', ['hidden' => ['history'], 'order' => $wanted])
            ->assertOk()->assertJsonPath('pad_hidden_sections', ['history'])->assertJsonPath('pad_section_order', $wanted)->json('pad_section_order');

        // A list that leaves parts out still gives back every part exactly once.
        $partial = $this->putJson('/api/doctor/pad-sections', ['hidden' => ['history'], 'order' => ['prescription', 'vitals']])->assertOk()->json('pad_section_order');
        $this->assertEqualsCanonicalizing(Library::PAD_ORDER, $partial);
        $this->assertLessThan(array_search('vitals', $partial), array_search('prescription', $partial));
        $this->putJson('/api/doctor/pad-sections', ['hidden' => ['history'], 'order' => $wanted])->assertOk();
        $this->getJson('/api/doctor/library')->assertJsonPath('pad_section_order', $order);

        // Hiding a section later does not disturb the order.
        $this->putJson('/api/doctor/pad-sections', ['hidden' => []])->assertOk()->assertJsonPath('pad_section_order', $order);
        $this->putJson('/api/doctor/pad-sections', ['hidden' => [], 'order' => ['vitals', 'nonsense']])->assertStatus(422);

        Sanctum::actingAs(User::factory()->create(['role' => 'doctor']));
        $this->getJson('/api/doctor/library')->assertJsonPath('pad_section_order', Library::PAD_ORDER);
    }

    public function test_results_from_outside_reports_can_be_typed_into_the_visit(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs($doctor);
        $visit = Appointment::create(['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now(), 'status' => 'arrived']);
        $url = "/api/appointments/{$visit->id}/consultation";

        $this->putJson($url, ['investigations' => [['name' => 'HbA1c', 'result' => '7.2 %', 'date' => now()->subDays(3)->toDateString()], ['name' => 'Chest X-ray', 'result' => 'Normal']]])
            ->assertOk()->assertJsonCount(2, 'consultation.investigations')
            ->assertJsonPath('consultation.investigations.0.name', 'HbA1c')->assertJsonPath('consultation.investigations.1.date', null);

        // Saving other parts of the pad leaves them alone; an empty list clears them.
        $this->putJson($url, ['complaints' => 'Tiredness'])->assertOk()->assertJsonCount(2, 'consultation.investigations');
        $this->putJson($url, ['investigations' => [['result' => '5']]])->assertStatus(422);
        $this->putJson($url, ['investigations' => [['name' => 'TSH', 'date' => now()->addDay()->toDateString()]]])->assertStatus(422);
        $this->putJson($url, ['investigations' => []])->assertOk()->assertJsonPath('consultation.investigations', null);
    }
}
