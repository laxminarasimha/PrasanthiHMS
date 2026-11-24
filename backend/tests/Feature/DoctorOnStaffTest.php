<?php

namespace Tests\Feature;

use App\Models\AdminEntry;
use App\Models\Admission;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\User;
use App\Workforce\Models\Employee;
use App\Workforce\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A doctor on the staff list is a doctor everywhere: for appointments, admissions, consultant visits and their fees,
 * whether or not they sign in.
 */
class DoctorOnStaffTest extends TestCase
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

    private function person(string $name, array $more = []): Employee
    {
        return Tenant::run($this->here->id, fn () => Employee::create($more + ['employee_no' => 'E'.random_int(10000, 99999), 'name' => $name]));
    }

    private function doctorNames(): array
    {
        return array_column($this->getJson('/api/doctors')->assertOk()->json(), 'name');
    }

    public function test_a_consultant_on_the_staff_list_is_put_on_the_doctors_list_without_a_login(): void
    {
        $ent = Tenant::run($this->here->id, fn () => AdminEntry::create(['kind' => 'department', 'name' => 'ENT', 'is_active' => true]));
        $consultant = $this->person('Dr Sample Consultant', ['phone' => '98480 55555', 'employment_type' => 'consultant', 'department_id' => $ent->id]);
        $patient = Patient::factory()->create();

        // On the staff list only: not a doctor anywhere yet. The staff check says so.
        Sanctum::actingAs($this->admin);
        $this->assertSame([], $this->doctorNames());
        $this->assertSame(['Dr Sample Consultant'], array_column($this->getJson('/api/workforce/staff-check')->json('consultants_not_doctors'), 'name'));

        // Whoever keeps the staff records puts them on the doctors' list. No login is given by it.
        Sanctum::actingAs($this->user('hr'));
        $id = $this->putJson("/api/staff/doctor-for/{$consultant->id}", ['doctor' => true, 'qualification' => 'MS ENT'])->assertOk()->assertJsonPath('doctor', true)->json('login_id');
        $doctor = User::find($id);
        $this->assertSame(['doctor', 'none', true, 'MS ENT', 'ENT', false, 'Dr Sample Consultant'], [$doctor->role->value, $doctor->sign_in_with, $doctor->is_active, $doctor->signature_text, $doctor->department, $doctor->book_online, $doctor->name]);
        $row = collect($this->getJson('/api/workforce/employees')->json('data'))->firstWhere('id', $consultant->id);
        $this->assertSame([true, false, 'MS ENT', false], [$row['login']['is_doctor'], $row['login']['signs_in'], $row['login']['qualification'], $row['regular']]);

        // Now they can be chosen wherever a doctor is chosen: an appointment, an admission, a consultant's visit.
        Sanctum::actingAs($this->admin);
        $this->assertSame(['Dr Sample Consultant'], $this->doctorNames());
        $this->assertSame([], $this->getJson('/api/workforce/staff-check')->json('consultants_not_doctors'));
        $this->postJson('/api/appointments', ['patient_id' => $patient->id, 'doctor_id' => $id, 'scheduled_at' => '2026-11-25 11:00', 'duration_minutes' => 15, 'status' => 'booked'])->assertCreated()->assertJsonPath('doctor.name', 'Dr Sample Consultant');
        $other = $this->user('doctor', ['name' => 'Dr Admitting']);
        $admission = Admission::create(['branch_id' => $this->here->id, 'patient_id' => $patient->id, 'doctor_id' => $other->id, 'admitted_at' => '2026-11-24 09:00', 'status' => 'admitted', 'created_by' => $this->admin->id]);
        $this->postJson("/api/ipd/admissions/{$admission->id}/consultant-visits", ['doctor_id' => $id, 'visited_on' => '2026-11-25'])->assertCreated()->assertJsonPath('fee', 1000);
        $this->assertContains('Dr Sample Consultant', array_column($this->getJson('/api/consultants?month=2026-11')->json('rows'), 'doctor'));

        // They cannot sign in: there is no login to open, by mobile number or any other way.
        app('auth')->forgetGuards();
        $this->postJson('/api/login', ['login' => '9848055555', 'password' => 'password'])->assertStatus(422);
        // And they are not offered to patients in the phone app until someone says so.
        $account = \App\Models\PatientAccount::create(['patient_id' => $patient->id, 'name' => 'App User', 'mobile' => '9876500000', 'password' => 'Health2026', 'verified_at' => now()]);
        Sanctum::actingAs($account->forceFill(['is_active' => true]));
        $this->assertSame(['Dr Admitting'], array_column($this->getJson('/api/app/doctors')->assertOk()->json(), 'name'));
        $this->getJson("/api/app/doctors/{$id}/slots?date=2026-11-26")->assertNotFound();
        $doctor->update(['book_online' => true]);
        $this->assertSame(['Dr Admitting', 'Dr Sample Consultant'], array_column($this->getJson('/api/app/doctors')->assertOk()->json(), 'name'));
    }

    public function test_a_doctor_on_the_lists_can_be_given_a_way_in_and_have_it_taken_away_again(): void
    {
        $consultant = $this->person('Dr Sample Consultant', ['phone' => '9848055555', 'employment_type' => 'consultant']);
        Sanctum::actingAs($this->admin);
        $id = $this->putJson("/api/staff/doctor-for/{$consultant->id}", ['doctor' => true])->assertOk()->json('login_id');
        $form = fn (array $more = []) => $more + ['name' => 'Dr Sample Consultant', 'phone' => '9848055555', 'role' => 'doctor', 'email' => null];

        // Saving their hours or their share leaves them without a login.
        $this->putJson("/api/staff/{$id}", $form(['slot_minutes' => 20, 'book_online' => true]))->assertOk()->assertJsonPath('sign_in_with', 'none')->assertJsonPath('book_online', true);
        // A password lets them sign in with their mobile number.
        $this->putJson("/api/staff/{$id}", $form(['password' => 'consult123']))->assertOk()->assertJsonPath('sign_in_with', 'password');
        app('auth')->forgetGuards();
        $token = $this->postJson('/api/login', ['login' => '9848055555', 'password' => 'consult123'])->assertOk()->json('token');
        // Taking the way in away signs them out and leaves them on the doctors' list.
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/staff/{$id}", $form(['signs_in' => false]))->assertOk()->assertJsonPath('sign_in_with', 'none')->assertJsonPath('is_active', true);
        $this->assertSame(0, User::find($id)->tokens()->count());
        $this->assertSame(['Dr Sample Consultant'], $this->doctorNames());
        app('auth')->forgetGuards();
        $this->postJson('/api/login', ['login' => '9848055555', 'password' => 'consult123'])->assertStatus(422);

        // Only a doctor is kept on the lists without signing in: another role needs a password in the same step.
        Sanctum::actingAs($this->admin);
        $this->putJson("/api/staff/{$id}", $form(['role' => 'nurse', 'reason' => 'Works as a nurse now']))->assertStatus(422)->assertJsonValidationErrors('password');
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        User::find($id)->update(['role' => 'frontdesk']);
    }

    public function test_coming_off_the_list_leaving_and_the_wrong_kind_of_login(): void
    {
        $consultant = $this->person('Dr Sample Consultant', ['phone' => '9848055555', 'employment_type' => 'consultant']);
        $desk = $this->user('frontdesk', ['name' => 'Desk Person']);
        $deskPerson = $this->person('Desk Person', ['user_id' => $desk->id]);
        $signing = $this->user('doctor', ['name' => 'Dr Signs In']);
        $signingPerson = $this->person('Dr Signs In', ['user_id' => $signing->id]);
        Sanctum::actingAs($this->admin);
        $id = $this->putJson("/api/staff/doctor-for/{$consultant->id}", ['doctor' => true])->assertOk()->json('login_id');

        // Someone who signs in as something else is made a doctor by changing their role, which is a matter of access.
        $this->putJson("/api/staff/doctor-for/{$deskPerson->id}", ['doctor' => true])->assertStatus(422)->assertJsonValidationErrors('doctor');
        // A doctor who signs in is already on the list; ticking it again changes nothing but their details.
        $this->putJson("/api/staff/doctor-for/{$signingPerson->id}", ['doctor' => true, 'qualification' => 'MD'])->assertOk();
        $this->assertSame(['MD', 'password'], [$signing->fresh()->signature_text, $signing->fresh()->sign_in_with]);
        $this->putJson("/api/staff/doctor-for/{$signingPerson->id}", ['doctor' => false])->assertStatus(422);

        // Taken off the list they can no longer be chosen, and are never removed; put back, they return.
        $this->putJson("/api/staff/doctor-for/{$consultant->id}", ['doctor' => false])->assertOk()->assertJsonPath('doctor', false);
        $this->assertSame(['Dr Signs In'], $this->doctorNames());
        $this->assertNotNull(User::find($id));
        $this->putJson("/api/staff/doctor-for/{$consultant->id}", ['doctor' => true])->assertOk()->assertJsonPath('login_id', $id);
        $this->assertSame(['Dr Sample Consultant', 'Dr Signs In'], $this->doctorNames());
        // Marked as left on the staff list, they come off the doctors' list by the same act.
        $this->putJson("/api/workforce/employees/{$consultant->id}", ['employee_no' => $consultant->employee_no, 'name' => 'Dr Sample Consultant', 'phone' => '9848055555', 'user_id' => $id, 'employment_type' => 'consultant', 'status' => 'inactive'])->assertOk();
        $this->assertSame(['Dr Signs In'], $this->doctorNames());
        $this->putJson("/api/staff/doctor-for/{$consultant->id}", ['doctor' => true])->assertStatus(422);
        // Not for someone who neither manages logins nor keeps the staff records.
        Sanctum::actingAs($desk);
        $this->putJson("/api/staff/doctor-for/{$consultant->id}", ['doctor' => true])->assertForbidden();
    }

    public function test_consultants_added_before_the_rule_are_put_on_the_doctors_list_once_and_likely_doctors_are_only_offered(): void
    {
        $consultant = $this->person('Sample Consultant Surgeon', ['employment_type' => 'consultant', 'phone' => '98480 66666']);
        $named = $this->person('Dr. Sample Duty Doctor', ['employment_type' => 'permanent']);
        $nurse = $this->person('Sample Nurse', ['employment_type' => 'permanent']);
        $left = $this->person('Dr Sample Gone', ['employment_type' => 'consultant', 'status' => 'inactive']);
        $desk = $this->user('frontdesk', ['name' => 'Sample Desk']);
        $working = $this->person('Sample Consultant At The Desk', ['employment_type' => 'consultant', 'user_id' => $desk->id]);

        // With nobody signed in, as when the rule first came in.
        $this->assertSame(1, \App\Support\DoctorList::catchUp());
        $this->assertSame(0, \App\Support\DoctorList::catchUp());

        $login = User::find($consultant->fresh()->user_id);
        $this->assertSame(['doctor', 'none', true, false, $this->here->id], [$login->role->value, $login->sign_in_with, (bool) $login->is_active, (bool) $login->book_online, $login->branch_id]);
        $this->assertNull($named->fresh()->user_id);
        $this->assertNull($nurse->fresh()->user_id);
        $this->assertNull($left->fresh()->user_id);
        $this->assertSame($desk->id, $working->fresh()->user_id);
        $this->assertSame('frontdesk', $desk->fresh()->role->value);

        Sanctum::actingAs($this->admin);
        $this->assertSame(['Sample Consultant Surgeon'], $this->doctorNames());
        // Someone written as "Dr" who is not marked as a consultant is shown on the staff check, for one press.
        $this->assertSame(['Dr. Sample Duty Doctor'], array_column($this->getJson('/api/workforce/staff-check')->json('consultants_not_doctors'), 'name'));
    }
}
