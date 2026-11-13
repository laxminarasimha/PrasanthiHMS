<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FormsTest extends TestCase
{
    use RefreshDatabase;

    private const FIELDS = [
        ['id' => 'vitals', 'label' => 'Vitals', 'type' => 'heading'],
        ['id' => 'pulse', 'label' => 'Pulse', 'type' => 'number'],
        ['id' => 'conscious', 'label' => 'Level of consciousness', 'type' => 'choice', 'options' => ['Alert', 'Drowsy', 'Unresponsive']],
        ['id' => 'fall_risk', 'label' => 'At risk of falls', 'type' => 'checkbox'],
        ['id' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
        ['id' => 'nurse', 'label' => 'Nurse', 'type' => 'signature'],
    ];

    public function test_a_form_is_designed_filled_in_for_an_ipd_case_and_keeps_its_questions(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/form-templates', ['name' => 'Initial assessment', 'access' => 'ipd', 'fields' => []])->assertStatus(422);
        $this->postJson('/api/form-templates', ['name' => 'Initial assessment', 'access' => 'ipd', 'fields' => [['id' => 'q', 'label' => 'Pick', 'type' => 'choice', 'options' => ['Only one']]]])->assertStatus(422);
        $template = $this->postJson('/api/form-templates', ['name' => 'Initial assessment', 'access' => 'ipd', 'fields' => self::FIELDS])->assertCreated()->assertJsonCount(6, 'fields')->json('id');
        $this->postJson('/api/form-templates', ['name' => 'Initial assessment', 'access' => 'both', 'fields' => self::FIELDS])->assertStatus(422);
        $doctorOnly = $this->postJson('/api/form-templates', ['name' => 'Clinic consent', 'access' => 'doctor', 'fields' => [['id' => 'a', 'label' => 'Agreed', 'type' => 'checkbox']]])->assertCreated()->json('id');

        $doctor = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs(User::factory()->create(['role' => 'nurse']));
        $case = $this->postJson('/api/ipd/admissions', ['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctor->id, 'admitted_at' => now()->format('Y-m-d H:i')])->assertCreated()->json('id');
        $forms = "/api/ipd/admissions/{$case}/forms";

        $this->postJson($forms, ['form_template_id' => $doctorOnly])->assertStatus(422);
        $this->postJson($forms, ['form_template_id' => $template, 'values' => ['conscious' => 'Asleep']])->assertStatus(422);
        $this->postJson($forms, ['form_template_id' => $template, 'values' => ['pulse' => 'fast']])->assertStatus(422);
        $record = $this->postJson($forms, ['form_template_id' => $template, 'values' => ['pulse' => 82, 'conscious' => 'Alert', 'fall_risk' => true, 'notes' => 'Walks with support', 'nurse' => 'ignored', 'not_a_question' => 'x']])
            ->assertCreated()->assertJsonPath('name', 'Initial assessment')->assertJsonPath('values.pulse', '82')->assertJsonPath('values.fall_risk', true)
            ->assertJsonMissingPath('values.nurse')->assertJsonMissingPath('values.not_a_question')->json('id');
        $this->getJson($forms)->assertOk()->assertJsonCount(1);
        $this->putJson("/api/forms/{$record}", ['values' => ['pulse' => 90]])->assertOk()->assertJsonPath('values.pulse', '90')->assertJsonMissingPath('values.notes');

        // Changing or hiding the template later does not change a form already filled in.
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson("/api/form-templates/{$template}", ['name' => 'Initial assessment', 'access' => 'ipd', 'is_active' => false, 'fields' => [['id' => 'only', 'label' => 'Only line', 'type' => 'text']]])->assertOk();
        $this->getJson("/api/forms/{$record}")->assertOk()->assertJsonCount(6, 'fields')->assertJsonPath('patient.id', fn ($id) => $id > 0)->assertJsonPath('admission.doctor.name', $doctor->name);
        $this->getJson('/api/form-templates')->assertJsonCount(1);
        $this->getJson('/api/form-templates?all=1')->assertJsonCount(2);
        $this->postJson($forms, ['form_template_id' => $template])->assertStatus(422); // hidden forms cannot be started

        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->getJson($forms)->assertOk();
        $this->deleteJson("/api/forms/{$record}")->assertForbidden();
        $this->postJson('/api/form-templates', ['name' => 'X', 'access' => 'both', 'fields' => self::FIELDS])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'nurse']));
        $this->deleteJson("/api/forms/{$record}")->assertNoContent();
    }
}
