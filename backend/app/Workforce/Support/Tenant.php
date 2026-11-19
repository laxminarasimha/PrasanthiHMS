<?php

namespace App\Workforce\Support;

use App\Models\Branch;
use App\Models\User;
use Closure;

/**
 * Which hospital branch the work in hand belongs to. Every workforce record carries its branch, and every read of
 * one is narrowed to the branch of whoever is signed in, here on the server, whatever the screen asked for.
 *
 * A job with nobody signed in (bringing data in from the old system, a test) names its branch with `Tenant::run()`.
 */
final class Tenant
{
    /** Where the application keeps the answer to "is there only one branch?". */
    private const ONLY = 'workforce.only_branch';

    /** A branch named for a job that runs with nobody signed in. */
    private static ?int $forced = null;

    /** The branch to work in, or null when there is no one signed in and none was named. */
    public static function id(): ?int
    {
        if (self::$forced !== null) {
            return self::$forced;
        }
        $user = auth()->user();
        if (! $user instanceof User) {
            return null;
        }

        return $user->branch_id !== null ? (int) $user->branch_id : self::onlyBranch();
    }

    /**
     * A hospital with a single branch may have logins that were never given one (the first admin, made from the
     * command line). They belong to that branch: there is no other they could mean. Where there are two or more
     * branches, a login without one still sees nothing, because guessing would cross the wall between hospitals.
     * The answer is remembered on the application, so it lasts one request and no longer.
     */
    private static function onlyBranch(): ?int
    {
        $app = app();
        if (! $app->bound(self::ONLY)) {
            $app->instance(self::ONLY, Branch::query()->limit(2)->pluck('id')->all());
        }
        $ids = $app->make(self::ONLY);

        return count($ids) === 1 ? (int) $ids[0] : null;
    }

    /** Forgets what was remembered, for a long-running worker or a test that adds or removes a branch. */
    public static function forget(): void
    {
        app()->forgetInstance(self::ONLY);
    }

    /**
     * Whether reads must be narrowed. Someone signed in with no branch of their own sees nothing rather than
     * everything; only a job with nobody signed in and no branch named reads across branches.
     */
    public static function mustNarrow(): bool
    {
        return self::$forced !== null || auth()->user() instanceof User;
    }

    /**
     * Runs a piece of work as one branch, then puts things back.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function run(int $branchId, Closure $work): mixed
    {
        $before = self::$forced;
        self::$forced = $branchId;
        try {
            return $work();
        } finally {
            self::$forced = $before;
        }
    }
}
