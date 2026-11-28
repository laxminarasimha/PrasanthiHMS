<?php

namespace App\Support;

use App\Models\Consultation;
use App\Models\LabOrderItem;
use App\Models\LabTest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tests a doctor advised at a visit that our lab has not been asked to do since. A test counts as done once the
 * patient has a lab order for it, not cancelled, on or after the day of the visit, whoever placed the order.
 *
 * Names are compared loosely, the way the front desk ticks advised tests: "CBP" finds "Complete Blood Picture - CBP",
 * "CBC" and "Hemogram" are the same test as "CBP", a short form in brackets or a test's initials count, and a test
 * ordered in a package counts under the package's name as well. A test done under quite another name can still be
 * listed. The tests-not-done report and the front desk's To do both read from here, so they always agree.
 */
final class TestsNotDone
{
    /** The same test under the names doctors commonly write. Kept the same as SAME_TEST in LabOrderForm.tsx. */
    private const SAME_TEST = [
        ['cbc', 'cbp', 'complete blood count', 'complete blood picture', 'hemogram', 'haemogram'],
        ['cue', 'urine routine', 'urine r/e', 'complete urine examination'],
        ['rbs', 'grbs', 'random blood sugar'],
        ['fbs', 'fasting blood sugar'],
    ];

    /**
     * Each visit finished in these dates with advised tests still not ordered, oldest first: the visit, every test
     * advised as the doctor wrote it, and those still missing. With a doctor, only that doctor's visits.
     *
     * @return Collection<int, array{visit: Consultation, advised: Collection<int, string>, missing: Collection<int, string>}>
     */
    public static function between(Carbon $from, Carbon $to, ?int $doctorId = null): Collection
    {
        $visits = Consultation::whereBetween('completed_at', [$from, $to])->whereNotNull('tests_advised')->where('tests_advised', '!=', '')
            ->when($doctorId, fn ($q) => $q->where('doctor_id', $doctorId))
            ->with(['patient:id,uhid,title,name,mobile', 'doctor:id,name', 'appointment:id,token_no'])->orderBy('completed_at')
            ->get(['id', 'appointment_id', 'patient_id', 'doctor_id', 'tests_advised', 'completed_at']);
        if ($visits->isEmpty()) {
            return collect();
        }
        // The same few names come up again and again over a long period, so each is worked out once.
        $known = [];
        $keysOf = function (?string $name) use (&$known): array {
            return $known[(string) $name] ??= self::keys($name);
        };
        // Every test our lab was asked to do for these patients from the first of these visits on, by every name it goes by.
        $ordered = LabOrderItem::join('lab_orders as o', 'o.id', '=', 'lab_order_items.lab_order_id')
            ->whereIn('o.patient_id', $visits->pluck('patient_id')->unique())->where('o.ordered_at', '>=', $from)->where('o.status', '!=', 'cancelled')->toBase()
            ->get(['o.patient_id', 'o.ordered_at', 'lab_order_items.test_name', 'lab_order_items.package_name'])
            ->map(fn ($item) => ['patient_id' => $item->patient_id, 'at' => (string) $item->ordered_at, 'keys' => [...$keysOf($item->test_name), ...$keysOf($item->package_name)]])
            ->groupBy('patient_id');

        return $visits->map(function (Consultation $visit) use ($ordered, $keysOf) {
            $advised = collect(preg_split('/[\r\n,;]+/', (string) $visit->tests_advised))->map(fn ($name) => trim($name))->filter()->unique(fn ($name) => self::plain($name))->values();
            $since = $visit->completed_at->copy()->startOfDay()->format('Y-m-d H:i:s');
            $done = ($ordered[$visit->patient_id] ?? collect())->filter(fn (array $item) => $item['at'] >= $since)->pluck('keys');
            $missing = $advised->filter(function (string $name) use ($done, $keysOf) {
                $keys = $keysOf($name);

                return $keys !== [] && ! $done->contains(fn (array $have) => self::same($keys, $have));
            })->values();

            return $missing->isEmpty() ? null : ['visit' => $visit, 'advised' => $advised, 'missing' => $missing];
        })->filter()->values();
    }

