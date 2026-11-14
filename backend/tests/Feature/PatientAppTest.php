<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\DoctorBlock;
use App\Models\Medicine;
use App\Models\MedicineSubscription;
use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientAppTest extends TestCase
{
    use RefreshDatabase;

    private array $person = ['name' => 'Lakshmi Devi', 'mobile' => '9876543210', 'gender' => 'female', 'date_of_birth' => '1970-05-01', 'password' => 'Health2026', 'password_confirmation' => 'Health2026'];

    private function account(string $mobile = '9876543210'): PatientAccount
    {
        $this->postJson('/api/app/register', ['mobile' => $mobile] + $this->person)->assertCreated();

        return PatientAccount::where('mobile', $mobile)->first();
    }

    public function test_a_patient_registers_and_signs_in(): void
    {
        $this->postJson('/api/app/register', ['mobile' => '12345'] + $this->person)->assertStatus(422)->assertJsonValidationErrors('mobile');
        $this->postJson('/api/app/register', ['password' => 'short', 'password_confirmation' => 'short'] + $this->person)->assertStatus(422)->assertJsonValidationErrors('password');

        $made = $this->postJson('/api/app/register', $this->person)->assertCreated()->assertJsonPath('account.name', 'Lakshmi Devi')->assertJsonPath('account.verified', false)->json();
        $this->assertNotEmpty($made['token']);
        $this->assertNotEmpty($made['account']['patient']['uhid']);
        $this->assertSame('Mobile app', Patient::first()->channel);

        $this->postJson('/api/app/register', $this->person)->assertStatus(422)->assertJsonValidationErrors('mobile'); // already has an account
        $this->postJson('/api/app/login', ['mobile' => '9876543210', 'password' => 'wrong'])->assertStatus(422);
        $token = $this->postJson('/api/app/login', ['mobile' => '98765 43210', 'password' => 'Health2026'])->assertOk()->json('token');
        $this->getJson('/api/app/me', ['Authorization' => "Bearer {$token}"])->assertOk()->assertJsonPath('mobile', '9876543210');
    }

    public function test_patient_and_staff_logins_cannot_reach_each_others_screens(): void
    {
        $patientToken = $this->postJson('/api/app/register', $this->person)->json('token');
        $other = Patient::factory()->create();
        $asPatient = ['Authorization' => "Bearer {$patientToken}"];

        // A patient's token opens nothing on the staff side.
        foreach (['/api/me', '/api/patients', "/api/patients/{$other->id}", '/api/doctor/queue', '/api/pharmacy/medicines', '/api/reports/summary', '/api/backups', '/api/pharmacy/subscriptions'] as $url) {
            $this->getJson($url, $asPatient)->assertForbidden();
        }
        $this->postJson('/api/patients', ['name' => 'X'], $asPatient)->assertForbidden();

        // A staff token opens nothing in the patient app.
        $staff = User::factory()->create(['role' => 'admin'])->createToken('web')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/app/me', ['Authorization' => "Bearer {$staff}"])->assertForbidden();
        $this->getJson('/api/app/appointments', ['Authorization' => "Bearer {$staff}"])->assertForbidden();

        // A switched-off account is refused.
        PatientAccount::first()->update(['is_active' => false]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/app/me', $asPatient)->assertForbidden();
        $this->postJson('/api/app/login', ['mobile' => '9876543210', 'password' => 'Health2026'])->assertStatus(422);
    }

    public function test_a_patient_books_and_cancels_their_own_appointment(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(9)); // 9 AM, before OPD starts
        $doctor = User::factory()->create(['role' => 'doctor']);
        $account = $this->account();
        Sanctum::actingAs($account);
        $day = today()->addDay()->toDateString();

        $this->getJson('/api/app/doctors')->assertOk()->assertJsonCount(1)->assertJsonMissingPath('0.email');
        $slots = collect($this->getJson("/api/app/doctors/{$doctor->id}/slots?date={$day}")->assertOk()->json());
        $this->assertSame('10:30', $slots->first()['time']); // hospital OPD hours when the doctor has none of their own
        $this->assertTrue($slots->every(fn ($s) => $s['available']));
        $this->getJson("/api/app/doctors/{$doctor->id}/slots?date=".today()->addDays(60)->toDateString())->assertStatus(422);

        $this->postJson('/api/app/appointments', ['doctor_id' => $doctor->id, 'scheduled_at' => "{$day} 03:00"])->assertStatus(422)->assertJsonValidationErrors('scheduled_at');
        $booked = $this->postJson('/api/app/appointments', ['doctor_id' => $doctor->id, 'scheduled_at' => "{$day} 10:45", 'notes' => 'Sugar check'])
            ->assertCreated()->assertJsonPath('status', 'booked')->assertJsonPath('token_no', 1)->assertJsonPath('can_cancel', true)->json();
        $appointment = Appointment::find($booked['id']);
        $this->assertSame(['app', $account->patient_id], [$appointment->booked_via, $appointment->patient_id]);

        // The same person cannot book that doctor twice in a day, and nobody else can take the same time.
        $this->postJson('/api/app/appointments', ['doctor_id' => $doctor->id, 'scheduled_at' => "{$day} 11:00"])->assertStatus(422);
        $second = $this->account('9000000001');
        Sanctum::actingAs($second);
        $slots = collect($this->getJson("/api/app/doctors/{$doctor->id}/slots?date={$day}")->json())->keyBy('time');
        $this->assertFalse($slots['10:45']['available']);
        $this->postJson('/api/app/appointments', ['doctor_id' => $doctor->id, 'scheduled_at' => "{$day} 10:45"])->assertStatus(422);
        $this->postJson('/api/app/appointments', ['doctor_id' => $doctor->id, 'scheduled_at' => "{$day} 11:00"])->assertCreated()->assertJsonPath('token_no', 2);

        // Each sees and cancels only their own.
        $this->getJson('/api/app/appointments')->assertJsonCount(1);
        $this->deleteJson("/api/app/appointments/{$booked['id']}")->assertNotFound();
        Sanctum::actingAs($account);
        $this->deleteJson("/api/app/appointments/{$booked['id']}")->assertOk()->assertJsonPath('status', 'cancelled');
        $this->deleteJson("/api/app/appointments/{$booked['id']}")->assertStatus(422);

        // The front desk sees the booking in its own list.
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson("/api/doctor/queue?date={$day}")->assertOk()->assertJsonCount(1);
    }

    public function test_slots_follow_the_doctors_hours_leave_and_the_clock(): void
    {
        $this->travelTo(now()->startOfDay()->addHours(11)); // 11 AM today
        $doctor = User::factory()->create(['role' => 'doctor']);
        $weekday = strtolower(today()->format('D'));
        $doctor->update(['availability' => [$weekday => [['from' => '09:00', 'to' => '13:00']]]]);
        Setting::updateOrCreate(['key' => 'calendar'], ['value' => ['slot_minutes' => 30] + Setting::CALENDAR_DEFAULTS]);
        DoctorBlock::create(['doctor_id' => $doctor->id, 'from_date' => today(), 'to_date' => today(), 'start_time' => '12:00', 'end_time' => '13:00']);
        Sanctum::actingAs($this->account());

        $slots = collect($this->getJson("/api/app/doctors/{$doctor->id}/slots?date=".today()->toDateString())->json())->keyBy('time');
        $this->assertSame(['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '12:30'], $slots->keys()->all());
        $this->assertFalse($slots['10:30']['available']); // already past
        $this->assertFalse($slots['11:00']['available']); // too close to now
        $this->assertTrue($slots['11:30']['available']);
        $this->assertFalse($slots['12:00']['available']); // the doctor is away

        // No hours set for tomorrow's weekday means no times that day.
        $this->getJson("/api/app/doctors/{$doctor->id}/slots?date=".today()->addDay()->toDateString())->assertOk()->assertJsonCount(0);
    }

    public function test_monthly_medicine_lists_reach_the_pharmacy(): void
    {
        $this->travelTo('2026-03-10 09:00');
        $medicine = Medicine::create(['name' => 'Metformin 500mg Tablet', 'gst_percent' => 5, 'units_per_pack' => 10]);
        $account = $this->account();
        Sanctum::actingAs($account);

        $this->getJson('/api/app/medicines?q=metf')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'Metformin 500mg Tablet')->assertJsonMissingPath('0.gst_percent');
        $this->postJson('/api/app/subscriptions', ['title' => 'Diabetes', 'day_of_month' => 31, 'items' => [['name' => 'x', 'quantity' => 1]]])->assertStatus(422)->assertJsonValidationErrors('day_of_month');
        $this->postJson('/api/app/subscriptions', ['title' => 'Diabetes', 'day_of_month' => 5, 'items' => []])->assertStatus(422)->assertJsonValidationErrors('items');

        $list = $this->postJson('/api/app/subscriptions', [
            'title' => 'Diabetes medicines', 'day_of_month' => 5,
            'items' => [['medicine_id' => $medicine->id, 'name' => 'Metformin 500mg Tablet', 'quantity' => 60], ['name' => 'Thyronorm 50mcg', 'quantity' => 30]],
        ])->assertCreated()->assertJsonPath('next_due_on', '2026-04-05')->assertJsonPath('status', 'active')->json();
        $this->getJson('/api/app/subscriptions')->assertJsonCount(1);

        // Another patient cannot see or change it.
        Sanctum::actingAs($this->account('9000000001'));
        $this->getJson('/api/app/subscriptions')->assertJsonCount(0);
        $this->putJson("/api/app/subscriptions/{$list['id']}", ['title' => 'Mine now', 'day_of_month' => 5, 'items' => [['name' => 'x', 'quantity' => 1]]])->assertNotFound();
        $this->deleteJson("/api/app/subscriptions/{$list['id']}")->assertNotFound();

        // The pharmacy sees it once it is within a week, makes the pack, and it rolls to next month.
        $pharmacist = User::factory()->create(['role' => 'pharmacist']);
        Sanctum::actingAs($pharmacist);
        $this->getJson('/api/pharmacy/subscriptions')->assertOk()->assertJsonCount(0, 'subscriptions')->assertJsonPath('counts.active', 1);
        $this->postJson("/api/pharmacy/subscriptions/{$list['id']}/prepared")->assertStatus(422); // not due yet
        $this->travelTo('2026-04-02 09:00');
        $this->getJson('/api/pharmacy/subscriptions')->assertJsonCount(1, 'subscriptions')->assertJsonPath('subscriptions.0.patient.name', 'Lakshmi Devi')->assertJsonPath('subscriptions.0.items.0.quantity', 60);
        $this->postJson("/api/pharmacy/subscriptions/{$list['id']}/prepared")->assertOk()->assertJsonPath('next_due_on', '2026-05-05')->assertJsonPath('last_prepared_on', '2026-04-02');
        $this->getJson('/api/pharmacy/subscriptions')->assertJsonCount(0, 'subscriptions');
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson('/api/pharmacy/subscriptions')->assertForbidden();

        // The patient pauses, restarts on another day, then cancels.
        Sanctum::actingAs($account);
        $body = ['title' => 'Diabetes medicines', 'day_of_month' => 5, 'items' => [['name' => 'Metformin 500mg Tablet', 'quantity' => 90]]];
        $this->putJson("/api/app/subscriptions/{$list['id']}", $body + ['status' => 'paused'])->assertOk()->assertJsonPath('status', 'paused')->assertJsonPath('items.0.quantity', 90);
        $this->putJson("/api/app/subscriptions/{$list['id']}", ['day_of_month' => 20, 'status' => 'active'] + $body)->assertOk()->assertJsonPath('next_due_on', '2026-04-20');
        $this->deleteJson("/api/app/subscriptions/{$list['id']}")->assertOk();
        $this->getJson('/api/app/subscriptions')->assertJsonCount(0);
        $this->assertSame('cancelled', MedicineSubscription::first()->status);
    }

    public function test_records_open_only_after_the_hospital_verifies_the_account(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor', 'name' => 'Dr Rao']);
        $account = $this->account();
        $patient = $account->patient;
        $stranger = Patient::factory()->create();

        // A finished visit, a draft visit, a reported lab test, a pending one, and another patient's records.
        Sanctum::actingAs($doctor);
        $visit = $this->postJson('/api/doctor/visits', ['patient_id' => $patient->id])->json('id');
        $this->putJson("/api/appointments/{$visit}/consultation", ['diagnosis' => 'Type 2 diabetes', 'advice' => 'Walk daily', 'quick_notes' => 'private note', 'complete' => true, 'medicines' => [['medicine' => 'Metformin 500', 'frequency' => '1-0-1', 'duration' => '30 days']]])->assertOk();
        $draft = $this->postJson('/api/doctor/visits', ['patient_id' => $patient->id])->json('id');
        $this->putJson("/api/appointments/{$draft}/consultation", ['diagnosis' => 'Not finished yet'])->assertOk();
        $order = \App\Models\LabOrder::create(['patient_id' => $patient->id, 'status' => 'partial', 'ordered_at' => now()]);
        $order->items()->create(['test_name' => 'HbA1c', 'price' => 400, 'reported_at' => now()])->results()->create(['parameter' => 'HbA1c', 'unit' => '%', 'reference_range' => '4 - 5.6', 'value' => '8.1', 'flag' => 'high']);
        $order->items()->create(['test_name' => 'Lipid profile', 'price' => 600])->results()->create(['parameter' => 'Cholesterol', 'value' => '999']);
        $other = \App\Models\LabOrder::create(['patient_id' => $stranger->id, 'status' => 'completed', 'ordered_at' => now()]);
        $other->items()->create(['test_name' => 'Secret', 'price' => 1, 'reported_at' => now()])->results()->create(['parameter' => 'Secret', 'value' => '1']);

        Sanctum::actingAs($account);
        $this->getJson('/api/app/records')->assertOk()->assertJsonPath('verified', false)->assertJsonCount(0, 'visits')->assertJsonCount(0, 'lab');

        // The front desk verifies; a pharmacist may not.
        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $this->postJson("/api/patients/{$patient->id}/app-account/verify", ['verified' => true])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $this->getJson("/api/patients/{$stranger->id}/app-account")->assertOk()->assertJsonPath('account', null);
        $this->postJson("/api/patients/{$stranger->id}/app-account/verify", ['verified' => true])->assertNotFound();
        $this->getJson("/api/patients/{$patient->id}/app-account")->assertOk()->assertJsonPath('account.mobile', '9876543210')->assertJsonPath('account.verified_at', null);
        $this->postJson("/api/patients/{$patient->id}/app-account/verify", ['verified' => true])->assertOk()->assertJsonPath('account.verified_at', now()->format('Y-m-d H:i'));

        Sanctum::actingAs($account->fresh());
        $records = $this->getJson('/api/app/records')->assertOk()->assertJsonPath('verified', true)->assertJsonCount(1, 'visits')->assertJsonCount(1, 'lab')->json();
        $this->assertSame(['Type 2 diabetes', 'Dr Rao'], [$records['visits'][0]['diagnosis'], $records['visits'][0]['doctor']['name']]);
        $this->assertSame('Metformin 500', $records['visits'][0]['medicines'][0]['medicine']);
        $this->assertArrayNotHasKey('quick_notes', $records['visits'][0]); // the doctor's private notes stay private
        $this->assertSame(['HbA1c'], array_column($records['lab'][0]['tests'], 'name')); // the unreported test is held back
        $this->assertSame('8.1', $records['lab'][0]['tests'][0]['results'][0]['value']);
        $this->assertStringNotContainsString('Secret', json_encode($records));

        // Where the lab approves results first, an unapproved one is not shown.
        Setting::updateOrCreate(['key' => 'lab'], ['value' => ['approval_required' => true] + Setting::LAB_DEFAULTS]);
        $this->getJson('/api/app/records')->assertJsonCount(0, 'lab');
    }

    public function test_a_patient_changes_their_password_and_staff_can_reset_it(): void
    {
        $account = $this->account();
        $second = $account->createToken('tablet')->plainTextToken;
        $first = $this->postJson('/api/app/login', ['mobile' => '9876543210', 'password' => 'Health2026'])->json('token');
        $auth = ['Authorization' => "Bearer {$first}"];

        $this->getJson('/api/app/hospital', $auth)->assertOk()->assertJsonStructure(['name', 'address', 'phone']);
        $new = ['password' => 'Better2027', 'password_confirmation' => 'Better2027'];
        $this->postJson('/api/app/password', ['current_password' => 'wrong'] + $new, $auth)->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->postJson('/api/app/password', ['current_password' => 'Health2026', 'password' => 'short', 'password_confirmation' => 'short'], $auth)->assertStatus(422);
        $this->postJson('/api/app/password', ['current_password' => 'Health2026'] + $new, $auth)->assertOk();
        $this->assertNull(\Laravel\Sanctum\PersonalAccessToken::findToken($second)); // other devices are signed out
        $this->postJson('/api/app/login', ['mobile' => '9876543210', 'password' => 'Health2026'])->assertStatus(422);
        $this->postJson('/api/app/login', ['mobile' => '9876543210', 'password' => 'Better2027'])->assertOk();

        // The patient forgets it: the front desk gives a one-time password, which signs every phone out.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $temporary = $this->postJson("/api/patients/{$account->patient_id}/app-account/password")->assertOk()->json('temporary_password');
        $this->assertMatchesRegularExpression('/^(?=.*[a-z])(?=.*[0-9]).{8,}$/', $temporary);
        $this->assertSame(0, $account->tokens()->count());
        $this->getJson("/api/patients/{$account->patient_id}/app-account")->assertJsonMissingPath('temporary_password');
        $this->postJson('/api/app/login', ['mobile' => '9876543210', 'password' => 'Better2027'])->assertStatus(422);
        $this->postJson('/api/app/login', ['mobile' => '9876543210', 'password' => $temporary])->assertOk();

        // Switching the account off signs it out and refuses sign-in; switching it on restores it.
        $this->postJson("/api/patients/{$account->patient_id}/app-account/active", ['active' => false])->assertOk()->assertJsonPath('account.is_active', false);
        $this->postJson('/api/app/login', ['mobile' => '9876543210', 'password' => $temporary])->assertStatus(422);
        $this->postJson("/api/patients/{$account->patient_id}/app-account/active", ['active' => true])->assertOk();
        $this->postJson('/api/app/login', ['mobile' => '9876543210', 'password' => $temporary])->assertOk();
    }
}
