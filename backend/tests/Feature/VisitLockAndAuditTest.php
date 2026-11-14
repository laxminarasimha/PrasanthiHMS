<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use App\Support\PrescriptionSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VisitLockAndAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_finished_visit_locks_the_next_day_and_takes_amendments(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $patient = Patient::factory()->create();
        Sanctum::actingAs($doctor);
        $visit = $this->postJson('/api/doctor/visits', ['patient_id' => $patient->id])->json('id');
        $url = "/api/appointments/{$visit}/consultation";

        $this->postJson("{$url}/amendments", ['body' => 'Too early'])->assertStatus(422); // nothing to amend yet
        $this->putJson($url, ['diagnosis' => 'Viral fever', 'complete' => true, 'medicines' => [['medicine' => 'Paracetamol 650']]])->assertOk()->assertJsonPath('locked', false);

        // The same day it can still be corrected.
        $this->putJson($url, ['diagnosis' => 'Viral fever with dehydration'])->assertOk()->assertJsonPath('consultation.diagnosis', 'Viral fever with dehydration');

        // From the next day the notes are fixed.
        $this->travel(1)->days();
        $this->getJson($url)->assertOk()->assertJsonPath('locked', true);
        $this->putJson($url, ['diagnosis' => 'Something else'])->assertStatus(422)->assertJsonValidationErrors('visit');
        $this->assertSame('Viral fever with dehydration', \App\Models\Consultation::first()->diagnosis);

        $this->postJson("{$url}/amendments", ['body' => ''])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->postJson("{$url}/amendments", ['body' => 'Lab report later showed dengue NS1 positive.'])
            ->assertCreated()->assertJsonCount(1, 'consultation.amendments')->assertJsonPath('consultation.amendments.0.author.name', $doctor->name)->assertJsonPath('consultation.diagnosis', 'Viral fever with dehydration');
        $this->getJson("/api/patients/{$patient->id}/consultations")->assertJsonPath('0.amendments.0.body', 'Lab report later showed dengue NS1 positive.');

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->postJson("{$url}/amendments", ['body' => 'x'])->assertForbidden();
    }

    public function test_the_log_records_who_opened_and_changed_a_patients_records(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Rao']);
        Sanctum::actingAs($doctor);

        $patient = $this->postJson('/api/patients', ['name' => 'Sita Devi', 'gender' => 'female', 'age' => 40, 'mobile' => '9000000009'])->assertCreated()->json();
        $this->putJson("/api/patients/{$patient['id']}", ['name' => 'Sita Devi', 'gender' => 'female', 'age' => 40, 'mobile' => '9000011111', 'city' => 'Guntur'])->assertOk();
        $this->getJson("/api/patients/{$patient['id']}/overview")->assertOk();
        $this->getJson("/api/patients/{$patient['id']}/overview")->assertOk(); // opening again straight away is noted once
        $visit = $this->postJson('/api/doctor/visits', ['patient_id' => $patient['id']])->json('id');
        $this->getJson("/api/appointments/{$visit}/consultation")->assertOk();
        $this->putJson("/api/appointments/{$visit}/consultation", ['complaints' => 'Fever', 'medicines' => [['medicine' => 'Paracetamol 650']]])->assertOk();
        $this->putJson("/api/appointments/{$visit}/consultation", ['complaints' => 'Fever', 'diagnosis' => 'Viral fever', 'complete' => true])->assertOk();
        $this->postJson("/api/patients/{$patient['id']}/health", ['kind' => 'condition', 'title' => 'Asthma'])->assertCreated();
        $this->putJson("/api/patients/{$patient['id']}/health/allergies", ['allergies' => 'Penicillin'])->assertOk();
        $bill = $this->postJson('/api/bills', ['patient_id' => $patient['id'], 'description' => 'Dressing', 'unit_price' => 200])->assertCreated()->json();
        $this->postJson("/api/bills/{$bill['id']}/payments", ['type' => 'payment', 'amount' => 200, 'mode' => 'cash'])->assertCreated();

        $log = collect($this->getJson("/api/patients/{$patient['id']}/audit-log")->assertOk()->json())->reverse()->values();
        $this->assertSame([
            'Registered the patient', 'Changed patient details', 'Opened the patient page', 'Opened a visit', 'Saved visit notes', 'Finished a visit',
            'Added to the health summary', 'Changed allergies', 'Made a bill', 'Recorded money on a bill',
        ], $log->pluck('action')->all());
        $this->assertTrue($log->every(fn ($r) => $r['who'] === 'Dr Rao'));
        $this->assertSame('Changed: Mobile, City', $log[1]['details']);
        $this->assertSame('Changed: Complaints, Prescription', $log[4]['details']);
        $this->assertSame('Changed: Diagnosis', $log[5]['details']);
        $this->assertStringContainsString('Penicillin', $log[7]['details']);
        $this->assertStringContainsString('received ₹200 by cash', $log[9]['details']);

        // It is also a dated report, and both are for people who may see reports.
        $range = '?from='.today()->toDateString().'&to='.today()->toDateString();
        $this->getJson('/api/reports/list/activity'.$range)->assertOk()->assertJsonCount(10, 'rows')->assertJsonPath('rows.0.who', 'Dr Rao');
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson("/api/patients/{$patient['id']}/audit-log")->assertForbidden();
        $this->assertSame(10, AuditLog::count()); // a refused request leaves no trace of having read anything
    }

    public function test_prescriptions_are_checked_for_allergies_and_repeats(): void
    {
        $messages = fn (?string $allergies, array $medicines) => array_column(PrescriptionSafety::warnings($allergies, $medicines), 'message');

        // A drug family named in the allergy box flags its members; so does one member named for its relatives.
        $this->assertCount(1, $messages('Penicillin', ['Amoxicillin 500mg Capsule', 'Paracetamol 650']));
        $this->assertStringContainsString('allergic to penicillin', $messages('Penicillin', ['Tab Augmentin 625'])[0]);
        $this->assertCount(1, $messages('sulpha drugs', ['Septran DS']));
        $this->assertCount(1, $messages('Allergic to NSAIDs, dust', ['Diclofenac 50mg']));
        $this->assertCount(1, $messages('amoxicillin', ['Ampicillin 500']));
        $this->assertCount(1, $messages('Ciprofloxacin', ['CIPROFLOXACIN 500MG TAB']));

        // Unrelated medicines, empty boxes and "none known" raise nothing.
        $this->assertSame([], $messages('Penicillin', ['Azithromycin 500', 'Moxifloxacin 400', 'Pantoprazole 40']));
        $this->assertSame([], $messages('None known', ['Amoxicillin 500']));
        $this->assertSame([], $messages(null, ['Amoxicillin 500']));
        $this->assertSame([], $messages('Statins', ['Nystatin drops']));

        // The same medicine twice, by name or by first word.
        $this->assertSame(['Paracetamol 650 is on the prescription twice.'], $messages(null, ['Paracetamol 650', 'paracetamol 650']));
        $this->assertStringContainsString('look like the same medicine', $messages(null, ['Tab Paracetamol 500', 'Paracetamol 650mg'])[0]);
        $this->assertSame([], $messages(null, ['Metformin 500', 'Glimepiride 1', '']));

        // Over the wire, with allergies typed on the pad but not yet saved.
        Sanctum::actingAs(User::factory()->create(['role' => 'doctor']));
        $patient = Patient::factory()->create(['allergies' => null]);
        $this->postJson("/api/patients/{$patient->id}/prescription-check", ['medicines' => ['Amoxicillin 500']])->assertOk()->assertJsonCount(0, 'warnings');
        $this->postJson("/api/patients/{$patient->id}/prescription-check", ['medicines' => ['Amoxicillin 500'], 'allergies' => 'Penicillin'])->assertOk()->assertJsonPath('warnings.0.kind', 'allergy');
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->postJson("/api/patients/{$patient->id}/prescription-check", ['medicines' => []])->assertForbidden();
    }
}
