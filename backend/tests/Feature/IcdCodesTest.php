<?php

namespace Tests\Feature;

use App\Models\IcdCode;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IcdCodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shipped_list_loads_and_can_be_searched_by_code_or_words(): void
    {
        $this->artisan('hms:import-icd')->expectsOutputToContain('diagnosis codes')->assertExitCode(0);
        $this->assertGreaterThan(10000, IcdCode::count());
        $this->assertSame('Essential (primary) hypertension', IcdCode::where('code', 'I10')->value('name'));
        $count = IcdCode::count();
        $this->artisan('hms:import-icd')->assertExitCode(0); // loading twice adds nothing
        $this->assertSame($count, IcdCode::count());

        Sanctum::actingAs(User::factory()->create(['role' => 'doctor']));
        $this->getJson('/api/icd-codes?q=e')->assertOk()->assertJsonCount(0);
        $byCode = $this->getJson('/api/icd-codes?q=e11')->assertOk()->json();
        $this->assertSame('E11', $byCode[0]['code']); // the broad entry first
        $this->assertSame('E11.9', collect($byCode)->firstWhere('code', 'E11.9')['code']);
        $byWords = array_column($this->getJson('/api/icd-codes?q=type 2 diabetes')->json(), 'code');
        $this->assertSame('E11', $byWords[0]);
        $this->assertContains('J45', array_column($this->getJson('/api/icd-codes?q=asthma')->json(), 'code'));

        // A bad file loads nothing.
        $bad = tempnam(sys_get_temp_dir(), 'icd');
        file_put_contents($bad, "not a list\n");
        $this->artisan('hms:import-icd', ['file' => $bad])->assertExitCode(1);
        $this->assertSame($count, IcdCode::count());
        unlink($bad);
    }

    public function test_a_visit_carries_coded_diagnoses_and_they_can_be_reported_and_searched(): void
    {
        IcdCode::insert([['code' => 'E11', 'name' => 'Type 2 diabetes mellitus'], ['code' => 'E11.9', 'name' => 'Type 2 diabetes mellitus without complications'], ['code' => 'I10', 'name' => 'Essential (primary) hypertension'], ['code' => 'J45', 'name' => 'Asthma']]);
        $doctor = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs($doctor);
        $asha = Patient::factory()->create(['name' => 'Asha']);
        $ravi = Patient::factory()->create(['name' => 'Ravi']);

        $visit = $this->postJson('/api/doctor/visits', ['patient_id' => $asha->id])->json('id');
        $url = "/api/appointments/{$visit}/consultation";
        $this->putJson($url, ['diagnosis_codes' => [['code' => 'Z99.999']]])->assertStatus(422)->assertJsonValidationErrors('diagnosis_codes.0.code');
        $this->putJson($url, ['diagnosis_codes' => [['code' => 'I10'], ['code' => 'I10']]])->assertStatus(422);
        // The name saved is the list's own, whatever was sent.
        $this->putJson($url, ['diagnosis' => 'DM, HTN', 'diagnosis_codes' => [['code' => 'E11.9', 'name' => 'made up'], ['code' => 'I10']], 'complete' => true])
            ->assertOk()->assertJsonPath('consultation.diagnosis_codes.0.name', 'Type 2 diabetes mellitus without complications')->assertJsonPath('consultation.diagnosis_codes.1.code', 'I10');

        $second = $this->postJson('/api/doctor/visits', ['patient_id' => $ravi->id])->json('id');
        $this->putJson("/api/appointments/{$second}/consultation", ['diagnosis_codes' => [['code' => 'J45']], 'complete' => true])->assertOk();
        // Saving without the field leaves the codes alone; sending an empty list clears them.
        $third = $this->postJson('/api/doctor/visits', ['patient_id' => $ravi->id])->json('id');
        $this->putJson("/api/appointments/{$third}/consultation", ['diagnosis_codes' => [['code' => 'I10']]])->assertOk();
        $this->putJson("/api/appointments/{$third}/consultation", ['advice' => 'Rest'])->assertOk()->assertJsonCount(1, 'consultation.diagnosis_codes');
        $this->putJson("/api/appointments/{$third}/consultation", ['diagnosis_codes' => [], 'complete' => true])->assertOk()->assertJsonPath('consultation.diagnosis_codes', null);

        $range = '?from='.today()->toDateString().'&to='.today()->toDateString();
        $report = collect($this->getJson('/api/reports/list/diagnosis-codes'.$range)->assertOk()->json('rows'))->keyBy('code');
        $this->assertSame(['E11.9', 'I10', 'J45'], $report->keys()->sort()->values()->all());
        $this->assertSame([1, 1], [$report['I10']['visits'], $report['I10']['patients']]);

        $names = fn (string $icd) => array_column($this->getJson('/api/reports/find-patients?icd='.$icd)->assertOk()->json('rows'), 'name');
        $this->assertSame(['Asha'], $names('E11')); // the broad code finds the finer one under it
        $this->assertSame(['Asha'], $names('e11.9'));
        $this->assertSame(['Ravi'], $names('J45'));
        $this->assertSame([], $names('K21'));
    }
}
