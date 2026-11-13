<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RegistrationDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_baby_is_registered_with_a_title_and_an_age_in_days_weeks_or_months(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $register = fn (array $extra) => $this->postJson('/api/patients', $extra + ['name' => 'Sample Child', 'gender' => 'female', 'mobile' => '9000000001']);

        $register(['title' => 'Baby', 'age' => 12, 'age_unit' => 'days'])->assertCreated()->assertJsonPath('title', 'Baby')->assertJsonPath('age_label', '12d')->assertJsonPath('age', 0);
        $register(['age' => 3, 'age_unit' => 'weeks'])->assertCreated()->assertJsonPath('age_label', '21d');
        $register(['age' => 8, 'age_unit' => 'months'])->assertCreated()->assertJsonPath('age_label', '8m')->assertJsonPath('dob_estimated', true);
        $register(['age' => 18, 'age_unit' => 'months'])->assertCreated()->assertJsonPath('age_label', '18m')->assertJsonPath('age', 1);
        // Years remain the usual way, with or without the unit.
        $register(['title' => 'Mrs', 'age' => 34])->assertCreated()->assertJsonPath('age_label', '34y')->assertJsonPath('age', 34);
        $register(['age' => 2, 'age_unit' => 'years'])->assertCreated()->assertJsonPath('age_label', '2y');

        $register(['title' => 'Lord', 'age' => 30])->assertStatus(422)->assertJsonValidationErrors('title');
        $register(['age' => 3, 'age_unit' => 'fortnights'])->assertStatus(422)->assertJsonValidationErrors('age_unit');
    }

    public function test_the_title_travels_with_the_patient_to_the_desk_and_the_bill(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $patient = Patient::factory()->create(['title' => 'Smt', 'name' => 'Sample Patient']);
        $doctor = User::factory()->create(['role' => 'doctor']);

        $visit = $this->postJson('/api/appointments', ['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now()->format('Y-m-d H:i')])
            ->assertCreated()->assertJsonPath('patient.title', 'Smt')->assertJsonPath('patient.age_label', $patient->age_label)->json('id');
        $this->getJson("/api/appointments/{$visit}/consultation")->assertOk()->assertJsonPath('patient.title', 'Smt');
        $this->getJson('/api/doctor/queue')->assertOk();
    }

    public function test_billing_preferences_have_defaults_and_only_setup_staff_change_them(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson('/api/billing-preferences')->assertOk()->assertJsonPath('default_payment_mode', 'cash')->assertJsonPath('pay_timing', 'before');
        $this->putJson('/api/billing-preferences', ['default_payment_mode' => 'upi', 'pay_timing' => 'after'])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson('/api/billing-preferences', ['default_payment_mode' => 'shells', 'pay_timing' => 'after'])->assertStatus(422);
        $this->putJson('/api/billing-preferences', ['default_payment_mode' => 'upi', 'pay_timing' => 'after'])->assertOk()->assertJsonPath('pay_timing', 'after');
        $this->getJson('/api/billing-preferences')->assertJsonPath('default_payment_mode', 'upi');
    }
}
