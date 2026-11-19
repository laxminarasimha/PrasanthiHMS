<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Appointment;
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

    public function test_patients_list_says_when_each_patient_was_last_seen(): void
    {
        $seen = Patient::factory()->create(['name' => 'Seen Before']);
        Patient::factory()->create(['name' => 'Never Seen']);
        $doctor = User::factory()->create(['role' => 'doctor']);
        $visit = fn (string $when, string $status) => Appointment::create(['patient_id' => $seen->id, 'doctor_id' => $doctor->id, 'scheduled_at' => $when, 'status' => $status]);
        $visit(now()->subDays(9)->format('Y-m-d 10:00'), 'reviewed');
        $visit(now()->subDays(2)->format('Y-m-d 11:00'), 'reviewed');
        $visit(now()->subDay()->format('Y-m-d 11:00'), 'cancelled'); // a cancelled visit is not a visit
        $visit(now()->subDay()->format('Y-m-d 12:00'), 'booked'); // nor one the patient never came for
        $visit(now()->addDays(3)->format('Y-m-d 11:00'), 'booked'); // nor one still to come

        $list = collect($this->getJson('/api/patients')->assertOk()->json('data'))->keyBy('name');
        $this->assertStringStartsWith(now()->subDays(2)->format('Y-m-d 11:00'), $list['Seen Before']['last_visit_at']);
        $this->assertNull($list['Never Seen']['last_visit_at']);
    }

    public function test_patient_details_can_be_updated(): void
    {
        $patient = Patient::factory()->create();

        $this->putJson("/api/patients/{$patient->id}", [
            'name' => 'Renamed', 'gender' => 'male', 'date_of_birth' => '1980-01-01', 'mobile' => '9000011111', 'city' => 'Guntur',
        ])->assertOk()->assertJsonPath('name', 'Renamed')->assertJsonPath('city', 'Guntur');
    }

    public function test_a_patient_is_found_however_the_name_or_number_is_typed(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        Patient::factory()->create(['title' => 'Mrs', 'name' => 'K. Lakshmi  Devi', 'mobile' => '9876543210', 'mobile_alt' => '9123456780', 'existing_id' => 'HP-4411']);
        Patient::factory()->create(['name' => 'Lakshmi Narayana', 'mobile' => '9000000001']);
        Patient::factory()->create(['name' => 'Ravi Kumar', 'mobile' => '9000000002']);
        $find = fn (string $q) => array_column($this->getJson('/api/patients?q='.urlencode($q))->assertOk()->json('data'), 'name');

        $this->assertSame(['K. Lakshmi  Devi'], $find('Lakshmi Devi'));       // extra spaces in the stored name
        $this->assertSame(['K. Lakshmi  Devi'], $find('Mrs Lakshmi Devi'));   // with the title
        $this->assertSame(['K. Lakshmi  Devi'], $find('devi lakshmi'));       // the other way round
        $this->assertSame(['K. Lakshmi  Devi'], $find('K Lakshmi Devi'));     // initial without the dot
        $this->assertCount(2, $find('lakshmi'));
        $this->assertSame(['Ravi Kumar'], $find('Mr. Ravi Kumar'));
        $this->assertSame(['K. Lakshmi  Devi'], $find('98765 43210'));        // number with a space
        $this->assertSame(['K. Lakshmi  Devi'], $find('+91 9876543210'));
        $this->assertSame(['K. Lakshmi  Devi'], $find('9123456780'));         // the other number
        $this->assertSame(['K. Lakshmi  Devi'], $find('HP-4411'));            // the old system's ID
        $this->assertSame([], $find('Lakshmi Kumar'));                        // every word must fit one person
        $this->assertSame([], $find('100%'));
    }

    public function test_the_patients_recently_in_the_hospital_are_offered_those_here_now_first(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Sample']);
        $visit = fn (string $name, string $status, $when) => tap(Patient::factory()->create(['name' => $name]), fn (Patient $p) => Appointment::create([
            'patient_id' => $p->id, 'doctor_id' => $doctor->id, 'scheduled_at' => $when, 'status' => $status, 'token_no' => 1,
        ]));

        $waiting = $visit('Waiting Now', 'arrived', now()->startOfDay()->addHours(9));
        $seenToday = $visit('Seen Today', 'reviewed', now()->startOfDay()->addHours(10));
        $yesterday = $visit('Seen Yesterday', 'reviewed', now()->subDay()->startOfDay()->addHours(11));
        $visit('Long Ago', 'reviewed', now()->subDays(6));
        $visit('Cancelled Today', 'cancelled', now()->startOfDay()->addHours(8));
        $admitted = Patient::factory()->create(['name' => 'In A Bed']);
        Admission::create(['patient_id' => $admitted->id, 'doctor_id' => $doctor->id, 'admitted_at' => now()->subDays(4), 'status' => 'admitted']);
        Patient::factory()->create(['name' => 'Never Came']);

        $list = $this->getJson('/api/patients/recent')->assertOk()->json();
        $names = array_column($list, 'name');
        // Here now (waiting, or in a bed) before those who have left; then the latest first.
        $this->assertSame(['Waiting Now', 'In A Bed', 'Seen Today', 'Seen Yesterday'], $names);
        $this->assertSame('Waiting · Dr Sample', $list[0]['recent']['why']);
        $this->assertTrue($list[1]['recent']['here']);
        $this->assertStringStartsWith('Admitted · ', $list[1]['recent']['why']);
        $this->assertSame($seenToday->uhid, $list[2]['uhid']);
        $this->assertNotNull($list[2]['age_label']);
        $this->assertSame([$waiting->id, $admitted->id, $seenToday->id, $yesterday->id], array_column($list, 'id'));
    }
}
