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
        // The heading their own payslip is printed under may be read, and never changed.
        $this->withToken($token)->getJson('/api/clinic')->assertOk();
        $this->withToken($token)->getJson('/api/billing-preferences')->assertOk();
        $this->withToken($token)->putJson('/api/clinic', ['name' => 'Changed'])->assertForbidden();
        $this->withToken($token)->putJson('/api/billing-preferences', [])->assertForbidden();
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

        // The login's department is the staff record's, from the hospital's own list.
        $ward = Tenant::run($this->here->id, fn () => \App\Models\AdminEntry::create(['kind' => 'department', 'name' => 'Front Office', 'is_active' => true]));
        $this->putJson("/api/workforce/employees/{$person->id}", $record(['name' => 'Desk Person R.', 'phone' => '9666666666', 'department_id' => $ward->id]))->assertOk();
        $this->assertSame('Front Office', $login->fresh()->department);

        // Marked as left: signed out everywhere and no longer able to sign in.
        $this->putJson("/api/workforce/employees/{$person->id}", $record(['name' => 'Desk Person R.', 'phone' => '9666666666', 'department_id' => $ward->id, 'status' => 'inactive']))->assertOk();
        $this->assertFalse($login->fresh()->is_active);
        $this->assertSame(0, $login->tokens()->count());
        app('auth')->forgetGuards();
        $this->postJson('/api/login', ['login' => '9666666666', 'password' => 'password'])->assertStatus(422);
    }

    public function test_a_member_of_staff_is_given_a_login_from_their_own_record(): void
    {
        $ward = Tenant::run($this->here->id, fn () => Employee::create(['employee_no' => 'E7', 'name' => 'Ward Person', 'phone' => '97000 00007', 'gender' => 'male']));
        $nurse = Tenant::run($this->here->id, fn () => Employee::create(['employee_no' => 'E8', 'name' => 'Nurse Person', 'phone' => '9700000008', 'email' => 'nurse.person@example.test']));
        $noPhone = Tenant::run($this->here->id, fn () => Employee::create(['employee_no' => 'E9', 'name' => 'No Phone']));

        // Whoever keeps the staff records gives an own-record login. The starting PIN is made here and shown once.
        Sanctum::actingAs($this->user('hr'));
        $this->postJson("/api/staff/for/{$noPhone->id}")->assertStatus(422)->assertJsonValidationErrors('phone');
        $given = $this->postJson("/api/staff/for/{$ward->id}")->assertCreated()->assertJsonPath('login.role', 'staff')->json();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $given['starting_pin']);
        $login = User::find($given['login']['id']);
        $this->assertSame(['Ward Person', '9700000007', 'pin', true, null], [$login->name, $login->login_mobile, $login->sign_in_with, $login->must_change_secret, $login->email]);
        $this->assertSame($login->id, Employee::withoutGlobalScopes()->find($ward->id)->user_id);
        $this->postJson("/api/staff/for/{$ward->id}")->assertStatus(422);
        // The list says who signs in and as what.
        $this->assertSame(['staff', 'pin', true], array_values(array_intersect_key(collect($this->getJson('/api/workforce/employees')->json('data'))->firstWhere('id', $ward->id)['login'], array_flip(['role', 'sign_in_with', 'waiting_for_own_pin']))));
        // A wider role is not theirs to give.
        $this->postJson("/api/staff/for/{$nurse->id}", ['role' => 'nurse', 'password' => 'nursing123'])->assertForbidden();

        // The person signs in with the starting PIN, and a forgotten PIN is replaced by a new starting one.
        app('auth')->forgetGuards();
        $this->postJson('/api/login', ['login' => '9700000007', 'password' => $given['starting_pin']])->assertOk()->assertJsonPath('user.must_change_secret', true);
        Sanctum::actingAs($this->user('hr'));
        $again = $this->postJson("/api/staff/{$login->id}/starting-pin")->assertOk()->json('starting_pin');
        $this->assertNotSame($given['starting_pin'], $again);
        $this->assertSame(0, $login->tokens()->count());
        app('auth')->forgetGuards();
        $this->postJson('/api/login', ['login' => '9700000007', 'password' => $given['starting_pin']])->assertStatus(422);
        $this->postJson('/api/login', ['login' => '9700000007', 'password' => $again])->assertOk();

        // Those who manage logins give any role within their own limits, with a password, and see the login afterwards.
        Sanctum::actingAs($this->user('admin'));
        $id = $this->postJson("/api/staff/for/{$nurse->id}", ['role' => 'nurse', 'password' => 'nursing123'])->assertCreated()->assertJsonMissingPath('starting_pin')->json('login.id');
        $this->getJson("/api/staff/{$id}")->assertOk()->assertJsonPath('email', 'nurse.person@example.test')->assertJsonPath('role', 'nurse');
        $this->getJson('/api/staff/catalogue')->assertOk()->assertJsonFragment(['value' => 'staff', 'label' => 'Staff (own record only)']);
        // A password login has no starting PIN to reset.
        $this->postJson("/api/staff/{$id}/starting-pin")->assertStatus(422);
        // Neither is open to someone who neither manages logins nor keeps staff records.
        Sanctum::actingAs($this->user('frontdesk'));
        $this->postJson("/api/staff/for/{$noPhone->id}")->assertForbidden();
        $this->postJson("/api/staff/{$login->id}/starting-pin")->assertForbidden();
    }

    public function test_everyone_without_a_login_is_given_one_and_the_unclear_are_left_out(): void
    {
        $taken = $this->user('frontdesk', ['name' => 'Desk Person', 'phone' => '9811111111']);
        $people = Tenant::run($this->here->id, fn () => [
            Employee::create(['employee_no' => 'A1', 'name' => 'Ayah One', 'phone' => '98222 22222']),
            Employee::create(['employee_no' => 'A2', 'name' => 'Guard Two', 'phone' => '9833333333']),
            Employee::create(['employee_no' => 'A3', 'name' => 'Same Number', 'phone' => '9811111111']),
            Employee::create(['employee_no' => 'A4', 'name' => 'No Number']),
            Employee::create(['employee_no' => 'A5', 'name' => 'Gone Away', 'phone' => '9844444444', 'status' => 'inactive']),
            Employee::create(['employee_no' => 'A6', 'name' => 'Has One', 'phone' => '9811111111', 'user_id' => $taken->id]),
        ]);
        Sanctum::actingAs($this->user('frontdesk'));
        $this->postJson('/api/staff/for-all')->assertForbidden();

        Sanctum::actingAs($this->user('hr'));
        $out = $this->postJson('/api/staff/for-all')->assertOk()->json();
        $this->assertSame(['Ayah One', 'Guard Two'], array_column($out['given'], 'name'));
        $this->assertSame(['No Number', 'Same Number'], array_column($out['skipped'], 'name'));
        $this->assertNotSame($out['given'][0]['starting_pin'], $out['given'][1]['starting_pin']);
        $ayah = User::where('login_mobile', '9822222222')->first();
        $this->assertSame(['staff', 'pin', true, $people[0]->id], [$ayah->role->value, $ayah->sign_in_with, $ayah->must_change_secret, Employee::withoutGlobalScopes()->where('user_id', $ayah->id)->value('id')]);
        // Nobody is given a second one.
        $this->assertSame([], $this->postJson('/api/staff/for-all')->assertOk()->json('given'));
        app('auth')->forgetGuards();
        $this->postJson('/api/login', ['login' => '9822222222', 'password' => $out['given'][0]['starting_pin']])->assertOk()->assertJsonPath('user.must_change_secret', true);
    }

    public function test_check_in_can_be_kept_to_the_hospitals_own_internet(): void
    {
        $ward = $this->wardStaff();
        Tenant::run($this->here->id, fn () => Employee::create(['employee_no' => 'W1', 'name' => $ward->name, 'phone' => '9000000001', 'user_id' => $ward->id]));
        Sanctum::actingAs($this->user('admin'));
        $this->getJson('/api/workforce/setup')->assertOk()->assertJsonPath('your_address', '127.0.0.1');
        $this->putJson('/api/workforce/setup/settings', ['attendance' => ['self_check_in' => true, 'self_check_in_networks' => ['not an address']]])->assertStatus(422);
        $this->putJson('/api/workforce/setup/settings', ['attendance' => ['self_check_in' => true, 'self_check_in_networks' => ['203.0.113.7', '2001:db8:1:2::9']]])->assertOk();

        Sanctum::actingAs($ward);
        // From home, or on mobile data: the card says so and the server refuses.
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20']);
        $this->getJson('/api/workforce/me/today')->assertOk()->assertJsonPath('here', false);
        $this->assertStringContainsString('hospital Wi-Fi', $this->postJson('/api/workforce/me/punch')->assertForbidden()->json('message'));
        // On the hospital's connection, including another device on its long-address network.
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2:aaaa:bbbb:cccc:dddd']);
        $this->getJson('/api/workforce/me/today')->assertOk()->assertJsonPath('here', true);
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);
        $this->postJson('/api/workforce/me/punch')->assertOk()->assertJsonPath('state', 'open');
        // With no connection listed, anywhere will do, as before.
        Sanctum::actingAs($this->user('admin'));
        $this->putJson('/api/workforce/setup/settings', ['attendance' => ['self_check_in_networks' => []]])->assertOk();
        Sanctum::actingAs($ward);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20']);
        $this->postJson('/api/workforce/me/punch')->assertOk()->assertJsonPath('state', 'completed');
    }

    public function test_doctors_and_consultants_check_in_and_their_times_are_listed_for_the_owner(): void
    {
        $this->travelTo('2026-03-11 09:10:00');
        $doctor = $this->user('doctor', ['name' => 'Dr Sample', 'phone' => '9811100001']);
        $nurse = $this->user('nurse', ['name' => 'Nurse Sample', 'phone' => '9811100002']);
        [$doc, $visiting, $ward] = Tenant::run($this->here->id, fn () => [
            Employee::create(['employee_no' => 'D1', 'name' => 'Dr Sample', 'phone' => '9811100001', 'user_id' => $doctor->id]),
            Employee::create(['employee_no' => 'D2', 'name' => 'Dr Visiting', 'phone' => '9811100003', 'employment_type' => 'consultant']),
            Employee::create(['employee_no' => 'N1', 'name' => 'Nurse Sample', 'phone' => '9811100002', 'user_id' => $nurse->id]),
        ]);
        Sanctum::actingAs($this->user('admin'));
        $this->putJson('/api/workforce/setup/settings', ['attendance' => ['self_check_in' => true]])->assertOk();
        // The office marks the visiting consultant in; the doctor and the nurse mark themselves.
        $this->postJson('/api/workforce/attendance/check-in', ['employee_id' => $visiting->id])->assertSuccessful();
        // A doctor signs in with their mobile number and checks in and out like anyone else.
        Sanctum::actingAs($doctor);
        $this->getJson('/api/workforce/me/today')->assertOk()->assertJsonPath('employee.name', 'Dr Sample')->assertJsonPath('self_check_in', true);
        $this->postJson('/api/workforce/me/punch')->assertOk()->assertJsonPath('state', 'open');
        Sanctum::actingAs($nurse);
        $this->postJson('/api/workforce/me/punch')->assertOk();
        $this->travelTo('2026-03-11 13:40:00');
        Sanctum::actingAs($doctor);
        $this->postJson('/api/workforce/me/punch')->assertOk()->assertJsonPath('worked_minutes', 270);
        // The doctor does not see everyone's times: that is for those who look after attendance.
        $this->getJson('/api/workforce/attendance/times')->assertForbidden();

        Sanctum::actingAs($this->user('admin'));
        $all = $this->getJson('/api/workforce/attendance/times?from=2026-03-01&to=2026-03-31')->assertOk()->json('rows');
        $this->assertCount(3, $all);
        $doctors = $this->getJson('/api/workforce/attendance/times?from=2026-03-01&to=2026-03-31&who=doctors')->assertOk()->json('rows');
        $this->assertSame([['Dr Visiting', '2026-03-11 09:10', null, 'By the office'], ['Dr Sample', '2026-03-11 09:10', '2026-03-11 13:40', 'By themself']], array_map(fn ($r) => [$r['name'], $r['in'], $r['out'], $r['marked']], $doctors));
        $this->assertSame(['Nurse Sample'], array_column($this->getJson('/api/workforce/attendance/times?from=2026-03-01&to=2026-03-31&who=others')->json('rows'), 'name'));
        // The day list and the month take the same choice.
        $this->assertEqualsCanonicalizing(['Dr Sample', 'Dr Visiting'], array_column($this->getJson('/api/workforce/attendance/day?date=2026-03-11&who=doctors')->assertOk()->json('rows'), 'name'));
        $this->assertCount(2, $this->getJson('/api/workforce/attendance/month?month=2026-03&who=doctors')->assertOk()->json('rows'));
        $this->getJson('/api/workforce/attendance/times?from=2026-01-01&to=2026-06-30')->assertStatus(422);
        $this->get('/api/workforce/attendance/times/export?from=2026-03-01&to=2026-03-31&who=doctors')->assertOk()->assertHeader('content-disposition', 'attachment; filename=in-and-out-2026-03-01-to-2026-03-31.csv');
    }
}
