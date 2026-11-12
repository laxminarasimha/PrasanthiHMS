<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_admin_who_can_sign_in_and_disables_sample_logins(): void
    {
        $sample = User::factory()->create(['email' => 'doctor@prasanthi.test', 'role' => 'doctor']);

        $this->artisan('hms:admin', ['email' => 'Owner@Hospital.example', '--disable-samples' => true])
            ->expectsQuestion('Password for owner@hospital.example (at least 10 characters)', 'a-long-secret-1')
            ->expectsQuestion('Type it again', 'a-long-secret-1')
            ->assertSuccessful();

        $this->postJson('/api/login', ['email' => 'owner@hospital.example', 'password' => 'a-long-secret-1'])
            ->assertOk()->assertJsonPath('user.role', 'admin');
        $this->assertFalse($sample->fresh()->is_active);
    }

    public function test_short_or_mismatched_passwords_change_nothing(): void
    {
        $this->artisan('hms:admin', ['email' => 'owner@hospital.example'])
            ->expectsQuestion('Password for owner@hospital.example (at least 10 characters)', 'a-long-secret-1')
            ->expectsQuestion('Type it again', 'something-else')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}
