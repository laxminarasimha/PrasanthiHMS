<?php

namespace App\Workforce\Support;

use App\Models\User;
use App\Workforce\Models\AuditEvent;
use App\Workforce\Models\EmployeePrivate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The module's record of who did what: every change, and every look at something sensitive. A line is only ever
 * added. Each carries a fingerprint of itself and of the line before it, so `verify()` can tell if a line was
 * altered or taken out directly in the database.
 *
 * Bank, tax and identity numbers never go into the record in full: only that they changed, and their last digits.
 */
final class Audit
{
    /** Values that are never written out in full, wherever in the before or after they appear. */
    private const NEVER_IN_FULL = [...EmployeePrivate::SECRET, 'password', 'token'];

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public static function record(string $action, ?Model $subject = null, ?array $before = null, ?array $after = null, ?string $reason = null, ?int $employeeId = null, ?User $actor = null): AuditEvent
    {
        $actor ??= auth()->user() instanceof User ? auth()->user() : null;
        $branch = Tenant::id() ?? $subject?->getAttribute('branch_id') ?? $actor?->branch_id;
        $branch = $branch === null ? null : (int) $branch;

        // One at a time within a branch, so that every line knows the one before it.
        return DB::transaction(function () use ($action, $subject, $before, $after, $reason, $employeeId, $actor, $branch) {
            $previous = AuditEvent::withoutGlobalScopes()->where('branch_id', $branch)->lockForUpdate()->latest('id')->value('hash');
            $line = [
                'branch_id' => $branch,
                'actor_id' => $actor?->id,
                'actor_name' => $actor?->name ?? 'System',
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'employee_id' => $employeeId,
                'reason' => filled($reason) ? mb_substr(trim((string) $reason), 0, 500) : null,
                'before' => $before === null ? null : self::safe($before),
                'after' => $after === null ? null : self::safe($after),
                'ip' => request()?->ip(),
                'created_at' => now()->format('Y-m-d H:i:s'),
                'previous_hash' => $previous,
            ];
            $line['hash'] = self::fingerprint($line);

            return AuditEvent::create($line);
        });
    }

    /** Only what differs between two versions of a record, as [before, after], ready for `record()`. */
    public static function difference(array $was, array $now): array
    {
        $keys = array_keys(array_filter($now + $was, fn ($value, $key) => ($was[$key] ?? null) != ($now[$key] ?? null), ARRAY_FILTER_USE_BOTH));

        return [array_intersect_key($was, array_flip($keys)), array_intersect_key($now, array_flip($keys))];
    }

    /**
     * Reads a branch's record from the start and checks every line against its fingerprint and the line before.
     *
     * @return array{ok: bool, lines: int, broken_at: int|null}
     */
    public static function verify(int $branchId): array
    {
        $previous = null;
        $lines = 0;
        foreach (AuditEvent::withoutGlobalScopes()->where('branch_id', $branchId)->orderBy('id')->cursor() as $event) {
            $lines++;
            $line = $event->only(['branch_id', 'actor_id', 'actor_name', 'action', 'subject_type', 'subject_id', 'employee_id', 'reason', 'before', 'after', 'ip']);
            $line['created_at'] = $event->created_at?->format('Y-m-d H:i:s');
            $line['previous_hash'] = $event->previous_hash;
            if ($event->previous_hash !== $previous || ! hash_equals($event->hash, self::fingerprint($line))) {
                return ['ok' => false, 'lines' => $lines, 'broken_at' => $event->id];
            }
            $previous = $event->hash;
        }

        return ['ok' => true, 'lines' => $lines, 'broken_at' => null];
    }

    /** @param  array<string, mixed>  $line */
    private static function fingerprint(array $line): string
    {
        return hash('sha256', json_encode(self::settled($line), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The same value in one fixed shape, however the database hands it back: named parts in name order, and a
     * whole number written as a whole number. Without this a fingerprint taken before saving would not match one
     * taken after reading, because databases reorder the parts of stored JSON.
     */
    private static function settled(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(self::settled(...), $value);
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function safe(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::safe($value);
            } elseif (in_array($key, self::NEVER_IN_FULL, true)) {
                $values[$key] = EmployeePrivate::masked(is_scalar($value) ? (string) $value : null);
            }
        }

        return $values;
    }
}
