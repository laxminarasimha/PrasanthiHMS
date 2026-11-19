<?php

namespace App\Workforce\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * For the few steps that should not be possible from a desk left signed in: finalising or reversing a payroll,
 * exporting salary or bank details. The person types their password again, and for a short while after that the
 * step is open to them on that device.
 */
final class Reauth
{
    /** How long a typed password stays good for, in minutes. */
    public const MINUTES = 10;

    public static function confirm(User $user): void
    {
        Cache::put(self::key($user), now()->timestamp, now()->addMinutes(self::MINUTES));
    }

    public static function fresh(User $user): bool
    {
        return Cache::has(self::key($user));
    }

    public static function forget(User $user): void
    {
        Cache::forget(self::key($user));
    }

    /** One per sign-in, so typing the password on one device opens nothing on another. */
    private static function key(User $user): string
    {
        $token = $user->currentAccessToken();

        return 'wf:reauth:'.$user->id.':'.(is_object($token) && isset($token->id) ? $token->id : 'session');
    }
}
