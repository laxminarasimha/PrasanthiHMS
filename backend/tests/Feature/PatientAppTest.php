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
}
