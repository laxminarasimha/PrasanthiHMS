<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Stops password guessing against one login. The per-address limit on the sign-in page does not help when the
 * guesses come from many addresses, so wrong passwords are also counted against the login itself:
 * after ten in a quarter of an hour it is closed to everyone for the rest of that time (five for a login
 * opened with a PIN, which has far fewer possibilities).
 */
class SignInGuard
{
    public const TRIES = 10;

    public const SECONDS = 900;

    public static function key(string $kind, string $login): string
    {
        return 'signin:'.$kind.':'.sha1(mb_strtolower(trim($login)));
    }

    /** Refuses at once while the login is closed, before the password is even looked at. */
    public static function refuseIfClosed(string $kind, string $login, string $field, int $tries = self::TRIES): void
    {
        $key = self::key($kind, $login);
        if (RateLimiter::tooManyAttempts($key, $tries)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));
            throw ValidationException::withMessages([$field => "Too many wrong tries for this login. Try again in {$minutes} minute".($minutes === 1 ? '' : 's').', or ask the hospital to reset it.']);
        }
    }

    public static function wrong(string $kind, string $login): void
    {
        RateLimiter::hit(self::key($kind, $login), self::SECONDS);
    }

    public static function clear(string $kind, string $login): void
    {
        RateLimiter::clear(self::key($kind, $login));
    }
}
