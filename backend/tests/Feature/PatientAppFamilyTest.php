<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Bill;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientAppFamilyTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $mobile = '9876543210', string $name = 'Lakshmi Devi'): PatientAccount
    {
        $this->postJson('/api/app/register', ['name' => $name, 'mobile' => $mobile, 'gender' => 'female', 'date_of_birth' => '1970-05-01', 'password' => 'Health2026', 'password_confirmation' => 'Health2026'])->assertCreated();

        return PatientAccount::where('mobile', $mobile)->first();
    }

    public function test_one_login_looks_after_family_members_and_nobody_else(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Rao']);
        $account = $this->account();
        $stranger = Patient::factory()->create();
        Sanctum::actingAs($account);

        $this->getJson('/api/app/me')->assertOk()->assertJsonCount(1, 'people')->assertJsonPath('people.0.relation', 'Self');
        $father = ['name' => 'Venkata Rao', 'gender' => 'male', 'date_of_birth' => '1945-02-10', 'relation' => 'Father'];
        $this->postJson('/api/app/family', ['relation' => 'Neighbour'] + $father)->assertStatus(422)->assertJsonValidationErrors('relation');
        $people = $this->postJson('/api/app/family', $father)->assertCreated()->assertJsonCount(2, 'people')->json('people');
        $fatherId = $people[1]['patient_id'];
        $this->assertSame(['Venkata Rao', 'Father'], [$people[1]['name'], $people[1]['relation']]);
        $this->assertSame('9876543210', Patient::find($fatherId)->mobile);
        // The same person twice is refused.
        $this->postJson('/api/app/family', $father)->assertStatus(422)->assertJsonValidationErrors('name');

        // Booking for the father, and never for someone who is not on the account.
        $at = now()->addDay()->setTime(10, 0)->format('Y-m-d H:i');
        $slot = collect($this->getJson("/api/app/doctors/{$doctor->id}/slots?date=".substr($at, 0, 10))->json())->firstWhere('available', true);
        $when = substr($at, 0, 10).' '.$slot['time'];
        $this->postJson('/api/app/appointments', ['doctor_id' => $doctor->id, 'scheduled_at' => $when, 'patient_id' => $stranger->id])->assertNotFound();
        $booked = $this->postJson('/api/app/appointments', ['doctor_id' => $doctor->id, 'scheduled_at' => $when, 'patient_id' => $fatherId])->assertCreated()->assertJsonPath('patient.name', 'Venkata Rao')->json();
        $this->assertSame($fatherId, Appointment::find($booked['id'])->patient_id);
        $this->getJson('/api/app/appointments')->assertOk()->assertJsonCount(1)->assertJsonPath('0.patient.id', $fatherId);
        $this->getJson("/api/app/records?patient_id={$stranger->id}")->assertNotFound();
        $this->getJson("/api/app/bills?patient_id={$stranger->id}")->assertNotFound();

        // The appointment shows on the first screen, with whose it is.
        $home = $this->getJson('/api/app/home')->assertOk()->assertJsonPath('verified', false)->json('notices');
        $this->assertSame(['appointment', 'verify'], array_column($home, 'kind'));
        $this->assertStringContainsString('for Venkata Rao', $home[0]['title']);

        // He cannot be taken off while his appointment stands; after cancelling he can, and his record stays.
        $this->deleteJson("/api/app/family/{$fatherId}")->assertStatus(422);
        $this->deleteJson("/api/app/appointments/{$booked['id']}")->assertOk();
        $this->deleteJson("/api/app/family/{$account->patient_id}")->assertStatus(422);
        $this->deleteJson("/api/app/family/{$stranger->id}")->assertNotFound();
        $this->deleteJson("/api/app/family/{$fatherId}")->assertOk()->assertJsonCount(1, 'people');
        $this->assertNotNull(Patient::find($fatherId));
        $this->getJson("/api/app/records?patient_id={$fatherId}")->assertNotFound();

        // Another family's login never sees this one's people.
        Sanctum::actingAs($this->account('9000000001', 'Someone Else'));
        $this->getJson('/api/app/family')->assertOk()->assertJsonCount(1, 'people');
        $this->getJson("/api/app/records?patient_id={$account->patient_id}")->assertNotFound();
    }

    public function test_bills_reports_and_money_due_show_only_after_verification(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Rao']);
        $account = $this->account();
        $patientId = $account->patient_id;

        // The desk bills a ₹300 visit and takes ₹100; the lab reports a test; the doctor asks for a visit in five days.
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $bill = $this->postJson('/api/appointments', ['patient_id' => $patientId, 'doctor_id' => $doctor->id, 'scheduled_at' => now()->format('Y-m-d H:i'), 'service_id' => Service::factory()->create(['price' => 300])->id])->assertCreated()->json('bills.0.id');
        $this->postJson("/api/bills/{$bill}/payments", ['type' => 'payment', 'amount' => 100, 'mode' => 'cash'])->assertCreated();
        $order = LabOrder::create(['patient_id' => $patientId, 'status' => 'completed', 'ordered_at' => now()]);
        $order->items()->create(['test_name' => 'HbA1c', 'price' => 400, 'reported_at' => now()])->results()->create(['parameter' => 'HbA1c', 'value' => '8.1', 'flag' => 'high']);
        Sanctum::actingAs($doctor);
        $visit = $this->postJson('/api/doctor/visits', ['patient_id' => $patientId])->json('id');
        $this->putJson("/api/appointments/{$visit}/consultation", ['diagnosis' => 'Diabetes', 'follow_up_on' => now()->addDays(5)->toDateString(), 'complete' => true])->assertOk();

        Sanctum::actingAs($account);
        $this->getJson('/api/app/bills')->assertOk()->assertJsonPath('verified', false)->assertJsonCount(0, 'bills');
        $kinds = array_column($this->getJson('/api/app/home')->json('notices'), 'kind');
        $this->assertNotContains('report', $kinds);
        $this->assertNotContains('due', $kinds);

        $account->update(['verified_at' => now()]);
        Sanctum::actingAs($account->fresh());
        $bills = $this->getJson('/api/app/bills')->assertOk()->assertJsonPath('verified', true)->assertJsonPath('due', 200)->json('bills');
        $this->assertSame([Bill::find($bill)->bill_no, 'Consultation'], [$bills[0]['bill_no'], $bills[0]['kind']]);
        $this->assertEquals([300, 100, 200], [$bills[0]['total'], $bills[0]['paid'], $bills[0]['due']]);

        $home = collect($this->getJson('/api/app/home')->assertJsonPath('due', 200)->json('notices'))->keyBy('kind');
        $this->assertStringContainsString('HbA1c', $home['report']['text']);
        $this->assertStringContainsString('Dr Rao asked to see you again', $home['follow_up']['text']);
        $this->assertSame('₹200 is due', $home['due']['title']);
        $this->assertFalse($home->has('verify'));

        // The hospital page lists the doctors.
        $this->getJson('/api/app/hospital')->assertOk()->assertJsonPath('doctors.0.name', 'Dr Rao');
    }

    public function test_a_doctor_sees_abnormal_results_of_their_own_patients(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $other = User::factory()->create(['role' => 'doctor']);
        $mine = Patient::factory()->create(['name' => 'My Patient']);
        $theirs = Patient::factory()->create(['name' => 'Their Patient']);
        Appointment::create(['patient_id' => $mine->id, 'doctor_id' => $doctor->id, 'scheduled_at' => now()->subDays(3), 'status' => 'reviewed', 'token_no' => 1]);
        Appointment::create(['patient_id' => $theirs->id, 'doctor_id' => $other->id, 'scheduled_at' => now()->subDays(3), 'status' => 'reviewed', 'token_no' => 1]);

        foreach ([$mine, $theirs] as $patient) {
            $order = LabOrder::create(['patient_id' => $patient->id, 'status' => 'completed', 'ordered_at' => now()->subDay()]);
            $item = $order->items()->create(['test_name' => 'Renal profile', 'price' => 500, 'reported_at' => now()->subHours(2)]);
            $item->results()->create(['parameter' => 'Creatinine', 'unit' => 'mg/dL', 'reference_range' => '0.6 - 1.2', 'value' => '2.4', 'flag' => 'high']);
            $item->results()->create(['parameter' => 'Urea', 'value' => '30']);
        }
        // An old abnormal result is not news.
        $old = LabOrder::create(['patient_id' => $mine->id, 'status' => 'completed', 'ordered_at' => now()->subDays(20)]);
        $old->items()->create(['test_name' => 'CBP', 'price' => 300, 'reported_at' => now()->subDays(20)])->results()->create(['parameter' => 'Hb', 'value' => '7', 'flag' => 'low']);

        Sanctum::actingAs($doctor);
        $alerts = $this->getJson('/api/doctor/abnormal-results')->assertOk()->assertJsonCount(1)->json();
        $this->assertSame(['My Patient', 'Creatinine', '2.4', 'high'], [$alerts[0]['patient']['name'], $alerts[0]['results'][0]['parameter'], $alerts[0]['results'][0]['value'], $alerts[0]['results'][0]['flag']]);
        $this->assertCount(1, $alerts[0]['results']); // the normal value is left out

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/doctor/abnormal-results')->assertOk()->assertJsonCount(2);
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson('/api/doctor/abnormal-results')->assertForbidden();
    }

    public function test_a_patient_can_close_their_own_account_and_the_hospital_record_stays(): void
    {
        $account = $this->account();
        $patientId = $account->patient_id;
        Sanctum::actingAs($account);
        $this->postJson('/api/app/family', ['name' => 'Venkata Rao', 'gender' => 'male', 'date_of_birth' => '1945-02-10', 'relation' => 'Father'])->assertCreated();
        $this->postJson('/api/app/subscriptions', ['title' => 'Sugar tablets', 'day_of_month' => 5, 'items' => [['name' => 'Metformin 500', 'quantity' => 60]]])->assertCreated();

        $this->postJson('/api/app/account/delete', ['password' => 'wrong-one'])->assertStatus(422)->assertJsonValidationErrors('password');
        $this->assertNotNull(PatientAccount::find($account->id));

        $this->postJson('/api/app/account/delete', ['password' => 'Health2026'])->assertOk();
        $this->assertNull(PatientAccount::find($account->id));
        $this->assertSame(0, \App\Models\MedicineSubscription::count());
        $this->assertSame(0, \App\Models\PatientAccountMember::count());
        $this->assertNotNull(Patient::find($patientId)); // the hospital's own record is kept
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'app.deleted')->where('patient_id', $patientId)->count());

        // The mobile number is free to register again.
        $this->postJson('/api/app/register', ['name' => 'Lakshmi Devi', 'mobile' => '9876543210', 'gender' => 'female', 'date_of_birth' => '1970-05-01', 'password' => 'Health2026', 'password_confirmation' => 'Health2026'])->assertCreated();
    }
}
