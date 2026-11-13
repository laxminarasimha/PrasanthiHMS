<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CaseHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_doctor_starts_a_visit_without_a_front_desk_booking(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $patient = Patient::factory()->create();
        Sanctum::actingAs($doctor);

        $first = $this->postJson('/api/doctor/visits', ['patient_id' => $patient->id])->assertCreated()->assertJsonPath('created', true)->json('id');
        $visit = Appointment::find($first);
        $this->assertSame([$doctor->id, 'arrived', 1], [$visit->doctor_id, $visit->status, $visit->token_no]);
        $this->assertNotNull($visit->arrived_at);

        // Asking again the same day opens the same visit instead of making a second one.
        $this->postJson('/api/doctor/visits', ['patient_id' => $patient->id])->assertOk()->assertJsonPath('id', $first)->assertJsonPath('created', false);
        $this->assertSame(1, Appointment::count());

        // It shows in the doctor's list and the pad can be saved.
        $this->getJson('/api/doctor/queue')->assertJsonCount(1)->assertJsonPath('0.id', $first);
        $this->putJson("/api/appointments/{$first}/consultation", ['diagnosis' => 'Viral fever', 'complete' => true])->assertOk();

        // Once that visit is finished, a new one can be started.
        $this->postJson('/api/doctor/visits', ['patient_id' => $patient->id])->assertCreated()->assertJsonPath('created', true);
        $this->assertSame(2, Appointment::latest('id')->first()->token_no);

        // A patient booked for later today is marked as arrived rather than booked twice.
        $other = Patient::factory()->create();
        $booked = Appointment::create(['patient_id' => $other->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now()->format('Y-m-d H:i'), 'status' => 'booked', 'token_no' => 3]);
        $this->postJson('/api/doctor/visits', ['patient_id' => $other->id])->assertOk()->assertJsonPath('id', $booked->id);
        $this->assertSame('arrived', $booked->fresh()->status);
    }

    public function test_only_people_who_consult_can_start_a_visit_and_others_must_name_the_doctor(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $patient = Patient::factory()->create();

        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->postJson('/api/doctor/visits', ['patient_id' => $patient->id])->assertForbidden();
        $this->getJson("/api/patients/{$patient->id}/case-history")->assertForbidden();
        $this->getJson("/api/patients/{$patient->id}/trends")->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/doctor/visits', ['patient_id' => $patient->id])->assertStatus(422)->assertJsonValidationErrors('doctor_id');
        $id = $this->postJson('/api/doctor/visits', ['patient_id' => $patient->id, 'doctor_id' => $doctor->id])->assertCreated()->json('id');
        $this->assertSame($doctor->id, Appointment::find($id)->doctor_id);
    }

    public function test_case_history_and_trends_bring_visits_and_lab_results_together(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $patient = Patient::factory()->create();
        Sanctum::actingAs($doctor);

        $visit = $this->postJson('/api/doctor/visits', ['patient_id' => $patient->id])->json('id');
        $this->putJson("/api/appointments/{$visit}/consultation", [
            'diagnosis' => 'Type 2 diabetes', 'weight_kg' => 72.5, 'bp_systolic' => 130, 'bp_diastolic' => 84, 'complete' => true,
            'medicines' => [['medicine' => 'Metformin 500', 'frequency' => '1-0-1']],
        ])->assertOk();

        foreach ([['2026-01-10 09:00', '182', 'high'], ['2026-03-12 09:00', '141', 'high'], ['2026-06-02 09:00', '104', null]] as [$when, $value, $flag]) {
            $order = LabOrder::create(['patient_id' => $patient->id, 'status' => 'completed', 'ordered_at' => $when]);
            $item = $order->items()->create(['test_name' => 'Fasting Blood Sugar', 'price' => 80, 'reported_at' => $when]);
            $item->results()->create(['parameter' => 'Glucose (fasting)', 'unit' => 'mg/dL', 'reference_range' => '70 - 110', 'value' => $value, 'flag' => $flag]);
        }
        // Not reported yet, and a result that is words rather than a number.
        $pending = LabOrder::create(['patient_id' => $patient->id, 'status' => 'pending', 'ordered_at' => '2026-06-03 09:00']);
        $pending->items()->create(['test_name' => 'Fasting Blood Sugar', 'price' => 80])->results()->create(['parameter' => 'Glucose (fasting)', 'value' => '999']);
        $words = LabOrder::create(['patient_id' => $patient->id, 'status' => 'completed', 'ordered_at' => '2026-06-04 09:00']);
        $words->items()->create(['test_name' => 'Widal', 'price' => 150, 'reported_at' => '2026-06-04 10:00'])->results()->create(['parameter' => 'S. typhi O', 'value' => '1:160']);
        // Another patient's results must not leak in.
        $else = LabOrder::create(['patient_id' => Patient::factory()->create()->id, 'status' => 'completed', 'ordered_at' => '2026-06-04 09:00']);
        $else->items()->create(['test_name' => 'Fasting Blood Sugar', 'price' => 80, 'reported_at' => '2026-06-04 10:00'])->results()->create(['parameter' => 'Glucose (fasting)', 'value' => '555']);

        $trends = $this->getJson("/api/patients/{$patient->id}/trends")->assertOk()->assertJsonCount(2, 'tests')->json();
        $sugar = collect($trends['tests'])->firstWhere('test', 'Fasting Blood Sugar');
        $this->assertSame(['182', '141', '104'], array_column($sugar['points'], 'value'));
        $this->assertSame([182.0, 141.0, 104.0], array_map('floatval', array_column($sugar['points'], 'number')));
        $this->assertSame(['2026-01-10', '2026-03-12', '2026-06-02'], array_column($sugar['points'], 'date'));
        $this->assertSame('mg/dL', $sugar['unit']);
        $this->assertNull(collect($trends['tests'])->firstWhere('test', 'Widal')['points'][0]['number']);
        $this->assertCount(1, $trends['vitals']);
        $this->assertSame(130, $trends['vitals'][0]['bp_systolic']);

        $history = $this->getJson("/api/patients/{$patient->id}/case-history")->assertOk()->assertJsonCount(6, 'events')->json('events');
        $this->assertSame('visit', $history[0]['kind']); // today's visit is the newest
        $this->assertSame('Type 2 diabetes', $history[0]['visit']['diagnosis']);
        $this->assertSame('Metformin 500', $history[0]['visit']['medicines'][0]['medicine']);
        $lab = collect($history)->firstWhere('lab.order_no', $words->fresh()->order_no);
        $this->assertSame('1:160', $lab['lab']['tests'][0]['results'][0]['value']);
        // A test that is not reported shows no values.
        $this->assertSame([], collect($history)->firstWhere('lab.order_no', $pending->fresh()->order_no)['lab']['tests'][0]['results']);
    }
}
