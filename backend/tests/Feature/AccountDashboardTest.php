<?php

namespace Tests\Feature;

use App\Models\Bed;
use App\Models\Patient;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountDashboardTest extends TestCase
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

    public function test_the_home_page_shows_only_what_each_person_works_with(): void
    {
        $doctor = User::factory()->create(['role' => 'doctor']);
        Sanctum::actingAs(User::factory()->create(['role' => 'frontdesk']));
        $service = Service::factory()->create(['price' => 400]);
        $this->postJson('/api/appointments', ['patient_id' => Patient::factory()->create()->id, 'doctor_id' => $doctor->id, 'service_id' => $service->id, 'scheduled_at' => now()->format('Y-m-d H:i'), 'status' => 'arrived', 'paid' => 400, 'payment_mode' => 'cash'])->assertCreated();
        $this->postJson('/api/appointments', ['patient_id' => Patient::factory()->create()->id, 'doctor_id' => User::factory()->create(['role' => 'doctor'])->id, 'service_id' => $service->id, 'scheduled_at' => now()->format('Y-m-d H:i')])->assertCreated();
        Bed::create(['ward' => 'General', 'bed_no' => '1']);

        $desk = $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('appointments.arrived', 1)->assertJsonPath('appointments.booked', 1)->assertJsonPath('unpaid_bills', 1)->assertJsonPath('ipd.free_beds', 1)->json();
        $this->assertArrayNotHasKey('money', $desk);
        $this->assertArrayNotHasKey('pharmacy', $desk);
        $this->assertArrayNotHasKey('lab', $desk);

        // A doctor sees their own queue, and the money because doctors may view reports.
        Sanctum::actingAs($doctor);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('appointments.mine', true)->assertJsonPath('appointments.arrived', 1)->assertJsonPath('appointments.booked', 0)->assertJsonPath('money.collected', 400)->assertJsonStructure(['lab', 'pharmacy', 'ipd']);

        Sanctum::actingAs(User::factory()->create(['role' => 'pharmacist']));
        $pharmacy = $this->getJson('/api/dashboard')->assertOk()->assertJsonStructure(['pharmacy' => ['bills_today', 'expiring', 'expired']])->json();
        $this->assertArrayNotHasKey('appointments', $pharmacy);

        // Day close: what each person took, by mode.
        Sanctum::actingAs($doctor);
        $rows = $this->getJson('/api/reports/list/cashier')->assertOk()->assertJsonCount(1, 'rows')->json();
        $this->assertEquals(400, $rows['rows'][0]['cash']);
        $this->assertEquals(400, $rows['totals']['total']);
        $this->assertSame('Front desk and lab', $rows['rows'][0]['counter']);
    }
}
