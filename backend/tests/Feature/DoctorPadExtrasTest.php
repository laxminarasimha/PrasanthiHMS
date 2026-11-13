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

    public function test_a_doctor_sets_their_own_prescription_lists_translations_and_medicine_library(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs($doctor);
        $this->getJson('/api/doctor/library')->assertOk()->assertJsonPath('rx_settings.print_language', 'en')->assertJsonPath('rx_settings.timings.0', 'After food');

        $settings = ['frequencies' => ['1-0-1', 'Weekly twice'], 'timings' => ['After food', 'With milk'], 'print_language' => 'both',
            'translations' => [['phrase' => 'After food', 'translation' => 'భోజనం తర్వాత'], ['phrase' => 'after food', 'translation' => 'duplicate']], 'capitalize_name' => true];
        $this->putJson('/api/doctor/rx-settings', ['print_language' => 'klingon'] + $settings)->assertStatus(422);
        $this->putJson('/api/doctor/rx-settings', ['frequencies' => []] + $settings)->assertStatus(422);
        $this->putJson('/api/doctor/rx-settings', $settings)->assertOk()->assertJsonCount(1, 'translations')->assertJsonPath('frequencies.1', 'Weekly twice');

        // Whoever prints the prescription gets this doctor's print choices with the visit.
        $visit = Appointment::create(['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now(), 'status' => 'arrived']);
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson("/api/appointments/{$visit->id}/consultation")->assertOk()
            ->assertJsonPath('rx_print.print_language', 'both')->assertJsonPath('rx_print.translations.0.translation', 'భోజనం తర్వాత')->assertJsonPath('rx_print.capitalize_name', true);
        $this->putJson('/api/doctor/rx-settings', $settings)->assertForbidden();

        // The medicines library: add, change and remove how a medicine is usually given.
        Sanctum::actingAs($doctor);
        $id = $this->postJson('/api/doctor/medicine-defaults', ['medicine' => 'Sample Tablet 500mg', 'dose' => '1 tablet', 'frequency' => '1-0-1'])->assertCreated()->json('id');
        $this->postJson('/api/doctor/medicine-defaults', ['medicine' => 'Sample Tablet 500mg', 'duration' => '5 days'])->assertCreated()->assertJsonPath('id', $id);
        $this->putJson("/api/doctor/medicine-defaults/{$id}", ['medicine' => 'Sample Tablet 500mg', 'timing' => 'With milk'])->assertOk()->assertJsonPath('timing', 'With milk');
        $this->getJson('/api/doctor/library')->assertJsonCount(1, 'medicine_defaults')->assertJsonPath('medicine_defaults.0.id', $id);

        Sanctum::actingAs(User::factory()->create(['role' => 'doctor']));
        $this->deleteJson("/api/doctor/medicine-defaults/{$id}")->assertForbidden();
        Sanctum::actingAs($doctor);
        $this->deleteJson("/api/doctor/medicine-defaults/{$id}")->assertNoContent();
    }

    public function test_print_layout_and_seal_travel_with_the_visit(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $doctor = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs($doctor);
        $library = $this->getJson('/api/doctor/library')->assertOk()->assertJsonPath('rx_settings.print.font_size_pt', 11)->assertJsonPath('rx_settings.print.columns.dose', true)->assertJsonPath('has_seal', false)->json('rx_settings');

        $this->putJson('/api/doctor/rx-settings', ['print' => ['font_size_pt' => 40]] + $library)->assertStatus(422);
        $this->putJson('/api/doctor/rx-settings', ['print' => ['sections' => ['nonsense' => true]]] + $library)->assertStatus(422);
        // Only some choices are sent; the others keep their usual values.
        $this->putJson('/api/doctor/rx-settings', ['print' => ['letterhead' => true, 'margin_top_mm' => 45, 'font_size_pt' => 12, 'columns' => ['dose' => false], 'sections' => ['history' => false]]] + $library)
            ->assertOk()->assertJsonPath('print.letterhead', true)->assertJsonPath('print.margin_top_mm', 45)->assertJsonPath('print.margin_side_mm', 12)
            ->assertJsonPath('print.columns.dose', false)->assertJsonPath('print.columns.frequency', true)->assertJsonPath('print.sections.history', false)->assertJsonPath('print.sections.advice', true);

        $this->post('/api/doctor/seal', ['file' => \Illuminate\Http\UploadedFile::fake()->create('seal.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/doctor/seal', ['file' => \Illuminate\Http\UploadedFile::fake()->image('seal.png', 300, 120)], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('has_seal', true);

        $visit = Appointment::create(['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now(), 'status' => 'arrived']);
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson("/api/appointments/{$visit->id}/consultation")->assertOk()->assertJsonPath('rx_print.print.letterhead', true)->assertJsonPath('rx_print.print.margin_top_mm', 45);
        $this->get("/api/users/{$doctor->id}/seal")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->post('/api/doctor/seal', ['file' => \Illuminate\Http\UploadedFile::fake()->image('seal.png')], ['Accept' => 'application/json'])->assertForbidden();

        Sanctum::actingAs($doctor);
        $this->post('/api/doctor/seal', [], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('has_seal', false);
        $this->assertSame([], \Illuminate\Support\Facades\Storage::disk('local')->allFiles('staff-seals'));
    }
}
