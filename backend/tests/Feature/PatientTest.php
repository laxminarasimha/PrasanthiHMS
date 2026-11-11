<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
    }

    public function test_patient_can_be_registered_with_an_age(): void
    {
        $response = $this->postJson('/api/patients', [
            'name' => 'Sample Patient', 'gender' => 'male', 'age' => 40, 'mobile' => '9123456780',
        ])->assertCreated();

        $response->assertJsonPath('age', 40)->assertJsonPath('dob_estimated', true);
        $this->assertMatchesRegularExpression('/^\d{6}\d{4,}$/', $response->json('uhid'));
    }

    public function test_patient_can_be_registered_with_a_date_of_birth(): void
    {
        $this->postJson('/api/patients', [
            'name' => 'Sample Patient', 'gender' => 'female', 'date_of_birth' => '1990-05-20', 'mobile' => '9123456780',
        ])->assertCreated()->assertJsonPath('dob_estimated', false)->assertJsonPath('date_of_birth', '1990-05-20');
    }

    public function test_required_fields_and_mobile_format_are_checked(): void
    {
        $this->postJson('/api/patients', ['name' => '', 'mobile' => '123'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'gender', 'mobile', 'age']);
    }

    public function test_patients_can_be_searched_by_name_mobile_or_number(): void
    {
        $target = Patient::factory()->create(['name' => 'Lakshmi Devi', 'mobile' => '9555500001']);
        Patient::factory(3)->create(['name' => 'Someone Else', 'mobile' => '9111100000']);

        $this->getJson('/api/patients?q=laksh')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/patients?q=95555')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/patients?q='.$target->fresh()->uhid)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/patients')->assertOk()->assertJsonCount(4, 'data');
    }

    public function test_patient_details_can_be_updated(): void
    {
        $patient = Patient::factory()->create();

        $this->putJson("/api/patients/{$patient->id}", [
            'name' => 'Renamed', 'gender' => 'male', 'date_of_birth' => '1980-01-01', 'mobile' => '9000011111', 'city' => 'Guntur',
        ])->assertOk()->assertJsonPath('name', 'Renamed')->assertJsonPath('city', 'Guntur');
    }
}
