<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FindPatientsTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private function patient(string $name, int $age, string $gender, array $more = []): Patient
    {
        return Patient::factory()->create(['name' => $name, 'gender' => $gender, 'date_of_birth' => today()->subYears($age)->subMonths(3)] + $more);
    }

    private function visit(Patient $p, string $diagnosis, array $medicines = [], ?string $on = null): void
    {
        $c = new Consultation(['diagnosis' => $diagnosis]);
        $c->forceFill(['patient_id' => $p->id, 'doctor_id' => $this->doctor->id, 'appointment_id' => \App\Models\Appointment::create(['patient_id' => $p->id, 'doctor_id' => $this->doctor->id, 'scheduled_at' => $on ?? now()->format('Y-m-d H:i'), 'status' => 'reviewed'])->id]);
        if ($on) {
            $c->created_at = $on;
        }
        $c->save();
        foreach ($medicines as $m) {
            $c->medicines()->create(['medicine' => $m]);
        }
    }

    private function labResult(Patient $p, string $test, string $value, ?string $when = null): void
    {
        $when ??= now()->format('Y-m-d H:i');
        LabOrder::create(['patient_id' => $p->id, 'status' => 'completed', 'ordered_at' => $when, 'referred_by' => 'Dr Outside'])
            ->items()->create(['test_name' => $test, 'price' => 100, 'reported_at' => $when])
            ->results()->create(['parameter' => $test, 'unit' => 'mg/dL', 'value' => $value]);
    }

    private function names(array $filters): array
    {
        return array_column($this->getJson('/api/reports/find-patients?'.http_build_query($filters))->assertOk()->json('rows'), 'name');
    }

    public function test_patients_are_found_by_age_gender_diagnosis_medicine_and_lab_value(): void
    {
        $this->doctor = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs($this->doctor);

        $asha = $this->patient('Asha', 62, 'female');
        $ravi = $this->patient('Ravi', 45, 'male');
        $kiran = $this->patient('Kiran', 8, 'male');
        $this->visit($asha, "Type 2 diabetes\nHypertension", ['Metformin 500', 'Telmisartan 40']);
        $this->visit($ravi, 'Type 2 Diabetes', ['Glimepiride 1'], now()->subMonths(8)->format('Y-m-d H:i'));
        $this->visit($kiran, 'Viral fever', ['Paracetamol syrup']);
        $this->labResult($asha, 'HbA1c', '9.2');
        $this->labResult($asha, 'HbA1c', '7.1', now()->addMinute()->format('Y-m-d H:i'));
        $this->labResult($ravi, 'HbA1c', '6.4');
        $this->labResult($kiran, 'Widal', 'Positive');

        $this->assertSame(['Asha', 'Kiran', 'Ravi'], $this->names([]));
        $this->assertSame(['Asha'], $this->names(['gender' => 'female']));
        $this->assertSame(['Asha', 'Ravi'], $this->names(['age_from' => 40]));
        $this->assertSame(['Ravi'], $this->names(['age_from' => 40, 'age_to' => 45]));
        $this->assertSame(['Kiran'], $this->names(['age_to' => 8]));
        $this->assertSame(['Asha', 'Ravi'], $this->names(['diagnosis' => 'diabetes']));
        $this->assertSame(['Asha'], $this->names(['diagnosis' => 'diabetes', 'visit_from' => now()->subMonth()->toDateString()]));
        $this->assertSame(['Asha'], $this->names(['medicine' => 'metformin']));
        $this->assertSame([], $this->names(['medicine' => 'metformin', 'gender' => 'male']));

        // Lab value in a range: Asha's latest HbA1c (7.1) is what is shown, and words are never treated as numbers.
        $this->assertSame(['Asha', 'Ravi'], $this->names(['test' => 'hba1c']));
        $this->assertSame(['Asha'], $this->names(['test' => 'hba1c', 'value_from' => 7]));
        $this->assertSame(['Ravi'], $this->names(['test' => 'hba1c', 'value_to' => 6.9]));
        $this->assertSame([], $this->names(['test' => 'widal', 'value_from' => 0]));
        $found = $this->getJson('/api/reports/find-patients?test=hba1c&value_from=7')->json();
        $this->assertStringContainsString('HbA1c 7.1 mg/dL', $found['rows'][0]['result']);
        $this->assertSame(1, $found['total']);
        $this->assertSame(3, $found['all_patients']);

        $this->getJson('/api/reports/find-patients?age_from=50&age_to=40')->assertStatus(422);

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson('/api/reports/find-patients')->assertForbidden();
    }

    public function test_lab_and_referral_reports(): void
    {
        $this->doctor = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs($this->doctor);
        $a = $this->patient('Asha', 62, 'female', ['referred_by' => 'Dr Outside']);
        $b = $this->patient('Ravi', 45, 'male', ['referred_by' => 'dr outside ']);
        $c = $this->patient('Kiran', 8, 'male');
        $this->labResult($a, 'HbA1c', '9.2');
        $this->labResult($b, 'HbA1c', '6.4');
        LabOrder::create(['patient_id' => $c->id, 'status' => 'pending', 'ordered_at' => now()])->items()->create(['test_name' => 'CBP', 'price' => 300]);

        $range = '?from='.today()->toDateString().'&to='.today()->toDateString();
        $tests = collect($this->getJson('/api/reports/list/lab-tests'.$range)->assertOk()->json('rows'))->keyBy('test');
        $this->assertEquals([2, 2, 200], [$tests['HbA1c']['ordered'], $tests['HbA1c']['reported'], $tests['HbA1c']['amount']]);
        $this->assertEquals([1, 0], [$tests['CBP']['ordered'], $tests['CBP']['reported']]);

        $lab = collect($this->getJson('/api/reports/list/lab-referrals'.$range)->assertOk()->json('rows'))->keyBy('referred_by');
        $this->assertEquals([2, 2, 200], [$lab['Dr Outside']['orders'], $lab['Dr Outside']['patients'], $lab['Dr Outside']['amount']]);
        $this->assertSame(1, $lab['Not referred (self)']['orders']);

        $new = collect($this->getJson('/api/reports/list/patient-referrals'.$range)->assertOk()->json('rows'))->keyBy('referred_by');
        $this->assertSame(2, $new['Dr Outside']['patients']); // spelling differences in case and spaces are counted together
        $this->assertSame(1, $new['Not referred (self)']['patients']);
    }
}
