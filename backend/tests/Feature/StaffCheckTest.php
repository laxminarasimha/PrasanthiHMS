<?php

namespace Tests\Feature;

use App\Models\AdminEntry;
use App\Models\Branch;
use App\Models\User;
use App\Workforce\Models\Employee;
use App\Workforce\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** The staff check: logins and staff records that do not yet agree, and joining them. */
class StaffCheckTest extends TestCase
{
    use RefreshDatabase;

    private Branch $here;

    protected function setUp(): void
    {
        parent::setUp();
        $this->here = Branch::create(['name' => 'Sample Hospital']);
    }

    private function user(string $role, array $more = []): User
    {
        return User::factory()->create($more + ['role' => $role, 'branch_id' => $this->here->id]);
    }

    private function person(string $name, array $more = []): Employee
    {
        return Tenant::run($this->here->id, fn () => Employee::create($more + ['employee_no' => 'E'.random_int(10000, 99999), 'name' => $name]));
    }

    public function test_it_lists_what_does_not_agree_and_proposes_only_certain_matches(): void
    {
        $admin = $this->user('admin', ['name' => 'Owner', 'phone' => '9000000000']);
        $desk = $this->user('frontdesk', ['name' => 'Desk One', 'phone' => '98480 11111']);
        $lab = $this->user('lab_tech', ['name' => 'Lab One', 'phone' => '9848022222', 'email' => 'lab.one@example.test']);
        $twin = $this->user('nurse', ['name' => 'Nurse Twin', 'phone' => '9848033333']);
        $this->user('frontdesk', ['name' => 'Gone Away', 'phone' => '9848044444', 'is_active' => false]);
        $screen = $this->user('frontdesk', ['name' => 'Waiting room screen', 'phone' => '9848055555']);

        $deskPerson = $this->person('Desk One Rao', ['phone' => '9848011111']);
        $labPerson = $this->person('Lab One', ['phone' => '9999999999', 'email' => 'LAB.ONE@example.test']);
        // Two people with the nurse's number: no match is proposed.
        $this->person('Nurse A', ['phone' => '9848033333']);
        $this->person('Nurse B', ['phone' => '9848033333']);
        $this->person('Ward Person', ['phone' => '9848066666']);
        $joined = $this->person('Owner Name', ['phone' => '9000000000', 'user_id' => $admin->id]);

        // Not for someone who only keeps staff records, nor for an own-record login.
        Sanctum::actingAs($this->user('hr'));
        $this->getJson('/api/workforce/staff-check')->assertForbidden();

        Sanctum::actingAs($admin);
        $this->postJson("/api/workforce/staff-check/shared-screen/{$screen->id}", ['shared' => true])->assertOk();
        $check = $this->getJson('/api/workforce/staff-check')->assertOk()->json();

        $unjoined = collect($check['unjoined'])->keyBy('id');
        $this->assertEqualsCanonicalizing([$desk->id, $lab->id, $twin->id], $unjoined->keys()->filter(fn ($id) => in_array($id, [$desk->id, $lab->id, $twin->id, $screen->id], true))->values()->all());
        $this->assertSame([$deskPerson->id, 'mobile'], [$unjoined[$desk->id]['match']['id'], $unjoined[$desk->id]['match']['by']]);
        $this->assertSame([$labPerson->id, 'email'], [$unjoined[$lab->id]['match']['id'], $unjoined[$lab->id]['match']['by']]);
        $this->assertNull($unjoined[$twin->id]['match']);
        $this->assertSame(1, $check['switched_off']);
        $this->assertSame([$screen->id], array_column($check['shared_screens'], 'id'));
        // The owner's login and staff record are joined but carry different names.
        $this->assertSame([['employee_id' => $joined->id, 'field' => 'name', 'staff' => 'Owner Name', 'login' => 'Owner']], array_map(fn ($d) => array_intersect_key($d, array_flip(['employee_id', 'field', 'staff', 'login'])), $check['differences']));
        $this->assertContains('Ward Person', array_column($check['without_login'], 'name'));
    }

