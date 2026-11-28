<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Support\WebPush;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use OpenSSLAsymmetricKey;

/**
 * A made-up phone for the notification tests: its key pair and secret, as a browser would make them, a way to switch
 * notifications on for it, and a way to read what was sent to it as the phone would. Nothing a test sends ever
 * leaves the test run for a real notification service.
 */
trait FakePhone
{
    private const CHROME = 'https://fcm.googleapis.com/fcm/send/sample-device-one';

    private const IPHONE = 'https://web.push.apple.com/sample-device-two';

    /** A made-up phone: its key pair and its secret, as a browser would have made them. */
    private OpenSSLAsymmetricKey $phone;

    private string $phoneKey;

    private string $phoneSecret;

    /** Run as each test starts, like the set-up of the framework's own test traits. */
    protected function setUpFakePhone(): void
    {
        Http::preventStrayRequests();
        $this->phone = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($this->phone)['ec'];
        $this->phoneKey = WebPush::encode("\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT));
        $this->phoneSecret = WebPush::encode(random_bytes(16));
    }

    /** What the phone would read out of a notification sent to it. */
    private function read(string $body): array
    {
        $salt = substr($body, 0, 16);
        $sender = substr($body, 21, 65);
        $shared = openssl_pkey_derive(openssl_pkey_get_public(WebPush::publicPem($sender)), $this->phone);
        $material = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".WebPush::decode($this->phoneKey).$sender, WebPush::decode($this->phoneSecret));
        $key = hash_hkdf('sha256', $material, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $material, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt(substr($body, 86, -16), 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, substr($body, -16));
        $this->assertNotFalse($plain, 'The phone could not open the notification.');
        $this->assertSame("\x02", substr($plain, -1));

        return json_decode(substr($plain, 0, -1), true);
    }

    /** Signs in as the person and switches notifications on for this device. They are left signed in. */
    private function switchOn(User $user, string $endpoint = self::CHROME): void
    {
        Sanctum::actingAs($user);
        $this->postJson('/api/me/push', ['endpoint' => $endpoint, 'keys' => ['p256dh' => $this->phoneKey, 'auth' => $this->phoneSecret]])->assertCreated();
    }

    /**
     * Everything one device has been sent so far, in order, read as the phone would read it.
     *
     * @return list<array<string, mixed>>
     */
    private function sentTo(string $endpoint = self::CHROME): array
    {
        return collect(Http::recorded())->map(fn (array $pair) => $pair[0])->filter(fn (SentRequest $sent) => $sent->url() === $endpoint)
            ->map(fn (SentRequest $sent) => $this->read($sent->body()))->values()->all();
    }
}
