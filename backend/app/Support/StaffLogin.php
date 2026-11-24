<?php

namespace App\Support;

use App\Enums\Role;
use App\Models\User;
use App\Workforce\Models\Employee;
use App\Workforce\Support\Audit;
use Illuminate\Validation\ValidationException;

/**
 * A member of staff and their login are one person. This is the one place that keeps the two in step: which mobile
 * number opens which login, the details that are typed once and copied to the other, a login that closes when the
 * person leaves, and what a PIN may open.
 */
final class StaffLogin
{
    /** What a person and their login have in common: typed on one, kept the same on the other. */
    public const SHARED = ['name', 'phone', 'gender'];

    public const PIN_DIGITS = 6;

    /** Wrong tries before a PIN login is closed for a while. A password login allows SignInGuard::TRIES. */
    public const PIN_TRIES = 5;

    /** The ten digits of an Indian mobile number however it was typed, or null when it is not one. */
    public static function digits(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === 10 ? $digits : null;
    }

    /** What the sign-in page counts wrong tries against: the email or the mobile number, written one way. */
    public static function key(string $typed): string
    {
        return str_contains($typed, '@') ? mb_strtolower(trim($typed)) : (self::digits($typed) ?? trim($typed));
    }

    /** What wrong tries are counted against once the login is known. */
    public static function guardKey(User $user): string
    {
        return 'login#'.$user->id;
    }

    /**
     * The login a person means by what they typed: their email, or their mobile number. A number that sits on more
     * than one login opens none of them, because guessing which was meant would let one person into another's.
     */
    public static function typed(string $typed, string $field = 'login'): ?User
    {
        $typed = trim($typed);
        if (str_contains($typed, '@')) {
            return User::query()->whereRaw('lower(email) = ?', [mb_strtolower($typed)])->first();
        }
        $digits = self::digits($typed);
        if ($digits === null) {
            return null;
        }
        $user = User::query()->where('login_mobile', $digits)->first();
        if ($user) {
            return $user;
        }
        // Logins from before mobile sign-in have not taken up their number yet. The first to use it does so now.
        $same = User::query()->whereNull('login_mobile')->whereNotNull('phone')->get()->filter(fn (User $u) => self::digits($u->phone) === $digits)->values();
        if ($same->count() > 1) {
            throw ValidationException::withMessages([$field => 'This mobile number is on more than one login. Sign in with your email, or ask the hospital to correct the numbers.']);
        }
        $same->first()?->forceFill(['login_mobile' => $digits])->saveQuietly();

        return $same->first();
    }

    /** The other login that already signs in with this number, if any. */
    public static function holder(?string $phone, ?User $except = null): ?User
    {
        $digits = self::digits($phone);

        return $digits === null ? null : User::query()->where('login_mobile', $digits)->when($except?->exists, fn ($q) => $q->where('id', '!=', $except->id))->first();
    }

    /** Refuses a number that already opens someone else's login, in words for the form. */
    public static function refuseTaken(?string $phone, ?User $except = null, string $field = 'phone'): void
    {
        $holder = self::holder($phone, $except);
        if ($holder) {
            throw ValidationException::withMessages([$field => "This mobile number already opens {$holder->name}’s login. Each person signs in with their own number."]);
        }
    }

    /**
     * Called as a login is saved. It takes up its mobile number where no other login has it, and a PIN is kept to
     * what a PIN may open: only a person's own record.
     */
    public static function saving(User $user): void
    {
        if ($user->isDirty('phone') || ! $user->exists || $user->login_mobile === null) {
            $digits = self::digits($user->phone);
            $user->login_mobile = $digits !== null && ! self::holder($user->phone, $user) ? $digits : null;
        }
        // The own-record role is exactly that: nothing can be ticked on top of it. Someone who needs more gets another role.
        if ($user->role === Role::Staff && $user->permissions) {
            $user->permissions = null;
        }
        if ($user->sign_in_with === 'pin' && $user->role !== Role::Staff) {
            throw ValidationException::withMessages(['role' => 'A PIN opens only a person’s own record. To give this person more, give them a password as well.']);
        }
    }

    /** Whether a PIN is too easy to guess: one digit repeated, a run up or down, or the end of the person's own number. */
    public static function weakPin(string $pin, ?User $user = null): bool
    {
        $run = '01234567890123456789';

        return count(array_unique(str_split($pin))) === 1 || str_contains($run, $pin) || str_contains(strrev($run), $pin)
            || ($user?->login_mobile && str_ends_with($user->login_mobile, $pin));
    }

    /** A starting PIN to hand to a person: random, and not one of the easy ones. */
    public static function startingPin(?User $user = null): string
    {
        do {
            $pin = str_pad((string) random_int(0, 10 ** self::PIN_DIGITS - 1), self::PIN_DIGITS, '0', STR_PAD_LEFT);
        } while (self::weakPin($pin, $user));

        return $pin;
    }

    /**
     * After a staff record is saved: what was changed of the name, mobile and gender is changed on its login too, and
     * a person marked as left is signed out everywhere and can no longer sign in. Coming back does not reopen the
     * login by itself. Only what was changed is copied, so two that differed before are never overwritten unasked.
     *
     * @param  list<string>  $changed  the fields changed in this save
     */
    public static function fromEmployee(Employee $employee, array $changed = []): void
    {
        $user = $employee->user_id ? User::find($employee->user_id) : null;
        if (! $user) {
            return;
        }
        $same = array_filter($employee->only(array_intersect(self::SHARED, $changed)), fn ($value) => filled($value));
        // A number that already opens another login is not copied over it: the check screen lists the clash instead.
        if (isset($same['phone']) && self::holder($same['phone'], $user)) {
            unset($same['phone']);
        }
        $user->fill($same);
        $closing = $employee->status === 'inactive' && $user->is_active;
        if ($closing) {
            $user->is_active = false;
        }
        if ($user->isDirty()) {
            $user->save();
        }
        if ($closing) {
            $user->tokens()->delete();
            Audit::record('staff.login_closed', $employee, ['login' => 'open'], ['login' => 'closed'], 'Marked as left', $employee->id);
        }
    }

    /**
     * After a login is saved: what was changed of the name, mobile and gender is changed on its staff record too.
     *
     * @param  list<string>  $changed  the fields changed in this save
     */
    public static function fromUser(User $user, array $changed): void
    {
        $fields = array_intersect(self::SHARED, $changed);
        $employee = $fields ? Employee::query()->where('user_id', $user->id)->first() : null;
        if (! $employee) {
            return;
        }
        $employee->fill(array_filter($user->only($fields), fn ($value) => filled($value)));
        if ($employee->isDirty()) {
            $was = array_intersect_key($employee->getOriginal(), $employee->getDirty());
            $now = $employee->getDirty();
            $employee->save();
            Audit::record('staff.changed', $employee, $was, $now, 'Changed on the person’s login', $employee->id);
        }
    }
}
