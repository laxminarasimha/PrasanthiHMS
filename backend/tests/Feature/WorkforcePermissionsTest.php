<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Workforce\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Who may do what in the workforce module: what each role starts with, that an untick for one person always wins,
 * that nobody raises themself, and that the sensitive permissions are the admin's to give.
 */
class WorkforcePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $more = []): User
    {
        static $branch;
        $branch = Branch::first() ?? Branch::create(['name' => 'Sample Hospital']);

        return User::factory()->create($more + ['role' => $role, 'branch_id' => $branch->id]);
    }

    private function details(User $user, array $extra = []): array
    {
        return $extra + ['name' => $user->name, 'email' => $user->email, 'role' => $user->role->value, 'gender' => 'female', 'phone' => '9000000000', 'reason' => 'Change of duties'];
    }

    public function test_every_permission_is_named_what_action_and_every_preset_names_real_permissions(): void
    {
        foreach (array_keys(Permissions::CATALOGUE) as $key) {
            $this->assertMatchesRegularExpression('/^wf:[a-z_]+:(view|create|edit|approve|finalise|reverse|export|admin)$/', $key);
        }
        foreach (Role::cases() as $role) {
            foreach (Permissions::preset($role) as $key => $reach) {
                $this->assertArrayHasKey($key, Permissions::CATALOGUE, "{$role->value} is given {$key}, which does not exist");
                $this->assertContains($reach, [Permissions::ALL, Permissions::DEPARTMENT, Permissions::SELF]);
            }
        }
    }

    public function test_what_each_role_starts_with(): void
    {
        $can = fn (string $role) => array_keys(array_filter(array_map(fn () => true, Permissions::preset(Role::from($role)))));

        // The owner's login has everything; ordinary staff have only their own records.
        $this->assertSame(array_keys(Permissions::CATALOGUE), $can('admin'));
        foreach (['doctor', 'nurse', 'pharmacist', 'lab_tech', 'frontdesk'] as $role) {
            $this->assertSame(['wf:self:view', 'wf:self:create'], $can($role));
        }

        // The manager sees and runs the day, without pay details, money decisions, exports or the record of changes.
        $manager = $this->user('manager');
        foreach (['wf:staff:view', 'wf:attendance:approve', 'wf:leave:approve', 'wf:payroll:view', 'wf:expenses:approve', 'wf:reports:view', 'wf:settings:admin'] as $yes) {
            $this->assertTrue($manager->allows($yes), $yes);
        }
        foreach (['wf:salary:view', 'wf:staff_private:view', 'wf:payroll:create', 'wf:payroll:finalise', 'wf:payroll:reverse', 'wf:advances:approve', 'wf:expenses:finalise', 'wf:expenses:reverse', 'wf:staff:export', 'wf:audit:view'] as $no) {
            $this->assertFalse($manager->allows($no), $no);
        }
        // And still no clinical authorship anywhere in the system.
        foreach (['canConsult', 'canApproveLab', 'canRunLab', 'canApproveIpd'] as $clinical) {
            $this->assertFalse($manager->allows($clinical), $clinical);
        }

        // HR prepares a payroll but does not approve or finalise it; the accountant approves but does not prepare it;
        // only the owner finalises or reverses. No one role can take a payroll from start to finish.
        [$hr, $accountant] = [$this->user('hr'), $this->user('accountant')];
        $this->assertSame([true, true, false, false, false], array_map(fn ($p) => $hr->allows($p), ['wf:payroll:create', 'wf:payroll:edit', 'wf:payroll:approve', 'wf:payroll:finalise', 'wf:payroll:reverse']));
        $this->assertSame([false, false, true, false, false], array_map(fn ($p) => $accountant->allows($p), ['wf:payroll:create', 'wf:payroll:edit', 'wf:payroll:approve', 'wf:payroll:finalise', 'wf:payroll:reverse']));
        $this->assertTrue($hr->allows('wf:staff_private:edit'));
        $this->assertFalse($accountant->allows('wf:staff_private:view'));
        // Neither desk job opens anything clinical, the pharmacy or the lab.
        foreach (['canConsult', 'canRunLab', 'canRunPharmacy', 'canViewPharmacy', 'canViewClinical', 'canWorkFrontDesk'] as $other) {
            $this->assertFalse($hr->allows($other), "hr {$other}");
            $this->assertFalse($accountant->allows($other), "accountant {$other}");
        }
        $this->assertTrue($accountant->allows('canViewReports'));
    }

    public function test_an_untick_for_one_person_beats_the_role_and_a_tick_adds_to_it(): void
    {
        $admin = $this->user('admin');
        $manager = $this->user('manager');
        Sanctum::actingAs($admin);

        $this->putJson("/api/staff/{$manager->id}", $this->details($manager, ['permissions' => ['wf:leave:approve' => false, 'wf:salary:view' => true]]))
            ->assertOk()->assertJsonPath('abilities.wf:leave:approve', false)->assertJsonPath('abilities.wf:salary:view', true);
        $manager->refresh();
        $this->assertSame(['wf:leave:approve' => false, 'wf:salary:view' => true], $manager->permissions);
        $this->assertNull(Permissions::reach($manager, 'wf:leave:approve'));
        $this->assertSame(Permissions::ALL, Permissions::reach($manager, 'wf:salary:view'));
        // Kept with who, when and why, like every other permission change.
        $this->assertStringContainsString('Given: See salaries', AuditLog::where('action', 'staff.permissions')->latest('id')->value('summary'));
        $this->assertStringContainsString('Taken away: Approve or refuse leave', AuditLog::where('action', 'staff.permissions')->latest('id')->value('summary'));

        // It holds on the person's very next request.
        Sanctum::actingAs($manager);
        $access = $this->getJson('/api/workforce/access')->assertOk()->json('permissions');
        $this->assertArrayNotHasKey('wf:leave:approve', $access);
        $this->assertSame('all', $access['wf:salary:view']);
    }

    public function test_nobody_raises_themself_and_sensitive_permissions_are_the_admins_to_give(): void
    {
        $hr = $this->user('hr');
        $manager = $this->user('manager');
        $desk = $this->user('frontdesk');

        // HR cannot tick payroll approval for themself: their own permissions are not theirs to change.
        Sanctum::actingAs($manager);
        $this->putJson("/api/staff/{$manager->id}", $this->details($manager, ['permissions' => ['wf:payroll:finalise' => true]]))->assertOk();
        $this->assertFalse($manager->fresh()->allows('wf:payroll:finalise'));
        // A manager cannot hand out what they do not hold, nor make an HR login that would hold it.
        $this->putJson("/api/staff/{$desk->id}", $this->details($desk, ['permissions' => ['wf:salary:view' => true]]))->assertStatus(422)->assertJsonValidationErrors('permissions');
        $this->putJson("/api/staff/{$desk->id}", $this->details($desk, ['role' => 'hr']))->assertStatus(422)->assertJsonValidationErrors('permissions');
        // What they do hold and is not sensitive, they can give.
        $this->putJson("/api/staff/{$desk->id}", $this->details($desk, ['permissions' => ['wf:attendance:view' => true]]))->assertOk()->assertJsonPath('abilities.wf:attendance:view', true);

        // The admin can give any of it.
        Sanctum::actingAs($this->user('admin'));
        $this->putJson("/api/staff/{$hr->id}", $this->details($hr, ['permissions' => ['wf:payroll:approve' => true]]))->assertOk()->assertJsonPath('abilities.wf:payroll:approve', true);
    }

    public function test_the_staff_screen_and_the_matrix_list_the_modules_permissions(): void
    {
        Sanctum::actingAs($this->user('admin'));
        $abilities = collect($this->getJson('/api/staff')->assertOk()->json('abilities'))->keyBy('key');
        $this->assertSame('Workforce · Payroll', $abilities['wf:payroll:finalise']['group']);
        $this->assertTrue($abilities['wf:payroll:finalise']['sensitive']);
        $this->assertSame(['admin'], $abilities['wf:payroll:finalise']['roles']);
        $this->assertSame('Hospital system', $abilities['canConsult']['group']);
        $this->assertContains(['value' => 'hr', 'label' => 'HR and Payroll'], $this->getJson('/api/staff')->json('roles'));

        $matrix = collect($this->getJson('/api/workforce/permission-matrix')->assertOk()->json('permissions'))->keyBy('key');
        $this->assertSame('department', $matrix['wf:leave:approve']['reach']['dept_manager']);
        $this->assertSame('all', $matrix['wf:leave:approve']['reach']['manager']);
        $this->assertNull($matrix['wf:leave:approve']['reach']['frontdesk']);

        Sanctum::actingAs($this->user('frontdesk'));
        $this->getJson('/api/workforce/permission-matrix')->assertForbidden();
    }
}
