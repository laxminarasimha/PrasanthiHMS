<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\PatientAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Laravel\Sanctum\Sanctum;
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

    public function test_a_login_is_closed_after_ten_wrong_passwords_and_opened_by_a_reset(): void
    {
        // The per-address limit would stop this test first; here the guesses come as if from many addresses.
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = User::factory()->create(['role' => 'frontdesk']);
        $try = fn (string $password) => $this->postJson('/api/login', ['email' => $user->email, 'password' => $password]);

        foreach (range(1, 10) as $i) {
            $try("guess{$i}")->assertStatus(422)->assertJsonPath('errors.email.0', 'The email or password is incorrect.');
        }
        // Closed now, even to the right password, and the same for the email typed in capitals.
        $this->assertStringContainsString('Too many wrong passwords', $try('password')->assertStatus(422)->json('errors.email.0'));
        $this->assertStringContainsString('Too many wrong passwords', $this->postJson('/api/login', ['email' => strtoupper($user->email), 'password' => 'password'])->json('errors.email.0'));

        // Someone else's login is not affected.
        $other = User::factory()->create();
        $this->postJson('/api/login', ['email' => $other->email, 'password' => 'password'])->assertOk();

        // An admin setting a new password opens it again.
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->putJson("/api/staff/{$user->id}", ['name' => $user->name, 'email' => $user->email, 'role' => 'frontdesk', 'gender' => 'female', 'phone' => '9000000000', 'password' => 'fresh12345'])->assertOk();
        $this->app['auth']->forgetGuards();
        $try('fresh12345')->assertOk();

        // It also opens by itself after a quarter of an hour.
        foreach (range(1, 10) as $i) {
            $try("guess{$i}");
        }
        $try('fresh12345')->assertStatus(422);
        $this->travel(16)->minutes();
        $try('fresh12345')->assertOk();
    }

    public function test_a_sign_in_left_unused_for_a_month_stops_working(): void
    {
        $user = User::factory()->create(['role' => 'doctor']);
        $token = $user->createToken('web');

        $token->accessToken->forceFill(['last_used_at' => now()->subDays(29)])->save();
        $this->withToken($token->plainTextToken)->getJson('/api/me')->assertOk();

        $this->app['auth']->forgetGuards();
        $token->accessToken->forceFill(['last_used_at' => now()->subDays(31)])->save();
        $this->withToken($token->plainTextToken)->getJson('/api/me')->assertUnauthorized();

        // The patient app is opened far less often, so it is given longer.
        $this->app['auth']->forgetGuards();
        $account = PatientAccount::create(['patient_id' => Patient::factory()->create()->id, 'name' => 'App User', 'mobile' => '9876500000', 'password' => 'Health2026']);
        $app = $account->createToken('app');
        $app->accessToken->forceFill(['last_used_at' => now()->subDays(31)])->save();
        $this->withToken($app->plainTextToken)->getJson('/api/app/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $app->accessToken->forceFill(['last_used_at' => now()->subDays(181)])->save();
        $this->withToken($app->plainTextToken)->getJson('/api/app/me')->assertUnauthorized();
    }

    public function test_the_reading_computer_does_not_use_up_the_sign_in_allowance(): void
    {
        // Both come from the hospital's one internet address. Twelve calls by the reader used to leave no sign-ins for staff.
        foreach (range(1, 12) as $i) {
            $this->postJson('/api/reader/next');
        }
        $user = User::factory()->create();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
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
