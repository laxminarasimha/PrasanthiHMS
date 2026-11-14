<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\PatientDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_doctor_issues_certificates_that_cannot_be_changed_only_cancelled(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Rao']);
        $patient = Patient::factory()->create(['name' => 'Sita Devi']);
        Sanctum::actingAs($doctor);
        $visit = $this->postJson('/api/doctor/visits', ['patient_id' => $patient->id])->json('id');
        $this->putJson("/api/appointments/{$visit}/consultation", ['complaints' => 'Fever 3 days', 'diagnosis' => 'Viral fever', 'medicines' => [['medicine' => 'Paracetamol 650', 'frequency' => '1-1-1', 'duration' => '3 days']]])->assertOk();
        $url = "/api/patients/{$patient->id}/documents";

        $draft = $this->getJson("{$url}/draft")->assertOk()->assertJsonCount(5, 'kinds')->assertJsonPath('me', $doctor->id)->json();
        $this->assertSame('Viral fever', $draft['last_visit']['diagnosis']);
        $this->assertSame(['Paracetamol 650 1-1-1 3 days'], $draft['last_visit']['medicines']);

        $body = 'This is to certify that Sita Devi was examined on '.today()->toDateString().' and is advised rest for three days.';
        $base = ['kind' => 'medical', 'title' => 'Medical certificate', 'body' => $body, 'issued_on' => today()->toDateString(), 'doctor_id' => $doctor->id, 'appointment_id' => $visit];
        $this->postJson($url, ['body' => 'Too short'] + $base)->assertStatus(422)->assertJsonValidationErrors('body');
        $this->postJson($url, ['issued_on' => today()->subMonth()->toDateString()] + $base)->assertStatus(422)->assertJsonValidationErrors('issued_on'); // no back-dating
        $this->postJson($url, ['issued_on' => today()->addDay()->toDateString()] + $base)->assertStatus(422);
        $this->postJson($url, ['kind' => 'gossip'] + $base)->assertStatus(422);

        $first = $this->postJson($url, $base)->assertCreated()->assertJsonPath('doc_no', 'MC00001')->assertJsonPath('doctor.name', 'Dr Rao')->json();
        $this->postJson($url, ['kind' => 'referral', 'title' => 'Referral letter', 'addressed_to' => 'Dr Kumar, Cardiology'] + $base)->assertCreated()->assertJsonPath('doc_no', 'RL00002');
        $this->getJson($url)->assertOk()->assertJsonCount(2);
        $this->getJson("/api/documents/{$first['id']}")->assertOk()->assertJsonPath('patient.name', 'Sita Devi')->assertJsonPath('body', $body);

        // There is no way to edit an issued document.
        $this->putJson("/api/documents/{$first['id']}", ['body' => 'Changed'])->assertStatus(405);
        $this->postJson("/api/documents/{$first['id']}/cancel", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->postJson("/api/documents/{$first['id']}/cancel", ['reason' => 'Wrong dates'])->assertOk()->assertJsonPath('cancel_reason', 'Wrong dates')->assertJsonPath('canceller.name', 'Dr Rao');
        $this->postJson("/api/documents/{$first['id']}/cancel", ['reason' => 'Again'])->assertStatus(422);
        $this->assertSame($body, PatientDocument::find($first['id'])->body);

        // It is on the record log.
        $log = array_column($this->getJson("/api/patients/{$patient->id}/audit-log")->json(), 'action');
        $this->assertContains('Issued a certificate or letter', $log);
        $this->assertContains('Cancelled a certificate or letter', $log);
    }

    public function test_only_doctors_issue_and_the_desk_can_reprint(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $patient = Patient::factory()->create();
        $other = Patient::factory()->create();
        $document = PatientDocument::create(['patient_id' => $patient->id, 'kind' => 'fitness', 'title' => 'Fitness certificate', 'body' => 'The patient is fit to resume normal duties from tomorrow.', 'issued_on' => today(), 'doctor_id' => $doctor->id]);
        $payload = ['kind' => 'letter', 'title' => 'Letter', 'body' => 'To whom it may concern: this patient is under our care.', 'issued_on' => today()->toDateString(), 'doctor_id' => $doctor->id];

        foreach (['nurse', 'frontdesk'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->getJson("/api/patients/{$patient->id}/documents")->assertOk()->assertJsonCount(1);
            $this->getJson("/api/documents/{$document->id}")->assertOk();
            $this->postJson("/api/patients/{$patient->id}/documents", $payload)->assertForbidden();
            $this->postJson("/api/documents/{$document->id}/cancel", ['reason' => 'x y z'])->assertForbidden();
        }
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->getJson("/api/patients/{$patient->id}/documents")->assertForbidden();

        // A visit or admission of another patient cannot be attached, and the signer must be a doctor.
        Sanctum::actingAs($doctor);
        $visit = $this->postJson('/api/doctor/visits', ['patient_id' => $other->id])->json('id');
        $this->postJson("/api/patients/{$patient->id}/documents", ['appointment_id' => $visit] + $payload)->assertStatus(422)->assertJsonValidationErrors('appointment_id');
        $this->postJson("/api/patients/{$patient->id}/documents", ['doctor_id' => User::factory()->create(['role' => 'nurse'])->id] + $payload)->assertStatus(422)->assertJsonValidationErrors('doctor_id');
        $this->postJson("/api/patients/{$patient->id}/documents", $payload)->assertCreated()->assertJsonPath('doc_no', 'LT00002');
    }
}
