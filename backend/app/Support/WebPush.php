<?php

namespace App\Support;

use App\Models\PushSubscription;
use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Sends a notification to a phone or computer through the notification service of its own browser
 * (Google's for Chrome and Android, Apple's for iPhone, Mozilla's for Firefox, Microsoft's for Edge).
 *
 * The words of a notification are locked here so that only the device they are meant for can read them:
 * the services in between carry them without being able to open them (RFC 8291). Each request is signed
 * with this hospital's own key pair, which is how the services know it is this site sending (RFC 8292).
 */
final class WebPush
{
    /** The only places a notification is ever sent to. An address anywhere else is refused when a device is added. */
    private const SERVICES = ['fcm.googleapis.com', 'push.apple.com', 'push.services.mozilla.com', 'notify.windows.com'];

    /** What comes before the 65 bytes of a P-256 public key when it is written out in the standard way. */
    private const PUBLIC_KEY_START = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    private const SETTING = 'push_keys';

    /** The most a notification service is sure to carry. */
    private const LONGEST = 3000;

    public static function allowed(string $endpoint): bool
    {
        $parts = parse_url($endpoint);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || (int) ($parts['port'] ?? 443) !== 443) {
            return false;
        }
        $host = strtolower($parts['host'] ?? '');
        foreach (self::SERVICES as $service) {
            if ($host === $service || str_ends_with($host, '.'.$service)) {
                return true;
            }
        }

        return false;
    }

    /** This site's public key, which a device is given when it asks to be notified. */
    public static function publicKey(): string
    {
        return self::keys()['public'];
    }

    /**
     * This site's key pair, made the first time it is needed and kept from then on. The private half is kept
     * locked with the site's own key. If it can no longer be opened, every device's notifications were made for
     * a key that is gone, so they are cleared and each device asks again the next time the app is opened on it.
     *
     * @return array{public: string, pem: string}
     */
    public static function keys(): array
    {
        $saved = Setting::find(self::SETTING)?->value;
        if (is_array($saved) && isset($saved['public'], $saved['private'])) {
            try {
                return ['public' => $saved['public'], 'pem' => Crypt::decryptString($saved['private'])];
            } catch (DecryptException) {
                Setting::whereKey(self::SETTING)->delete();
                PushSubscription::query()->delete();
            }
        }

        $key = self::newKey();
        if (! openssl_pkey_export($key, $pem)) {
            throw new RuntimeException('This server could not make the key that notifications are signed with.');
        }
        $made = ['public' => self::encode(self::point($key)), 'private' => Crypt::encryptString($pem)];
        try {
            Setting::create(['key' => self::SETTING, 'value' => $made]);
        } catch (QueryException) {
            // Someone else made it in the same moment: theirs is the one that was kept.
            $made = Setting::findOrFail(self::SETTING)->value;
            $pem = Crypt::decryptString($made['private']);
        }

        return ['public' => $made['public'], 'pem' => $pem];
    }

    /**
     * Sends one notification. Answers with what the service said (201 is "taken"; 404 and 410 mean the device
     * no longer wants them), or 0 when the service could not be reached.
     *
     * @param  array<string, mixed>  $message
     */
    public static function send(string $endpoint, string $p256dh, string $auth, array $message, int $keepForSeconds = 14400): int
    {
        if (! self::allowed($endpoint)) {
            return 0;
        }
        $body = self::encrypt((string) json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $p256dh, $auth);

        try {
            return Http::withHeaders([
                'Authorization' => self::authorization($endpoint),
                'TTL' => (string) $keepForSeconds,
                'Urgency' => 'high',
                'Content-Encoding' => 'aes128gcm',
            ])->withBody($body, 'application/octet-stream')->connectTimeout(3)->timeout(6)->post($endpoint)->status();
        } catch (ConnectionException) {
            return 0;
        }
    }

    /**
     * The words of a notification, locked for one device (RFC 8291, "aes128gcm").
     * The last two arguments are only for checking this against the worked example in the standard.
     */
    public static function encrypt(string $payload, string $p256dh, string $auth, ?OpenSSLAsymmetricKey $sender = null, ?string $salt = null): string
    {
        $device = self::decode($p256dh);
        $secret = self::decode($auth);
        if (strlen($device) !== 65 || $device[0] !== "\x04" || strlen($secret) !== 16) {
            throw new InvalidArgumentException('These are not the keys of a device that can be notified.');
        }
        if (strlen($payload) > self::LONGEST) {
            throw new InvalidArgumentException('That is too long for a notification.');
        }

        $sender ??= self::newKey();
        $salt ??= random_bytes(16);
        $ours = self::point($sender);
        $theirs = openssl_pkey_get_public(self::publicPem($device));
        $shared = $theirs ? openssl_pkey_derive($theirs, $sender) : false;
        if ($shared === false) {
            throw new InvalidArgumentException('These are not the keys of a device that can be notified.');
        }

        $material = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$device.$ours, $secret);
        $key = hash_hkdf('sha256', $material, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $material, 12, "Content-Encoding: nonce\0", $salt);
        // One record holds it all; the 0x02 after the words marks it as the last.
        $locked = openssl_encrypt($payload."\x02", 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($locked === false) {
            throw new RuntimeException('This server could not lock the notification.');
        }

        return $salt.pack('N', 4096).chr(strlen($ours)).$ours.$locked.$tag;
    }

    /** The signed note that tells a notification service this site is the one sending (RFC 8292). Good for twelve hours. */
    public static function authorization(string $endpoint, ?int $now = null): string
    {
        $keys = self::keys();
        $claims = [
            'aud' => parse_url($endpoint, PHP_URL_SCHEME).'://'.parse_url($endpoint, PHP_URL_HOST),
            'exp' => ($now ?? time()) + 12 * 3600,
            'sub' => self::contact(),
        ];
        $unsigned = self::encode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256'])).'.'.self::encode((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
        if (! openssl_sign($unsigned, $der, $keys['pem'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('This server could not sign the notification.');
        }

        return 'vapid t='.$unsigned.'.'.self::encode(self::plainSignature($der)).', k='.$keys['public'];
    }

    /** A P-256 public key as 65 plain bytes, written the standard way so it can be used. */
    public static function publicPem(string $point): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::PUBLIC_KEY_START).$point), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'), true);
    }

    /** Whom a notification service may write to about this site's notifications: the site itself, never a person. */
    private static function contact(): string
    {
        $url = (string) config('app.url');
        $host = (string) parse_url($url, PHP_URL_HOST);

        return str_starts_with($url, 'https://') && str_contains($host, '.') ? $url : 'https://prasanthihospitals.com';
    }

    private static function newKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (! $key) {
            throw new RuntimeException('This server could not make a key for notifications.');
        }

        return $key;
    }

    /** The public half of a key as the 65 plain bytes that devices and services expect. */
    private static function point(OpenSSLAsymmetricKey $key): string
    {
        $ec = openssl_pkey_get_details($key)['ec'] ?? [];

        return "\x04".str_pad($ec['x'] ?? '', 32, "\0", STR_PAD_LEFT).str_pad($ec['y'] ?? '', 32, "\0", STR_PAD_LEFT);
    }

    /** A signature comes out wrapped (two numbers, each with its own length); the services want the two numbers side by side. */
    private static function plainSignature(string $der): string
    {
        $at = 2;
        $half = function () use ($der, &$at): string {
            $length = ord($der[$at + 1]);
            $number = substr($der, $at + 2, $length);
            $at += 2 + $length;

            return str_pad(ltrim($number, "\0"), 32, "\0", STR_PAD_LEFT);
        };

        return $half().$half();
    }
}
