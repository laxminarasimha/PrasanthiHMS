<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_person_changes_their_own_password_and_other_devices_are_signed_out(): void
    {
        $user = User::factory()->create(['role' => 'frontdesk', 'password' => 'OldPass123']);
        $other = $user->createToken('phone')->plainTextToken;
        $here = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'OldPass123'])->assertOk()->json('token');
        $auth = ['Authorization' => "Bearer {$here}"];

        $new = ['password' => 'NewPass456', 'password_confirmation' => 'NewPass456'];
        $this->postJson('/api/me/password', ['current_password' => 'wrong'] + $new, $auth)->assertStatus(422)->assertJsonValidationErrors('current_password');
        $this->postJson('/api/me/password', ['current_password' => 'OldPass123', 'password' => 'short', 'password_confirmation' => 'short'], $auth)->assertStatus(422);
        $this->postJson('/api/me/password', ['current_password' => 'OldPass123', 'password' => 'NewPass456', 'password_confirmation' => 'Different789'], $auth)->assertStatus(422);
        $this->postJson('/api/me/password', ['current_password' => 'OldPass123', 'password' => 'OldPass123', 'password_confirmation' => 'OldPass123'], $auth)->assertStatus(422);
        $this->postJson('/api/me/password', ['current_password' => 'OldPass123'] + $new, $auth)->assertOk();

        $this->assertSame(1, $user->tokens()->count()); // only this device is left
        $this->assertNull(\Laravel\Sanctum\PersonalAccessToken::findToken($other));
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'OldPass123'])->assertStatus(422);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'NewPass456'])->assertOk();
    }

    public function test_a_person_chooses_their_own_theme_and_text_size(): void
    {
        $user = User::factory()->create(['role' => 'nurse']);
        $other = User::factory()->create(['role' => 'doctor']);
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->getJson('/api/me')->assertOk()->assertJsonPath('appearance.theme', 'prasanthi')->assertJsonPath('appearance.text', 'normal');
        $this->putJson('/api/me/appearance', ['theme' => 'neon', 'text' => 'normal'])->assertStatus(422)->assertJsonValidationErrors('theme');
        $this->putJson('/api/me/appearance', ['theme' => 'night', 'text' => 'huge'])->assertStatus(422)->assertJsonValidationErrors('text');
        $this->putJson('/api/me/appearance', ['theme' => 'night', 'text' => 'large'])->assertOk()->assertJsonPath('appearance.theme', 'night')->assertJsonPath('appearance.text', 'large');
        $this->getJson('/api/me')->assertJsonPath('appearance.theme', 'night');

        // It is theirs alone.
        \Laravel\Sanctum\Sanctum::actingAs($other);
        $this->getJson('/api/me')->assertJsonPath('appearance.theme', 'prasanthi');
    }

    public function test_a_person_hides_menu_tabs_they_do_not_use_without_losing_access(): void
    {
        $user = User::factory()->create(['role' => 'doctor']);
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->getJson('/api/me')->assertOk()->assertJsonPath('hidden_tabs', []);
        $this->putJson('/api/me/tabs', ['hidden' => ['billing']])->assertStatus(422);
        $this->putJson('/api/me/tabs', ['hidden' => ['pharmacy', 'lab', 'lab']])->assertOk()->assertJsonPath('hidden_tabs', ['pharmacy', 'lab'])->assertJsonPath('can_run_pharmacy', true);
        $this->getJson('/api/me')->assertJsonPath('hidden_tabs', ['pharmacy', 'lab']);
        $this->putJson('/api/me/tabs', ['hidden' => []])->assertOk()->assertJsonPath('hidden_tabs', []);
    }
}
