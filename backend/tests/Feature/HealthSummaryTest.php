<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\PatientHealthItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HealthSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_doctor_keeps_a_lasting_health_summary_for_a_patient(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $patient = Patient::factory()->create();
        Sanctum::actingAs($doctor);
        $url = "/api/patients/{$patient->id}/health";

        $empty = $this->getJson($url)->assertOk()->assertJsonCount(6, 'sections')->json();
        $this->assertSame(['condition', 'medication', 'surgery', 'family', 'habit', 'immunisation'], array_column($empty['sections'], 'kind'));

        $this->postJson($url, ['kind' => 'condition', 'title' => ''])->assertStatus(422)->assertJsonValidationErrors('title');
        $this->postJson($url, ['kind' => 'gossip', 'title' => 'x'])->assertStatus(422)->assertJsonValidationErrors('kind');
        $this->postJson($url, ['kind' => 'condition', 'title' => ' Type 2 diabetes ', 'since' => '2018'])->assertCreated()->assertJsonPath('sections.0.items.0.title', 'Type 2 diabetes')->assertJsonPath('sections.0.items.0.creator.name', $doctor->name);
        $this->postJson($url, ['kind' => 'condition', 'title' => 'type 2 DIABETES'])->assertStatus(422)->assertJsonValidationErrors('title'); // already there
        $this->postJson($url, ['kind' => 'condition', 'title' => 'Hypertension', 'since' => '5 years'])->assertCreated();
        $this->postJson($url, ['kind' => 'medication', 'title' => 'Metformin 500mg', 'detail' => '1-0-1 after food'])->assertCreated()->assertJsonPath('sections.1.items.0.detail', '1-0-1 after food');
        $this->postJson($url, ['kind' => 'surgery', 'title' => 'Appendicectomy', 'since' => '2009'])->assertCreated();

        // Marking a condition as resolved keeps it on the record, below the active ones, with the day.
        $diabetes = PatientHealthItem::where('title', 'Type 2 diabetes')->first();
        $after = $this->putJson("/api/health-items/{$diabetes->id}", ['is_active' => false])->assertOk()->json('sections.0.items');
        $this->assertSame(['Hypertension', 'Type 2 diabetes'], array_column($after, 'title'));
        $this->assertSame(today()->toDateString(), $after[1]['ended_on']);
        $this->putJson("/api/health-items/{$diabetes->id}", ['is_active' => true, 'detail' => 'On diet control'])->assertOk()->assertJsonPath('sections.0.items.0.ended_on', null)->assertJsonPath('sections.0.items.0.detail', 'On diet control');
        $this->putJson("/api/health-items/{$diabetes->id}", ['kind' => 'family'])->assertStatus(422); // a fact does not change section

        $this->putJson("{$url}/allergies", ['allergies' => ' Penicillin '])->assertOk()->assertJsonPath('allergies', 'Penicillin');
        $this->assertSame('Penicillin', $patient->fresh()->allergies);
        $this->putJson("{$url}/allergies", ['allergies' => ''])->assertOk()->assertJsonPath('allergies', null);

        $surgery = PatientHealthItem::where('kind', 'surgery')->first();
        $this->deleteJson("/api/health-items/{$surgery->id}")->assertOk()->assertJsonCount(0, 'sections.2.items');
    }

    public function test_who_may_read_and_change_the_summary(): void
    {
        $patient = Patient::factory()->create();
        $item = PatientHealthItem::create(['patient_id' => $patient->id, 'kind' => 'condition', 'title' => 'Asthma']);
        $url = "/api/patients/{$patient->id}/health";

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson($url)->assertForbidden();

        // The lab can read it, to interpret results, but not change it.
        Sanctum::actingAs(User::factory()->create(['role' => 'lab_tech']));
        $this->getJson($url)->assertOk()->assertJsonPath('sections.0.items.0.title', 'Asthma');
        $this->postJson($url, ['kind' => 'condition', 'title' => 'x'])->assertForbidden();
        $this->putJson("/api/health-items/{$item->id}", ['title' => 'x'])->assertForbidden();
        $this->deleteJson("/api/health-items/{$item->id}")->assertForbidden();
        $this->putJson("{$url}/allergies", ['allergies' => 'x'])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'nurse']));
        $this->postJson($url, ['kind' => 'immunisation', 'title' => 'Tetanus toxoid', 'since' => 'Nov 2026'])->assertCreated();
    }

    public function test_merging_patients_carries_the_summary_and_the_app_account_across(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $old = Patient::factory()->create();
        $fromApp = Patient::factory()->create();
        PatientHealthItem::create(['patient_id' => $fromApp->id, 'kind' => 'condition', 'title' => 'Hypothyroidism']);
        $account = PatientAccount::create(['patient_id' => $fromApp->id, 'name' => 'App User', 'mobile' => '9876500000', 'password' => 'Health2026']);
        $list = $account->subscriptions()->create(['patient_id' => $fromApp->id, 'title' => 'Thyroid', 'items' => [['medicine_id' => null, 'name' => 'Thyronorm 50mcg', 'quantity' => 30]], 'day_of_month' => 5, 'status' => 'active', 'next_due_on' => today()->addDays(3)]);

        $this->postJson('/api/patient-merges', ['from' => $fromApp->uhid, 'to' => $old->uhid])->assertSuccessful();

        $this->assertSame($old->id, PatientHealthItem::first()->patient_id);
        $this->assertSame($old->id, $account->fresh()->patient_id);
        $this->assertSame($old->id, $list->fresh()->patient_id);
    }
}
