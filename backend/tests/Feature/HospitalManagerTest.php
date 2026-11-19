<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Hospital Manager: a member of staff who runs the hospital for the owner. The role starts with everything
 * a doctor may do, can be cut down person by person, and is never a doctor that patients are booked with.
 */
class HospitalManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_manager_starts_with_exactly_what_a_doctor_may_do(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        $manager = User::factory()->create(['role' => 'manager']);

        $this->assertSame($doctor->abilities, $manager->abilities);
        // Everything but closing the day's accounts, which is the admin's until the admin gives it to someone.
        $this->assertSame(['canCloseDay'], array_keys(array_filter($manager->abilities, fn (bool $allowed) => ! $allowed)));

        Sanctum::actingAs($manager);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('role', 'manager')->assertJsonPath('role_label', 'Hospital Manager')
            ->assertJsonPath('can_view_reports', true)->assertJsonPath('can_manage_staff', true)->assertJsonPath('can_consult', true);
        $this->getJson('/api/reports/summary')->assertOk();
        $this->getJson('/api/staff')->assertOk();
    }

    public function test_the_role_is_offered_on_the_staff_screen_and_its_permissions_can_be_cut_down(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $list = $this->getJson('/api/staff')->assertOk()->json();
        $this->assertContains(['value' => 'manager', 'label' => 'Hospital Manager'], $list['roles']);

        $details = ['name' => 'Ravi Manager', 'email' => 'manager@example.test', 'password' => 'a-long-password-1', 'role' => 'manager', 'gender' => 'male', 'phone' => '9000000001'];
        $id = $this->postJson('/api/staff', $details)->assertCreated()->assertJsonPath('role', 'manager')->assertJsonPath('abilities.canRunPharmacy', true)->json('id');

        // Later the owner takes away the visit pad and the staff screen from this one person.
        unset($details['password']);
        $this->putJson("/api/staff/{$id}", $details + ['permissions' => ['canConsult' => false, 'canManageStaff' => false]])
            ->assertOk()->assertJsonPath('abilities.canConsult', false)->assertJsonPath('abilities.canViewReports', true);

        Sanctum::actingAs(User::findOrFail($id));
        $this->getJson('/api/staff')->assertForbidden();
        $this->getJson('/api/reports/summary')->assertOk();
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

        // An admin login stays the admin's to give and to change.
        $this->postJson('/api/staff', ['name' => 'New Admin', 'email' => 'new-admin@example.test', 'password' => 'a-long-password-1', 'role' => 'admin', 'gender' => 'male', 'phone' => '9000000002'])
            ->assertStatus(422)->assertJsonValidationErrors('role');
        $this->putJson("/api/staff/{$admin->id}", ['name' => $admin->name, 'email' => $admin->email, 'role' => 'admin', 'gender' => 'male', 'phone' => '9000000003'])
            ->assertStatus(422)->assertJsonValidationErrors('role');
    }
}