    public function test_joining_creating_and_settling_change_only_what_was_asked(): void
    {
        $admin = $this->user('admin', ['name' => 'Owner', 'phone' => '9000000000']);
        $desk = $this->user('frontdesk', ['name' => 'Desk One', 'phone' => '9848011111']);
        $lab = $this->user('lab_tech', ['name' => 'Lab One', 'phone' => '9848022222', 'department' => 'front office']);
        $deskPerson = $this->person('Desk One Rao', ['phone' => '9848011111']);
        $front = Tenant::run($this->here->id, fn () => AdminEntry::create(['kind' => 'department', 'name' => 'Front Office', 'is_active' => true]));
        Sanctum::actingAs($admin);

        // Joining leaves both names as they were; the difference is shown and settled by choice.
        $this->postJson('/api/workforce/staff-check/join', ['pairs' => [['user_id' => $desk->id, 'employee_id' => $deskPerson->id]]])->assertOk()->assertJsonPath('joined', 1);
        $this->assertSame(['Desk One', 'Desk One Rao'], [$desk->fresh()->name, Employee::withoutGlobalScopes()->find($deskPerson->id)->name]);
        $this->postJson('/api/workforce/staff-check/join', ['pairs' => [['user_id' => $lab->id, 'employee_id' => $deskPerson->id]]])->assertStatus(422);
        $this->postJson('/api/workforce/staff-check/settle', ['employee_id' => $deskPerson->id, 'field' => 'name', 'keep' => 'staff'])->assertOk();
        $this->assertSame('Desk One Rao', $desk->fresh()->name);
        $this->assertSame([], $this->getJson('/api/workforce/staff-check')->json('differences'));

        // A staff record is made for a login from what the login says, and nothing more.
        $this->postJson('/api/workforce/staff-check/create', ['user_ids' => [$lab->id, $desk->id, $admin->id]])->assertOk()->assertJsonPath('made', 2);
        $made = Employee::withoutGlobalScopes()->where('user_id', $lab->id)->first();
        $this->assertSame(['Lab One', '9848022222', null, null, 'active'], [$made->name, $made->phone, $made->designation, $made->joined_on, $made->status]);
        // The department is taken only where the hospital's own list has one by that name.
        $this->assertSame([$front->id, null], [$made->department_id, Employee::withoutGlobalScopes()->where('user_id', $admin->id)->value('department_id')]);
        $this->assertSame([], $this->getJson('/api/workforce/staff-check')->json('unjoined'));

        // A wrong join can be taken off again.
        $this->postJson("/api/workforce/staff-check/unjoin/{$deskPerson->id}")->assertOk();
        $this->assertNull(Employee::withoutGlobalScopes()->find($deskPerson->id)->user_id);
        // A login joined to a person is not a screen on the wall.
        $this->postJson("/api/workforce/staff-check/shared-screen/{$lab->id}", ['shared' => true])->assertStatus(422);
    }

    public function test_whoever_manages_logins_keeps_the_staff_list_but_not_pay(): void
    {
        $doctor = $this->user('doctor');
        $this->person('Ward Person', ['phone' => '9848066666']);
        Sanctum::actingAs($doctor);

        $this->getJson('/api/workforce/staff-check')->assertOk();
        $this->getJson('/api/workforce/employees')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/workforce/pay/salaries')->assertForbidden();
        $this->getJson('/api/workforce/payroll/runs')->assertForbidden();

        // Taken away from one person, it goes with it.
        $doctor->update(['permissions' => ['canManageStaff' => false]]);
        $this->getJson('/api/workforce/employees')->assertForbidden();
        // A nurse, who does not manage logins, never had it.
        Sanctum::actingAs($this->user('nurse'));
        $this->getJson('/api/workforce/employees')->assertForbidden();
    }
}
