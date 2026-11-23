<?php

namespace App\Support;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Checks a "Sign in with Google" token: that Google signed it (against Google's published keys), that it was made for
 * this hospital's own Google sign-in, that it has not expired, and that Google has checked the email address. Only then
 * is the person's Google account (its stable id, email and name) handed on.
 */
class GoogleIdToken
{
    public const CERTS = 'https://www.googleapis.com/oauth2/v3/certs';

    /** The hospital's own Google sign-in ids (web, iPhone); none means Google sign-in is switched off. */
    public static function clientIds(): array
    {
        return array_values(array_filter((array) config('services.google.client_ids')));
    }

    public static function enabled(): bool
    {
        return self::clientIds() !== [];
    }

    /**
     * @return array{sub: string, email: string, name: ?string, authoritative: bool}
     *
     * @throws ValidationException when the token cannot be trusted
     */
    public static function verify(string $token, string $field = 'credential'): array
    {
        $refuse = fn (string $why) => throw ValidationException::withMessages([$field => $why]);
        if (! self::enabled()) {
            $refuse('Sign in with Google is not switched on for this hospital yet.');
        }

        try {
            JWT::$leeway = 60;
            $claims = (array) JWT::decode($token, JWK::parseKeySet(self::keys(), 'RS256'));
        } catch (Throwable) {
            // Google turns its keys over now and then: a token signed with a newer key gets one fresh look.
            try {
                Cache::forget('google-sign-in-keys');
                $claims = (array) JWT::decode($token, JWK::parseKeySet(self::keys(), 'RS256'));
            } catch (Throwable) {
                $refuse('Google sign-in did not go through. Please try again.');
            }
        }

        if (! in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) {
            $refuse('Google sign-in did not go through. Please try again.');
        }
        // Made for this hospital's sign-in, not some other app's: a token from elsewhere is not accepted here.
        if (! in_array($claims['aud'] ?? '', self::clientIds(), true)) {
            $refuse('Google sign-in did not go through. Please try again.');
        }
        if (! ($claims['email_verified'] ?? false) || ! is_string($claims['email'] ?? null) || ! is_string($claims['sub'] ?? null)) {
            $refuse('Google has not confirmed the email address of this account.');
        }

        $email = mb_strtolower($claims['email']);

        return [
            'sub' => $claims['sub'],
            'email' => $email,
            'name' => is_string($claims['name'] ?? null) ? $claims['name'] : null,
            // Google speaks for the address only for Gmail and for organisations' own Google accounts. Any other
            // address is not proof enough to be matched to a login by email alone.
            'authoritative' => str_ends_with($email, '@gmail.com') || ! empty($claims['hd']),
        ];
    }

    /** @return array<string, mixed> Google's current signing keys, kept for a few hours. */
    private static function keys(): array
    {
        return Cache::remember('google-sign-in-keys', now()->addHours(6), fn () => Http::timeout(10)->get(self::CERTS)->throw()->json());
    }
}