    /**
     * The same visits with only the missing tests our own lab does, each under the lab's own name rather than as the
     * doctor wrote it; a visit with none of those left drops out. A test that matches nothing in the lab's list of
     * tests is taken to be done outside, and is left to the report.
     *
     * @param  Collection<int, array{visit: Consultation, advised: Collection<int, string>, missing: Collection<int, string>}>  $visits
     * @return Collection<int, array{visit: Consultation, advised: Collection<int, string>, missing: Collection<int, string>}>
     */
    public static function ourLab(Collection $visits): Collection
    {
        if ($visits->isEmpty()) {
            return $visits;
        }
        $tests = LabTest::where('is_active', true)->orderBy('name')->pluck('name')->map(fn (string $name) => ['name' => $name, 'keys' => self::keys($name)]);
        $found = [];
        $ours = function (string $name) use ($tests, &$found): ?string {
            if (! array_key_exists($name, $found)) {
                $keys = self::keys($name);
                // A test called exactly that first, then one whose name only contains it, as the desk's ticking does.
                $test = $tests->first(fn (array $t) => array_intersect($keys, $t['keys']) !== []) ?? $tests->first(fn (array $t) => self::same($keys, $t['keys']));
                $found[$name] = $test['name'] ?? null;
            }

            return $found[$name];
        };

        return $visits->map(function (array $row) use ($ours) {
            $missing = $row['missing']->map($ours)->filter()->unique()->values();

            return $missing->isEmpty() ? null : ['missing' => $missing] + $row;
        })->filter()->values();
    }

    /**
     * Every name a test may go by, as letters and digits only: as written; without what is in brackets; what is in
     * brackets ("(TSH)"); the initials of a name of three words or more; and the other common names of the same test.
     * These are the rules of namesOf() in LabOrderForm.tsx, and each side of a dash ("Complete Blood Picture - CBP")
     * counts as a name as well.
     *
     * @return list<string>
     */
    public static function keys(?string $name): array
    {
        $full = trim(mb_strtolower((string) $name));
        if ($full === '') {
            return [];
        }
        preg_match_all('/\(([^)]+)\)/u', $full, $brackets);
        $plain = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\([^)]*\)/u', ' ', $full)));
        $parts = $plain === '' ? [] : (preg_split('/\s+[-–—]\s+/u', $plain) ?: []);
        $names = [$full, $plain, ...$brackets[1], ...$parts];
        foreach ($parts as $part) {
            $words = array_filter(preg_split('/\s+/u', $part) ?: [], fn (string $word) => preg_match('/^[\p{L}\p{N}]/u', $word) === 1);
            $initials = implode('', array_map(fn (string $word) => mb_substr($word, 0, 1), $words));
            if (mb_strlen($initials) >= 3) {
                $names[] = $initials;
            }
        }
        $keys = array_values(array_unique(array_filter(array_map(self::plain(...), $names))));
        foreach (self::sameTests() as $same) {
            if (array_intersect($same, $keys) !== []) {
                $keys = array_values(array_unique([...$keys, ...$same]));
            }
        }

        return $keys;
    }

    /**
     * The common names of each test, as letters and digits only. Worked out once.
     *
     * @return list<list<string>>
     */
    private static function sameTests(): array
    {
        static $groups = null;

        return $groups ??= array_map(fn (array $group) => array_map(self::plain(...), $group), self::SAME_TEST);
    }

    /** Lower-case letters and digits only, so "CBP" and "C.B.P." are written the same. */
    private static function plain(string $name): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '', mb_strtolower($name));
    }

    /**
     * Whether two tests are one by any of their names: the same, or one inside the other where the shorter has
     * three letters or more ("cbp" is inside "completebloodpicturecbp").
     *
     * @param  list<string>  $one
     * @param  list<string>  $other
     */
    private static function same(array $one, array $other): bool
    {
        foreach ($one as $a) {
            foreach ($other as $b) {
                if ($a === $b || (strlen($a) >= 3 && str_contains($b, $a)) || (strlen($b) >= 3 && str_contains($a, $b))) {
                    return true;
                }
            }
        }

        return false;
    }
}
