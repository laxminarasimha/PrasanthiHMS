<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use App\Support\DoctorFee;
use App\Workforce\Models\Employee;
use App\Workforce\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Each doctor's own OPD consultation fee: set on the doctor's page, kept as a service of theirs, billed when the
 * doctor is chosen, and never carried to another doctor.
 */
class DoctorFeeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $here;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-25 10:00:00');
        $this->here = Branch::create(['name' => 'Sample Hospital']);
        $this->admin = $this->user('admin', ['name' => 'Owner']);
    }

    private function user(string $role, array $more = []): User
    {
        return User::factory()->create($more + ['role' => $role, 'branch_id' => $this->here->id]);
    }

    /** What the login page sends when it is saved. */
    private function page(User $login, array $more = []): array
    {
        return $more + ['name' => $login->name, 'phone' => '98480 1'.str_pad((string) $login->id, 4, '0', STR_PAD_LEFT), 'email' => $login->email, 'role' => $login->role->value];
    }

    public function test_a_fee_set_on_the_doctors_page_is_a_service_of_theirs_and_is_billed_for_their_visits(): void
    {
        $first = $this->user('doctor', ['name' => 'Dr Sample First']);
        $second = $this->user('doctor', ['name' => 'Dr Sample Second']);
        $patient = Patient::factory()->create();
        Sanctum::actingAs($this->admin);

        $this->putJson("/api/staff/{$first->id}", $this->page($first, ['opd_fee' => 600]))->assertOk()->assertJsonPath('opd_fee', 600);
        $this->putJson("/api/staff/{$second->id}", $this->page($second, ['opd_fee' => 500]))->assertOk()->assertJsonPath('opd_fee', 500);

        $services = collect($this->getJson('/api/services')->assertOk()->json());
        $mine = $services->firstWhere('doctor_id', $first->id);
        $this->assertSame([DoctorFee::NAME, 'appointment', 600, true], [$mine['name'], $mine['type'], $mine['price'], $mine['is_doctor_fee']]);
        $this->assertCount(2, $services);

        // The desk, which may not change a price, books each doctor at their own fee.
        Sanctum::actingAs($this->user('frontdesk'));
        $booked = $this->postJson('/api/appointments', ['patient_id' => $patient->id, 'doctor_id' => $first->id, 'service_id' => $mine['id'], 'scheduled_at' => '2026-11-25 11:00', 'status' => 'arrived'])->assertCreated();
        $this->assertEquals(600, $booked->json('bills.0.total'));

        // One doctor's fee is not billed for another doctor's visit.
        $this->postJson('/api/appointments', ['patient_id' => $patient->id, 'doctor_id' => $second->id, 'service_id' => $mine['id'], 'scheduled_at' => '2026-11-25 11:30', 'status' => 'arrived'])
            ->assertStatus(422)->assertJsonValidationErrors('service_id');

        // Moved to the other doctor with nothing said about the service: the visit takes that doctor's own.
        $this->putJson('/api/appointments/'.$booked->json('id'), ['doctor_id' => $second->id])->assertOk()
            ->assertJsonPath('service.id', DoctorFee::of($second->id)->id);
    }

    public function test_changing_the_fee_changes_the_same_service_and_needs_someone_who_sets_prices(): void
    {
        $doctor = $this->user('doctor', ['name' => 'Dr Sample First']);
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/staff/{$doctor->id}", $this->page($doctor, ['opd_fee' => 600]))->assertOk();
        $this->putJson("/api/staff/{$doctor->id}", $this->page($doctor, ['opd_fee' => 700]))->assertOk()->assertJsonPath('opd_fee', 700);
        $this->assertSame(1, Service::count());

        // Someone who manages logins but may not set prices: the page saves with the fee as it is, and not with another.
        $manager = $this->user('manager');
        $this->assertFalse($manager->allows('canSetPrices'));
        $manager->forceFill(['permissions' => ['canManageStaff' => true]])->save();
        Sanctum::actingAs($manager->fresh());
        $this->putJson("/api/staff/{$doctor->id}", $this->page($doctor, ['opd_fee' => 700]))->assertOk();
        $this->putJson("/api/staff/{$doctor->id}", $this->page($doctor, ['opd_fee' => 900]))->assertStatus(422)->assertJsonValidationErrors('opd_fee');
        $this->assertEquals(700, DoctorFee::of($doctor->id)->price);

        // Emptied: the doctor follows the hospital's list. The service is switched off, and opened again by a new fee.
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/staff/{$doctor->id}", $this->page($doctor, ['opd_fee' => null]))->assertOk()->assertJsonPath('opd_fee', null);
        $this->assertSame([1, false], [Service::count(), Service::first()->is_active]);
        $this->putJson("/api/staff/{$doctor->id}", $this->page($doctor, ['opd_fee' => 650]))->assertOk();
        $this->assertSame([1, true, 650.0], [Service::count(), Service::first()->is_active, Service::first()->price]);
    }

    public function test_a_doctor_has_one_usual_consultation_and_a_staff_record_can_carry_the_fee(): void
    {
        $doctor = $this->user('doctor', ['name' => 'Dr Sample First']);
        Sanctum::actingAs($this->admin);

        // Made by hand on the price list, as before: with no mark, the first of the doctor's own is the usual one.
        $review = $this->postJson('/api/services', ['name' => 'Review visit', 'type' => 'appointment', 'price' => 300, 'doctor_id' => $doctor->id, 'priority' => 2])->assertCreated()->json('id');
        $this->assertSame($review, DoctorFee::of($doctor->id)->id);
        $full = $this->postJson('/api/services', ['name' => 'Consultation', 'type' => 'appointment', 'price' => 600, 'doctor_id' => $doctor->id, 'priority' => 2, 'is_doctor_fee' => true])->assertCreated()->json('id');
        $this->assertSame($full, DoctorFee::of($doctor->id)->id);
        // Marking the other takes the mark off the first.
        $this->putJson("/api/services/{$review}", ['name' => 'Review visit', 'type' => 'appointment', 'price' => 300, 'doctor_id' => $doctor->id, 'is_doctor_fee' => true])->assertOk();
        $this->assertSame([$review], Service::where('is_doctor_fee', true)->pluck('id')->all());
        // A service open to every doctor cannot be one doctor's usual consultation.
        $this->postJson('/api/services', ['name' => 'General consultation', 'type' => 'appointment', 'price' => 400, 'is_doctor_fee' => true])->assertCreated()->assertJsonPath('is_doctor_fee', false);

        // A consultant put on the doctors' list from the staff record, fee and all.
        $consultant = Tenant::run($this->here->id, fn () => Employee::create(['employee_no' => 'E90001', 'name' => 'Dr Sample Consultant', 'employment_type' => 'consultant']));
        $made = $this->putJson("/api/staff/doctor-for/{$consultant->id}", ['doctor' => true, 'qualification' => 'MS ENT', 'opd_fee' => 500])->assertOk()->json('login_id');
        $this->assertEquals(500, DoctorFee::of($made)->price);
        $this->assertEquals(500, collect($this->getJson('/api/workforce/employees')->json('data'))->firstWhere('id', $consultant->id)['login']['opd_fee']);
    }
}
