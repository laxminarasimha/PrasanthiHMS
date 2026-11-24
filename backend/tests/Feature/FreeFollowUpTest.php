<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Bill;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A visit to the same doctor within the hospital's follow-up days of a consultation is a follow-up and is not
 * charged. The desk only books the appointment.
 */
class FreeFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private Patient $patient;

    private Service $consultation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-01 09:00:00');
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->doctor = User::factory()->create(['role' => 'doctor']);
        $this->patient = Patient::factory()->create();
        $this->consultation = Service::factory()->create(['price' => 600, 'doctor_id' => $this->doctor->id]);
    }

    private function book(string $day, array $more = []): TestResponse
    {
        return $this->postJson('/api/appointments', $more + ['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'service_id' => $this->consultation->id, 'scheduled_at' => "{$day} 10:30", 'status' => 'arrived']);
    }

    private function asked(string $day, ?User $doctor = null): array
    {
        return $this->getJson('/api/appointments/follow-up?'.http_build_query(['patient_id' => $this->patient->id, 'doctor_id' => ($doctor ?? $this->doctor)->id, 'date' => $day]))->assertOk()->json();
    }

    public function test_a_visit_within_fifteen_days_of_a_consultation_is_a_follow_up_and_is_not_charged(): void
    {
        // Nothing before: a consultation, charged.
        $this->assertFalse($this->asked('2026-11-01')['follow_up']);
        $first = $this->book('2026-11-01')->assertCreated();
        $this->assertSame([false, 600], [$first->json('is_follow_up'), (int) $first->json('bills.0.total')]);

        // Ten days later the form is told it is a follow-up, free until the fifteenth day.
        $this->assertSame(['follow_up' => true, 'days' => 15, 'since' => '2026-11-01', 'until' => '2026-11-16'], $this->asked('2026-11-11'));
        $again = $this->book('2026-11-11', ['follow_up' => true])->assertCreated();
        $this->assertSame([true, []], [$again->json('is_follow_up'), $again->json('bills')]);
        $this->assertSame(1, Bill::count());

        // The fifteenth day is still free; the sixteenth is a consultation again, whatever the form says.
        $this->assertTrue($this->asked('2026-11-16')['follow_up']);
        $this->assertFalse($this->asked('2026-11-17')['follow_up']);
        $this->book('2026-11-17', ['follow_up' => true])->assertStatus(422)->assertJsonValidationErrors('follow_up');

        // The days are counted from the consultation, not from the follow-up: free visits do not chain on.
        $this->assertFalse($this->asked('2026-11-20')['follow_up']);
        // Another doctor is another consultation.
        $this->assertFalse($this->asked('2026-11-11', User::factory()->create(['role' => 'doctor']))['follow_up']);
    }

    public function test_the_desk_may_charge_anyway_and_a_new_consultation_starts_the_days_again(): void
    {
        $this->book('2026-11-01')->assertCreated();
        // The form sent a charge and no follow-up: the desk chose to charge, a new complaint say.
        $charged = $this->book('2026-11-11')->assertCreated();
        $this->assertSame([false, 600], [$charged->json('is_follow_up'), (int) $charged->json('bills.0.total')]);
        $this->assertSame('2026-11-11', $this->asked('2026-11-20')['since']);
    }

    public function test_only_a_visit_the_patient_came_for_earns_a_follow_up(): void
    {
        // Booked and never came, then cancelled: neither is a consultation to follow up.
        $noShow = $this->book('2026-11-01', ['status' => 'booked', 'skip_billing' => true])->assertCreated()->json('id');
        $this->assertFalse($this->asked('2026-11-05')['follow_up']);
        $this->putJson("/api/appointments/{$noShow}", ['status' => 'arrived'])->assertOk();
        $this->assertTrue($this->asked('2026-11-05')['follow_up']);
        $this->putJson("/api/appointments/{$noShow}", ['status' => 'cancelled'])->assertOk();
        $this->assertFalse($this->asked('2026-11-05')['follow_up']);
    }

    public function test_a_visit_booked_without_the_desk_deciding_is_marked_by_itself(): void
    {
        $this->book('2026-11-01')->assertCreated();
        // As the patient's own phone books it: no bill, nothing said about which it is.
        $own = Appointment::create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'scheduled_at' => '2026-11-08 11:00', 'status' => 'booked', 'booked_via' => 'app']);
        $this->assertTrue($own->fresh()->is_follow_up);
        // Moved by the patient to after the days: it is a consultation, for the desk to bill.
        $own->update(['scheduled_at' => '2026-11-25 11:00']);
        $this->assertFalse($own->fresh()->is_follow_up);
        $own->update(['scheduled_at' => '2026-11-10 11:00']);
        $this->assertTrue($own->fresh()->is_follow_up);

        // Skipping the bill at the desk within the days is a follow-up too.
        $this->assertTrue($this->book('2026-11-09', ['skip_billing' => true])->assertCreated()->json('is_follow_up'));
    }

    public function test_a_follow_up_cannot_be_moved_out_of_its_days_at_the_desk(): void
    {
        $this->book('2026-11-01')->assertCreated();
        $visit = $this->book('2026-11-11', ['follow_up' => true])->assertCreated()->json('id');
        $this->putJson("/api/appointments/{$visit}", ['scheduled_at' => '2026-11-14 10:30'])->assertOk()->assertJsonPath('is_follow_up', true);
        $this->putJson("/api/appointments/{$visit}", ['scheduled_at' => '2026-11-30 10:30'])->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
        $other = User::factory()->create(['role' => 'doctor']);
        $this->putJson("/api/appointments/{$visit}", ['doctor_id' => $other->id])->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
        // Cancelling it is always possible.
        $this->putJson("/api/appointments/{$visit}", ['status' => 'cancelled'])->assertOk();
    }

    public function test_the_hospital_sets_the_days_or_switches_the_rule_off(): void
    {
        $this->book('2026-11-01')->assertCreated();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $now = $this->getJson('/api/billing-preferences')->assertOk()->json();
        $this->assertSame(15, $now['follow_up_days']);

        $this->putJson('/api/billing-preferences', ['follow_up_days' => 7] + $now)->assertOk();
        $this->assertSame([true, '2026-11-08'], [$this->asked('2026-11-08')['follow_up'], $this->asked('2026-11-08')['until']]);
        $this->assertFalse($this->asked('2026-11-09')['follow_up']);

        $this->putJson('/api/billing-preferences', ['follow_up_days' => 0] + $now)->assertOk();
        $this->assertFalse($this->asked('2026-11-02')['follow_up']);
        $this->assertNotNull(Setting::query()->first());
    }
}
