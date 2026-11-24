<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Consultation;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\ReferralDoctor;
use App\Models\Service;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FrontDeskBatchOneTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;

    private Patient $patient;

    private Service $consultation;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-24 09:00'); // a Tuesday morning, before OPD opens
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Rao']);
        $this->patient = Patient::factory()->create();
        $this->consultation = Service::factory()->create(['price' => 200]);
    }

    private function book(array $with = [])
    {
        return $this->postJson('/api/appointments', $with + ['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'scheduled_at' => '2026-11-24 11:00']);
    }

    public function test_a_visit_booked_ahead_has_to_be_inside_the_doctors_hours_unless_the_desk_says_so(): void
    {
        // No hours of their own: the hospital's OPD hours (10:30–2:00, 6:00–9:00) apply.
        $this->book(['scheduled_at' => '2026-11-24 16:00'])->assertStatus(422)->assertJsonValidationErrors('outside_hours');
        $this->book(['scheduled_at' => '2026-11-24 16:00', 'outside_hours' => true])->assertCreated();
        $this->book(['scheduled_at' => '2026-11-24 11:00'])->assertCreated();

        // Their own hours replace the hospital's: Tuesday evenings only, never on Sunday.
        $this->doctor->update(['availability' => ['tue' => [['from' => '17:00', 'to' => '19:00']]]]);
        $this->book(['scheduled_at' => '2026-11-24 11:00'])->assertStatus(422)->assertJsonPath('errors.outside_hours.0', 'Dr Rao sees patients on Tuesdays from 5:00 PM – 7:00 PM. 11:00 AM is outside those hours.');
        $this->book(['scheduled_at' => '2026-11-29 18:00'])->assertStatus(422)->assertJsonPath('errors.outside_hours.0', 'Dr Rao does not see patients on Sundays. 6:00 PM is outside those hours.');
        $id = $this->book(['scheduled_at' => '2026-11-24 17:30'])->assertCreated()->json('id');

        // A patient who is already here is never refused for the hour, and nor is a time that has come.
        $this->book(['scheduled_at' => '2026-11-24 12:00', 'status' => 'arrived'])->assertCreated();
        $this->book(['scheduled_at' => '2026-11-24 08:30'])->assertCreated();

        // Moving a booked visit is checked the same way.
        $this->putJson("/api/appointments/{$id}", ['scheduled_at' => '2026-11-24 20:00'])->assertStatus(422)->assertJsonValidationErrors('outside_hours');
        $this->putJson("/api/appointments/{$id}", ['scheduled_at' => '2026-11-24 20:00', 'outside_hours' => true])->assertOk();
        $this->putJson("/api/appointments/{$id}", ['notes' => 'Brings old reports'])->assertOk();
    }

    public function test_a_doctor_can_have_their_own_slot_length(): void
    {
        Setting::updateOrCreate(['key' => 'calendar'], ['value' => ['slot_limit' => 1] + Setting::CALENDAR_DEFAULTS]);
        $this->putJson("/api/staff/{$this->doctor->id}", ['name' => 'Dr Rao', 'email' => $this->doctor->email, 'role' => 'doctor', 'gender' => 'male', 'phone' => '9876543210', 'slot_minutes' => 30])->assertOk();
        $this->assertSame(30, $this->doctor->fresh()->slot_minutes);
        $this->getJson('/api/doctors')->assertJsonPath('0.slot_minutes', 30);

        // With half-hour slots, 11:00 and 11:20 are the same slot; for a doctor on the hospital's 15 minutes they are not.
        $this->book(['scheduled_at' => '2026-11-24 11:00'])->assertCreated();
        $this->book(['scheduled_at' => '2026-11-24 11:20', 'patient_id' => Patient::factory()->create()->id])->assertStatus(422)->assertJsonPath('errors.scheduled_at.0', 'The 11:00 AM slot is full (1 per slot). Choose another time.');
        $other = User::factory()->create(['role' => 'doctor']);
        $this->book(['doctor_id' => $other->id, 'scheduled_at' => '2026-11-24 11:00'])->assertCreated();
        $this->book(['doctor_id' => $other->id, 'scheduled_at' => '2026-11-24 11:20', 'patient_id' => Patient::factory()->create()->id])->assertCreated();

        $this->putJson("/api/staff/{$this->doctor->id}", ['name' => 'Dr Rao', 'email' => $this->doctor->email, 'role' => 'doctor', 'gender' => 'male', 'phone' => '9876543210', 'slot_minutes' => 7])->assertStatus(422);
    }

    public function test_a_referring_doctor_on_the_list_is_one_doctor_however_the_name_is_typed(): void
    {
        $ravi = ReferralDoctor::create(['direction' => 'by', 'name' => 'Dr. Ravi Kumar', 'specialization' => 'Cardiology', 'place' => 'Guntur']);
        $register = fn (string $referredBy) => $this->postJson('/api/patients', ['name' => 'Sample Person', 'gender' => 'male', 'age' => 40, 'mobile' => (string) random_int(6000000000, 9999999999), 'referred_by' => $referredBy])->assertCreated();

        // Typed loosely, or picked with the speciality and place after the name: saved in the list's spelling and tied to it.
        $register('ravi kumar')->assertJsonPath('referred_by', 'Dr. Ravi Kumar')->assertJsonPath('referral_doctor_id', $ravi->id);
        $register('DR RAVI KUMAR, Cardiology, Guntur')->assertJsonPath('referral_doctor_id', $ravi->id);
        // Not on the list: kept as typed.
        $register('Dr Sita, Tenali')->assertJsonPath('referred_by', 'Dr Sita, Tenali')->assertJsonPath('referral_doctor_id', null);
        // A record from before the link existed is matched by its name when the report is made.
        Patient::factory()->create(['referred_by' => 'Dr Ravi  Kumar']);

        $rows = collect($this->getJson('/api/reports/list/patient-referrals?from=2026-11-24&to=2026-11-24')->assertOk()->json('rows'))->keyBy('referred_by');
        $this->assertSame(3, $rows['Dr. Ravi Kumar']['patients']);
        $this->assertSame(1, $rows['Dr Sita, Tenali']['patients']);

        // Renaming the doctor on the list renames the row; the linked patients follow.
        $ravi->update(['name' => 'Dr. K. Ravi Kumar']);
        $rows = collect($this->getJson('/api/reports/list/patient-referrals?from=2026-11-24&to=2026-11-24')->json('rows'))->keyBy('referred_by');
        $this->assertSame(2, $rows['Dr. K. Ravi Kumar']['patients']);

        // Lab orders are tied the same way.
        $test = \App\Models\LabTest::create(['name' => 'RBS', 'price' => 80]);
        $this->postJson('/api/lab/orders', ['patient_id' => $this->patient->id, 'test_ids' => [$test->id], 'referred_by' => 'k ravi kumar'])->assertCreated();
        $this->assertSame($ravi->id, LabOrder::latest('id')->first()->referral_doctor_id);
    }

    public function test_patients_advised_to_come_back_who_have_not_come_are_listed_to_ring(): void
    {
        $advise = function (Patient $patient, string $seen, string $due): Appointment {
            $visit = Appointment::create(['patient_id' => $patient->id, 'doctor_id' => $this->doctor->id, 'scheduled_at' => "{$seen} 11:00", 'status' => 'reviewed', 'arrived_at' => "{$seen} 10:50"]);
            Consultation::create(['appointment_id' => $visit->id, 'patient_id' => $patient->id, 'doctor_id' => $this->doctor->id, 'diagnosis' => 'Diabetes', 'follow_up_on' => $due, 'completed_at' => "{$seen} 11:20"]);

            return $visit;
        };
        [$late, $came, $booked, $notYet] = Patient::factory()->count(4)->create()->all();

        $advise($late, '2026-11-01', '2026-11-15');
        $advise($came, '2026-11-01', '2026-11-15');
        Appointment::create(['patient_id' => $came->id, 'doctor_id' => $this->doctor->id, 'scheduled_at' => '2026-11-18 11:00', 'status' => 'reviewed', 'arrived_at' => '2026-11-18 11:00']);
        $advise($booked, '2026-11-02', '2026-11-20');
        Appointment::create(['patient_id' => $booked->id, 'doctor_id' => $this->doctor->id, 'scheduled_at' => '2026-11-26 11:00', 'status' => 'booked']);
        // A booking that was missed is not a return.
        Appointment::create(['patient_id' => $late->id, 'doctor_id' => $this->doctor->id, 'scheduled_at' => '2026-11-15 11:00', 'status' => 'booked']);
        $advise($notYet, '2026-11-20', '2026-11-30');

        $list = collect($this->getJson('/api/front-desk/not-returned')->assertOk()->json())->keyBy('patient.id');
        $this->assertEqualsCanonicalizing([$late->id, $booked->id], $list->keys()->all());
        $this->assertSame(9, $list[$late->id]['days_late']);
        $this->assertNull($list[$late->id]['booked_for']);
        $this->assertSame('2026-11-26', $list[$booked->id]['booked_for']);

        $report = $this->getJson('/api/reports/list/not-returned?from=2026-11-01&to=2026-11-30')->assertOk();
        $report->assertJsonCount(2, 'rows')->assertJsonPath('rows.0.advised', '2026-11-15')->assertJsonPath('rows.0.booked_for', 'Not booked')->assertJsonPath('rows.1.booked_for', '2026-11-26');
    }

    public function test_a_discount_says_why_it_was_given_and_who_allowed_it(): void
    {
        $why = ['discount_reason' => 'Staff family', 'discount_allowed_by' => 'Dr Rao'];

        // At booking.
        $this->book(['service_id' => $this->consultation->id, 'status' => 'arrived', 'discount' => 50])->assertStatus(422)->assertJsonValidationErrors(['discount_reason', 'discount_allowed_by']);
        $this->assertSame(0, Appointment::count());
        $billId = $this->book(['service_id' => $this->consultation->id, 'status' => 'arrived', 'discount' => 50] + $why)->assertCreated()->json('bills.0.id');
        $this->assertSame('Staff family', Bill::find($billId)->discount_reason);

        // On a bill made without one.
        $plain = $this->book(['service_id' => $this->consultation->id, 'status' => 'arrived', 'patient_id' => Patient::factory()->create()->id])->assertCreated()->json('bills.0.id');
        $this->putJson("/api/bills/{$plain}", ['discount' => 20])->assertStatus(422)->assertJsonValidationErrors(['discount_reason', 'discount_allowed_by']);
        $this->putJson("/api/bills/{$plain}", ['discount' => 20, 'discount_reason' => 'Old patient'])->assertStatus(422)->assertJsonValidationErrors('discount_allowed_by');
        $this->putJson("/api/bills/{$plain}", ['discount' => 20, 'discount_reason' => 'Old patient', 'discount_allowed_by' => 'Dr Rao'])->assertOk()
            ->assertJsonPath('discount_reason', 'Old patient')->assertJsonPath('discount_allowed_by', 'Dr Rao');
        $this->assertStringContainsString('reason: Old patient · allowed by Dr Rao', AuditLog::where('action', 'bill.discount')->latest('id')->first()->summary);
        // A different amount needs its reason again; taking the discount off clears it.
        $this->putJson("/api/bills/{$plain}", ['discount' => 40])->assertStatus(422);
        $this->putJson("/api/bills/{$plain}", ['discount' => 0])->assertOk()->assertJsonPath('discount_reason', null)->assertJsonPath('discount_allowed_by', null);

        // On one line of a bill.
        $this->postJson("/api/bills/{$plain}/items", ['description' => 'Dressing', 'unit_price' => 100, 'line_discount' => 10])->assertStatus(422)->assertJsonValidationErrors('discount_reason');
        $this->postJson("/api/bills/{$plain}/items", ['description' => 'Dressing', 'unit_price' => 100, 'line_discount' => 10] + $why)->assertCreated()->assertJsonPath('discount_reason', 'Staff family');

        // The bill report shows both.
        $row = collect($this->getJson('/api/reports/list/bills?from=2026-11-24&to=2026-11-24')->assertOk()->json('rows'))->firstWhere('bill_no', Bill::find($billId)->bill_no);
        $this->assertSame(['Staff family', 'Dr Rao'], [$row['discount_reason'], $row['discount_allowed_by']]);
    }
}
