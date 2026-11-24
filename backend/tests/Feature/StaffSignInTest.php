<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Support\StaffLogin;
use App\Workforce\Models\Employee;
use App\Workforce\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Signing in with a mobile number, PIN logins that open only a person's own record, and a person kept in step with their login. */
class StaffSignInTest extends TestCase
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

    private function wardStaff(string $phone = '9000000001', string $pin = '482915'): User
    {
        return $this->user('staff', ['email' => null, 'phone' => $phone, 'password' => Hash::make($pin), 'sign_in_with' => 'pin']);
    }

    public function test_a_person_signs_in_with_their_mobile_number_however_it_is_typed(): void
    {
        $nurse = $this->user('nurse', ['phone' => '98480 12345']);
        $this->assertSame('9848012345', $nurse->fresh()->login_mobile);

        foreach (['9848012345', '+91 98480 12345', '098480-12345'] as $typed) {
            $this->postJson('/api/login', ['login' => $typed, 'password' => 'password'])->assertOk()->assertJsonPath('user.id', $nurse->id)->assertJsonPath('user.sign_in_with', 'password');
        }
        // The email still works, under the new name of the box and under the old one that installed phone apps send.
        $this->postJson('/api/login', ['login' => strtoupper($nurse->email), 'password' => 'password'])->assertOk();
        $this->postJson('/api/login', ['email' => $nurse->email, 'password' => 'password'])->assertOk();
        $this->postJson('/api/login', ['login' => '9848012345', 'password' => 'wrong'])->assertStatus(422)->assertJsonValidationErrors('login');
        $this->postJson('/api/login', ['password' => 'password'])->assertStatus(422);
    }

    public function test_a_number_on_two_logins_opens_only_the_one_that_holds_it(): void
    {
        $first = $this->user('frontdesk', ['phone' => '9111111111']);
        $second = $this->user('frontdesk', ['phone' => '91111 11111']);
        $this->assertSame(['9111111111', null], [$first->fresh()->login_mobile, $second->fresh()->login_mobile]);
        $this->postJson('/api/login', ['login' => '9111111111', 'password' => 'password'])->assertOk()->assertJsonPath('user.id', $first->id);

        // Logins from before mobile sign-in hold no number yet. One of them takes it up on first use; two with the same number are refused.
        User::query()->update(['login_mobile' => null]);
        $this->assertStringContainsString('more than one login', $this->postJson('/api/login', ['login' => '9111111111', 'password' => 'password'])->assertStatus(422)->json('errors.login.0'));
        $second->update(['phone' => '9222222222']);
        User::query()->whereKey($second->id)->update(['login_mobile' => null]);
        $this->postJson('/api/login', ['login' => '9222222222', 'password' => 'password'])->assertOk()->assertJsonPath('user.id', $second->id);
        $this->assertSame('9222222222', $second->fresh()->login_mobile);
    }

    public function test_a_pin_login_reaches_only_its_own_record_and_closes_after_five_wrong_tries(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $ward = $this->wardStaff();
        $token = $this->postJson('/api/login', ['login' => '9000000001', 'password' => '482915'])->assertOk()->assertJsonPath('user.sign_in_with', 'pin')->assertJsonPath('user.role', 'staff')->json('token');

        $this->withToken($token)->getJson('/api/me')->assertOk();
        $this->withToken($token)->getJson('/api/workforce/me')->assertOk();
        $this->withToken($token)->getJson('/api/workforce/me/today')->assertOk();
        // Nothing of the hospital's: not patients, not bills, not other staff, not a screen added later.
        foreach (['/api/patients', '/api/front-desk/queue', '/api/staff', '/api/workforce/employees', '/api/workforce/dashboard', '/api/reports/owner', '/api/doctors'] as $closed) {
            $this->withToken($token)->getJson($closed)->assertForbidden();
        }

        app('auth')->forgetGuards();
        foreach (range(1, StaffLogin::PIN_TRIES) as $i) {
            $this->postJson('/api/login', ['login' => '9000000001', 'password' => '00000'.$i])->assertStatus(422);
        }
        $this->assertStringContainsString('Too many wrong tries', $this->postJson('/api/login', ['login' => '9000000001', 'password' => '482915'])->assertStatus(422)->json('errors.login.0'));
        $this->assertTrue($ward->fresh()->is_active);
    }

    public function test_a_starting_pin_must_be_replaced_before_anything_else_opens(): void
    {
        $ward = $this->wardStaff();
        $ward->update(['must_change_secret' => true]);
        $token = $this->postJson('/api/login', ['login' => '9000000001', 'password' => '482915'])->assertOk()->assertJsonPath('user.must_change_secret', true)->json('token');
        $this->withToken($token);

        $this->getJson('/api/me')->assertOk()->assertJsonPath('must_change_secret', true);
        $this->getJson('/api/workforce/me')->assertForbidden();

        $change = fn (string $pin) => $this->postJson('/api/me/password', ['current_password' => '482915', 'password' => $pin, 'password_confirmation' => $pin]);
        // Not a password, not an easy PIN, not the end of their own number.
        foreach (['abc12345', '12345', '111111', '123456', '654321', '000001'] as $refused) {
            $change($refused)->assertStatus(422)->assertJsonValidationErrors('password');
        }
        $change('739104')->assertOk()->assertJsonPath('message', 'PIN changed.');
        $this->assertFalse($ward->fresh()->must_change_secret);
        app('auth')->forgetGuards();
        $this->getJson('/api/workforce/me')->assertOk();
    }

    public function test_a_pin_opens_only_an_own_record_login(): void
    {
        $ward = $this->wardStaff();
        Sanctum::actingAs($this->user('admin'));
        $form = fn (array $more) => $more + ['name' => $ward->name, 'email' => null, 'role' => 'staff', 'gender' => 'female', 'phone' => '9000000001'];

        // Made a nurse: a password is asked for in the same step, and the PIN no longer works.
        $this->putJson("/api/staff/{$ward->id}", $form(['role' => 'nurse', 'reason' => 'Moved to the ward desk']))->assertStatus(422)->assertJsonValidationErrors('password');
        $this->putJson("/api/staff/{$ward->id}", $form(['role' => 'nurse', 'reason' => 'Moved to the ward desk', 'password' => '482915']))->assertStatus(422)->assertJsonValidationErrors('password');
        $this->putJson("/api/staff/{$ward->id}", $form(['role' => 'nurse', 'reason' => 'Moved to the ward desk', 'password' => 'nursing123']))->assertOk();
        $this->assertSame(['password', false], [$ward->fresh()->sign_in_with, $ward->fresh()->must_change_secret]);

        // Back to own record only: a starting PIN is asked for, an easy one refused, and the person must choose their own.
        $this->putJson("/api/staff/{$ward->id}", $form(['reason' => 'Back to the wards']))->assertStatus(422)->assertJsonValidationErrors('password');
        $this->putJson("/api/staff/{$ward->id}", $form(['reason' => 'Back to the wards', 'password' => '222222']))->assertStatus(422)->assertJsonValidationErrors('password');
        $this->putJson("/api/staff/{$ward->id}", $form(['reason' => 'Back to the wards', 'password' => '305817', 'permissions' => ['canWorkFrontDesk' => true]]))->assertOk();
        $ward->refresh();
        $this->assertSame(['pin', true, null], [$ward->sign_in_with, $ward->must_change_secret, $ward->permissions]);

        // However it is saved, a PIN never sits on a wider role.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $ward->update(['role' => 'frontdesk']);
    }

    public function test_a_new_login_needs_an_email_or_a_mobile_number_of_its_own(): void
    {
        $this->user('frontdesk', ['phone' => '9333333333']);
        Sanctum::actingAs($this->user('admin'));
        $form = fn (array $more) => $more + ['name' => 'Sample Ward Person', 'role' => 'staff', 'gender' => 'male', 'phone' => '9444444444', 'password' => '381046'];

        $this->postJson('/api/staff', $form(['phone' => '0866 2551'])) ->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->assertStringContainsString('already opens', $this->postJson('/api/staff', $form(['phone' => '9333333333']))->assertStatus(422)->json('errors.phone.0'));
        $id = $this->postJson('/api/staff', $form([]))->assertCreated()->assertJsonPath('email', null)->json('id');
        $made = User::find($id);
        $this->assertSame(['pin', true, '9444444444'], [$made->sign_in_with, $made->must_change_secret, $made->login_mobile]);
        // With an email, a number that another login signs in with is allowed; it opens only the other login.
        $this->postJson('/api/staff', ['name' => 'Sample Desk Two', 'email' => 'desk.two@example.test', 'role' => 'frontdesk', 'gender' => 'female', 'phone' => '9333333333', 'password' => 'frontdesk9'])->assertCreated();
        $this->assertNull(User::where('email', 'desk.two@example.test')->value('login_mobile'));
    }

    public function test_a_person_and_their_login_stay_the_same_and_leaving_closes_the_login(): void
    {
        $login = $this->user('frontdesk', ['name' => 'Desk Person', 'phone' => '9555555555']);
        $person = Tenant::run($this->here->id, fn () => Employee::create(['employee_no' => 'E1', 'name' => 'Desk P.', 'phone' => '9555555555', 'joined_on' => '2025-01-01', 'user_id' => $login->id]));
        $login->createToken('phone');
        Sanctum::actingAs($this->user('admin'));
        $record = fn (array $more) => $more + ['employee_no' => 'E1', 'name' => 'Desk P.', 'phone' => '9555555555', 'user_id' => $login->id];

        // Only what was changed is copied: the two names differed before and are left alone.
        $this->putJson("/api/workforce/employees/{$person->id}", $record(['designation' => 'Receptionist']))->assertOk();
        $this->assertSame('Desk Person', $login->fresh()->name);
        // A change on the staff record reaches the login, and a change on the login reaches the staff record.
        $this->putJson("/api/workforce/employees/{$person->id}", $record(['name' => 'Desk Person Rao', 'phone' => '9666666666']))->assertOk();
        $this->assertSame(['Desk Person Rao', '9666666666', '9666666666'], [$login->fresh()->name, $login->fresh()->phone, $login->fresh()->login_mobile]);
        $this->putJson("/api/staff/{$login->id}", ['name' => 'Desk Person R.', 'email' => $login->email, 'role' => 'frontdesk', 'gender' => 'female', 'phone' => '9666666666'])->assertOk();
        $this->assertSame('Desk Person R.', Employee::withoutGlobalScopes()->find($person->id)->name);

        // Marked as left: signed out everywhere and no longer able to sign in.
        $this->putJson("/api/workforce/employees/{$person->id}", $record(['name' => 'Desk Person R.', 'phone' => '9666666666', 'status' => 'inactive']))->assertOk();
        $this->assertFalse($login->fresh()->is_active);
        $this->assertSame(0, $login->tokens()->count());
        app('auth')->forgetGuards();
        $this->postJson('/api/login', ['login' => '9666666666', 'password' => 'password'])->assertStatus(422);
    }
}
