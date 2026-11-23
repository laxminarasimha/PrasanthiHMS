<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PatientAccount;
use App\Models\User;
use App\Support\GoogleIdToken;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * "Sign in with Google" for staff and for the patient app. Google's keys are stood in for by a key made here, so the
 * tokens are signed exactly as Google signs them, without reaching Google. Made-up people and addresses.
 */
class GoogleSignInTest extends TestCase
{
    use RefreshDatabase;

    private const WEB = 'web-client.apps.googleusercontent.com';

    private string $private = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_ids' => [self::WEB, 'ios-client.apps.googleusercontent.com'], 'services.google.web_client_id' => self::WEB]);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->private);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $b64 = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        Http::fake([GoogleIdToken::CERTS => Http::response(['keys' => [['kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'test-key', 'n' => $b64($rsa['n']), 'e' => $b64($rsa['e'])]]])]);
    }

    private function token(array $claims = []): string
    {
        return JWT::encode($claims + [
            'iss' => 'https://accounts.google.com', 'aud' => self::WEB, 'sub' => '1000001', 'email' => 'asha.sample@gmail.com',
            'email_verified' => true, 'name' => 'Asha Sample', 'iat' => time(), 'exp' => time() + 3600,
        ], $this->private, 'RS256', 'test-key');
    }

    public function test_staff_sign_in_with_google_only_into_an_existing_login(): void
    {
        $user = User::factory()->create(['role' => 'doctor', 'email' => 'Asha.Sample@gmail.com']);
        $this->getJson('/api/brand')->assertOk()->assertJsonPath('google_client_id', self::WEB);

        // First time: the Gmail address is the login's own, so it is joined and signed in.
        $this->postJson('/api/login/google', ['credential' => $this->token()])->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('user.google_email', 'asha.sample@gmail.com');
        $this->assertSame('1000001', $user->fresh()->google_sub);
        $this->assertSame('With Google (asha.sample@gmail.com)', AuditLog::where('action', 'auth.login')->latest('id')->value('summary'));

        // From then on the Google account itself is what counts, even if its address changes.
        $this->postJson('/api/login/google', ['credential' => $this->token(['email' => 'asha.new@gmail.com'])])->assertOk()->assertJsonPath('user.id', $user->id);

        // A Google account nobody has joined does not get in, and Google never makes a new staff login.
        $this->postJson('/api/login/google', ['credential' => $this->token(['sub' => '2000002', 'email' => 'someone.else@gmail.com'])])->assertStatus(422)->assertJsonValidationErrors('credential');
        $this->assertSame(1, User::count());

        // An address Google does not vouch for (not Gmail, no organisation) is not matched by email alone.
        User::factory()->create(['role' => 'nurse', 'email' => 'ravi@sample-clinic.in']);
        $this->postJson('/api/login/google', ['credential' => $this->token(['sub' => '3000003', 'email' => 'ravi@sample-clinic.in'])])->assertStatus(422);

        // A switched-off login stays shut.
        $user->update(['is_active' => false]);
        $this->postJson('/api/login/google', ['credential' => $this->token()])->assertStatus(422);
    }

    public function test_tokens_that_are_not_googles_for_this_hospital_are_refused(): void
    {
        User::factory()->create(['role' => 'doctor', 'email' => 'asha.sample@gmail.com']);
        $refused = fn (string $token) => $this->postJson('/api/login/google', ['credential' => $token])->assertStatus(422)->assertJsonValidationErrors('credential');

        $refused($this->token(['aud' => 'some-other-app.apps.googleusercontent.com']));
        $refused($this->token(['iss' => 'https://evil.example']));
        $refused($this->token(['exp' => time() - 3600, 'iat' => time() - 7200]));
        $refused($this->token(['email_verified' => false]));
        $refused('not-a-token');
        // Signed by some other key: not Google.
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $pem);
        $refused(JWT::encode(['iss' => 'https://accounts.google.com', 'aud' => self::WEB, 'sub' => '1000001', 'email' => 'asha.sample@gmail.com', 'email_verified' => true, 'exp' => time() + 3600], $pem, 'RS256', 'test-key'));

        // With no Google ids set, Google sign-in is off.
        config(['services.google.client_ids' => [], 'services.google.web_client_id' => null]);
        $this->getJson('/api/brand')->assertOk()->assertJsonPath('google_client_id', null);
        $refused($this->token());
    }

    public function test_staff_join_and_remove_their_google_account_while_signed_in(): void
    {
        $user = User::factory()->create(['role' => 'nurse', 'email' => 'ravi@sample-clinic.in']);
        Sanctum::actingAs($user);
        $this->postJson('/api/account/google', ['credential' => $this->token(['sub' => '4000004', 'email' => 'ravi.sample@gmail.com'])])->assertOk()->assertJsonPath('google_email', 'ravi.sample@gmail.com');

        // Now Google signs them in, though the address differs from their login's.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login/google', ['credential' => $this->token(['sub' => '4000004', 'email' => 'ravi.sample@gmail.com'])])->assertOk()->assertJsonPath('user.id', $user->id);

        // One Google account cannot be joined to two logins.
        Sanctum::actingAs(User::factory()->create(['role' => 'nurse']));
        $this->postJson('/api/account/google', ['credential' => $this->token(['sub' => '4000004'])])->assertStatus(422);

        Sanctum::actingAs($user);
        $this->deleteJson('/api/account/google')->assertOk()->assertJsonPath('google_email', null);
        $this->assertNull($user->fresh()->google_sub);
    }

    public function test_patients_open_an_app_account_with_google_and_sign_in_with_it(): void
    {
        // Not joined yet: the app is told it is new, with the name Google gives, and nothing is opened.
        $this->postJson('/api/app/google', ['credential' => $this->token()])->assertOk()->assertJsonPath('new', true)->assertJsonPath('name', 'Asha Sample');
        $this->assertSame(0, PatientAccount::count());

        $details = ['name' => 'Asha Sample', 'mobile' => '9876500011', 'gender' => 'female', 'date_of_birth' => '1990-04-02'];
        $this->postJson('/api/app/google/register', $details + ['credential' => $this->token()])->assertCreated()
            ->assertJsonPath('account.google_email', 'asha.sample@gmail.com')->assertJsonPath('account.verified', false)->assertJsonStructure(['token']);
        // As with any new account, the front desk still verifies the person before older records are joined.
        $this->assertNull(PatientAccount::sole()->verified_at);

        $this->postJson('/api/app/google', ['credential' => $this->token()])->assertOk()->assertJsonPath('account.mobile', '9876500011')->assertJsonStructure(['token']);
        // The same Google account cannot open a second one, nor can a mobile number that already has an account.
        $this->postJson('/api/app/google/register', $details + ['credential' => $this->token()])->assertStatus(422);
        $this->postJson('/api/app/google/register', ['mobile' => '9876500011'] + $details + ['credential' => $this->token(['sub' => '5000005'])])->assertStatus(422)->assertJsonValidationErrors('mobile');

        // An account opened with Google has no password of its own: it is closed by signing in with that Google account.
        Sanctum::actingAs(PatientAccount::sole());
        $this->postJson('/api/app/account/delete', ['credential' => $this->token(['sub' => '5000005'])])->assertStatus(422)->assertJsonValidationErrors('credential');
        $this->postJson('/api/app/account/delete', ['credential' => $this->token()])->assertOk();
        $this->assertSame(0, PatientAccount::count());
    }

    public function test_a_patient_joins_google_to_an_account_opened_with_a_password(): void
    {
        $account = $this->postJson('/api/app/register', ['name' => 'Ravi Sample', 'mobile' => '9876500022', 'gender' => 'male', 'date_of_birth' => '1985-01-01', 'password' => 'sample123', 'password_confirmation' => 'sample123'])->assertCreated()->json('account.id');
        Sanctum::actingAs(PatientAccount::find($account));
        $this->postJson('/api/app/google/connect', ['credential' => $this->token(['sub' => '6000006', 'email' => 'ravi.sample@gmail.com'])])->assertOk()->assertJsonPath('google_email', 'ravi.sample@gmail.com');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/app/google', ['credential' => $this->token(['sub' => '6000006', 'email' => 'ravi.sample@gmail.com'])])->assertOk()->assertJsonPath('account.id', $account);

        Sanctum::actingAs(PatientAccount::find($account));
        $this->deleteJson('/api/app/google/connect')->assertOk()->assertJsonPath('google_email', null);
    }
}
