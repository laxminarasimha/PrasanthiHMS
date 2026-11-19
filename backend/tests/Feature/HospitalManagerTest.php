<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Bed;
use App\Models\IpdService;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Hospital Manager: a member of staff who runs the hospital for the owner. The role sees everything and
 * manages the day's work, but clinical authorship, approvals, prices, reversals and exports are not part of it
 * unless the admin gives them to the person. It is a preset like any other, changed tick by tick per person.
 */
class HospitalManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_preset_gives_oversight_and_keeps_authorship_approvals_prices_and_exports_out(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);

        $this->assertSame(
            ['canWorkFrontDesk', 'canRecordExpenses', 'canFilePastRecords', 'canViewClinical', 'canViewPharmacy', 'canWorkIpd', 'canViewReports', 'canManageSetup', 'canManageStaff'],
            array_keys(array_filter($manager->abilities)),
        );

        Sanctum::actingAs($manager);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('role_label', 'Hospital Manager')
            ->assertJsonPath('can_view_reports', true)->assertJsonPath('can_view_clinical', true)->assertJsonPath('can_view_pharmacy', true)
            ->assertJsonPath('can_consult', false)->assertJsonPath('can_run_pharmacy', false)->assertJsonPath('can_approve_lab', false)
            ->assertJsonPath('can_set_prices', false)->assertJsonPath('can_export', false)->assertJsonPath('can_delete_bills', false);
    }

    public function test_a_manager_sees_the_pharmacy_and_clinical_records_but_cannot_change_them(): void
    {
        $patient = Patient::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));

        // Looking: reports, the pharmacy's screens, a patient's clinical history.
        $this->getJson('/api/reports/summary')->assertOk();
        $this->getJson('/api/pharmacy/manager')->assertOk();
        $this->getJson('/api/pharmacy/dashboard')->assertOk();
        $this->getJson('/api/pharmacy/shelves')->assertOk();
        $this->getJson("/api/patients/{$patient->id}/case-history")->assertOk();
        $this->getJson("/api/patients/{$patient->id}/health")->assertOk();

        // Doing: none of these.
        $this->postJson('/api/pharmacy/sales', [])->assertForbidden();
        $this->putJson('/api/pharmacy/shelves', ['places' => []])->assertForbidden();
        $this->postJson('/api/pharmacy/medicines', [])->assertForbidden();
        $this->postJson("/api/patients/{$patient->id}/health", [])->assertForbidden();
        $this->postJson('/api/lab/tests', [])->assertForbidden();
    }

    public function test_a_manager_looks_after_the_lists_but_does_not_set_prices(): void
    {
        $service = Service::create(['name' => 'ECG', 'type' => 'other', 'price' => 300]);
        $bed = Bed::create(['ward' => 'General', 'bed_no' => '1', 'daily_charge' => 800]);
        $ipd = IpdService::create(['name' => 'Dressing', 'category' => 'Procedure', 'price' => 150]);
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));

        // Renaming with the price left as it is goes through; a different price does not, nor does a new priced item.
        $this->putJson("/api/services/{$service->id}", ['name' => 'ECG (12 lead)', 'type' => 'other', 'price' => 300])->assertOk()->assertJsonPath('name', 'ECG (12 lead)');
        $this->putJson("/api/services/{$service->id}", ['name' => 'ECG (12 lead)', 'type' => 'other', 'price' => 350])->assertForbidden();
        $this->postJson('/api/services', ['name' => 'X-ray', 'type' => 'other', 'price' => 500])->assertForbidden();
        $this->putJson("/api/ipd/beds/{$bed->id}", ['ward' => 'General', 'bed_no' => '1', 'daily_charge' => 1200])->assertForbidden();
        $this->putJson("/api/ipd/services/{$ipd->id}", ['name' => 'Dressing', 'category' => 'Procedure', 'price' => 200])->assertForbidden();
        $this->assertSame([300.0, 800.0, 150.0], [(float) $service->fresh()->price, (float) $bed->fresh()->daily_charge, (float) $ipd->fresh()->price]);

        // A doctor, who may set prices, changes it as before.
        Sanctum::actingAs(User::factory()->create(['role' => 'doctor']));
        $this->putJson("/api/services/{$service->id}", ['name' => 'ECG (12 lead)', 'type' => 'other', 'price' => 350])->assertOk()->assertJsonPath('price', 350);
    }

    public function test_nobody_gives_more_than_they_hold_and_sensitive_extras_are_the_admins(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $desk = User::factory()->create(['role' => 'frontdesk']);
        $new = fn (string $role, array $extra = []) => $extra + ['name' => 'New Person', 'email' => "new-{$role}@example.test", 'password' => 'a-long-password-1', 'role' => $role, 'gender' => 'male', 'phone' => '9000000001'];
        $same = fn (User $u, array $extra) => $extra + ['name' => $u->name, 'email' => $u->email, 'role' => $u->role->value, 'gender' => 'male', 'phone' => '9000000002', 'reason' => 'Asked for it'];
        Sanctum::actingAs($manager);

        // A manager cannot make a doctor's login, which would carry the visit pad he does not have himself...
        $this->postJson('/api/staff', $new('doctor'))->assertStatus(422)->assertJsonValidationErrors('permissions');
        // ...nor tick it for someone, nor raise himself.
        $this->putJson("/api/staff/{$desk->id}", $same($desk, ['permissions' => ['canConsult' => true]]))->assertStatus(422)->assertJsonValidationErrors('permissions');
        $this->putJson("/api/staff/{$manager->id}", $same($manager, ['permissions' => ['canConsult' => true, 'canSetPrices' => true]]))->assertOk();
        $this->assertFalse($manager->fresh()->allows('canConsult'));
        // He can add a front desk person with only what he holds, and take a permission away from anyone.
        $this->postJson('/api/staff', $new('frontdesk', ['permissions' => ['canChangePrice' => false, 'canExportData' => false]]))->assertCreated()->assertJsonPath('abilities.canWorkFrontDesk', true);
        $this->putJson("/api/staff/{$desk->id}", $same($desk, ['permissions' => ['canChangePrice' => false]]))->assertOk()->assertJsonPath('abilities.canChangePrice', false);

        // A doctor holds "delete bills", but giving it on top of a role is still the admin's to do.
        Sanctum::actingAs(User::factory()->create(['role' => 'doctor']));
        $this->putJson("/api/staff/{$desk->id}", $same($desk, ['permissions' => ['canDeleteBills' => true]]))->assertStatus(422)->assertJsonValidationErrors('permissions');
        $this->postJson('/api/staff', $new('nurse'))->assertCreated();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson("/api/staff/{$desk->id}", $same($desk, ['permissions' => ['canDeleteBills' => true]]))->assertOk()->assertJsonPath('abilities.canDeleteBills', true);
        $this->putJson("/api/staff/{$manager->id}", $same($manager, ['permissions' => ['canConsult' => true]]))->assertOk()->assertJsonPath('abilities.canConsult', true);
    }

    public function test_every_change_of_role_or_permission_needs_a_reason_and_is_kept_with_who_and_when(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Owner Admin']);
        $desk = User::factory()->create(['role' => 'frontdesk', 'name' => 'Desk Person']);
        $details = ['name' => 'Desk Person', 'email' => $desk->email, 'role' => 'manager', 'gender' => 'male', 'phone' => '9000000003', 'permissions' => ['canViewPharmacy' => false]];
        Sanctum::actingAs($admin);

        $this->putJson("/api/staff/{$desk->id}", $details)->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->assertSame('frontdesk', $desk->fresh()->role->value);
        // A change of name or phone alone needs no reason.
        $this->putJson("/api/staff/{$desk->id}", ['role' => 'frontdesk', 'phone' => '9000000004', 'permissions' => []] + $details)->assertOk();

        $this->putJson("/api/staff/{$desk->id}", $details + ['reason' => 'Promoted to run the front office'])->assertOk()->assertJsonPath('role', 'manager');

        $history = $this->getJson("/api/staff/{$desk->id}/permission-history")->assertOk()->json();
        $this->assertCount(1, $history);
        $this->assertSame('Owner Admin', $history[0]['by']);
        $this->assertNotEmpty($history[0]['at']);
        $this->assertStringContainsString('Role: Front Desk → Hospital Manager', $history[0]['what']);
        $this->assertStringContainsString('Given: See clinical records, Reports, Price lists and setup, Staff and permissions', $history[0]['what']);
        $this->assertStringContainsString('Taken away: Change the price while billing, Export to a file', $history[0]['what']);
        $this->assertStringContainsString('Reason: Promoted to run the front office', $history[0]['what']);
        // The change takes hold on the person's very next request: nothing is remembered from before.
        Sanctum::actingAs($desk->fresh());
        $this->getJson('/api/reports/summary')->assertOk();
        $this->getJson('/api/pharmacy/manager')->assertForbidden();
        $this->assertSame(1, AuditLog::where('action', 'staff.permissions')->count());
    }

    public function test_a_manager_is_not_a_doctor_to_book_with_and_cannot_make_an_admin(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $manager = User::factory()->create(['role' => 'manager']);
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($manager);

        $this->assertSame([$doctor->id], array_column($this->getJson('/api/doctors')->assertOk()->json(), 'id'));
        $this->postJson('/api/appointments', ['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $manager->id, 'scheduled_at' => now()->addDay()->format('Y-m-d 11:00')])
            ->assertStatus(422)->assertJsonValidationErrors('doctor_id');

        $this->postJson('/api/staff', ['name' => 'New Admin', 'email' => 'new-admin@example.test', 'password' => 'a-long-password-1', 'role' => 'admin', 'gender' => 'male', 'phone' => '9000000002'])
            ->assertStatus(422)->assertJsonValidationErrors('role');
        $this->putJson("/api/staff/{$admin->id}", ['name' => $admin->name, 'email' => $admin->email, 'role' => 'admin', 'gender' => 'male', 'phone' => '9000000003'])
            ->assertStatus(422)->assertJsonValidationErrors('role');
        $this->assertSame(0, Admission::count());
    }
}
