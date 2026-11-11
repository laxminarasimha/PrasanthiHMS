<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_sign_in_and_fetch_their_profile(): void
    {
        $user = User::factory()->create(['role' => 'doctor']);

        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('user.role', 'doctor')
            ->json('token');

        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('email', $user->email);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'nope'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_deactivated_staff_cannot_sign_in(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(422);
    }

    public function test_api_requires_sign_in(): void
    {
        $this->getJson('/api/patients')->assertUnauthorized();
    }
}
