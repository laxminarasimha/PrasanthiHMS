<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use RefreshDatabase;

    private array $payload = [
        'name' => 'Sample Nurse',
        'email' => 'nurse@example.test',
        'password' => 'Secret123',
        'role' => 'nurse',
        'gender' => 'female',
        'phone' => '9876543210',
    ];

    public function test_admin_can_add_and_list_staff(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/staff', $this->payload)->assertCreated()->assertJsonPath('role', 'nurse');
        $this->getJson('/api/staff')->assertOk()->assertJsonCount(2, 'data');
        $this->postJson('/api/login', ['email' => 'nurse@example.test', 'password' => 'Secret123'])->assertOk();
    }

    public function test_frontdesk_cannot_manage_staff(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));

        $this->getJson('/api/staff')->assertForbidden();
        $this->postJson('/api/staff', $this->payload)->assertForbidden();
    }

    public function test_weak_password_and_duplicate_email_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/staff', ['password' => 'short', 'email' => $admin->email] + $this->payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password', 'email']);
    }

    public function test_deactivating_staff_blocks_their_login(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $nurse = User::factory()->create(['role' => 'nurse']);

        $this->putJson("/api/staff/{$nurse->id}", [
            'name' => $nurse->name, 'email' => $nurse->email, 'role' => 'nurse',
            'gender' => 'female', 'phone' => '9876543210', 'is_active' => false,
        ])->assertOk()->assertJsonPath('is_active', false);

        $this->postJson('/api/login', ['email' => $nurse->email, 'password' => 'password'])->assertStatus(422);
    }
}
