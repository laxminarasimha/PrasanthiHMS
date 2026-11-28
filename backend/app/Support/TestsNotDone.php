<?php

namespace App\Support;

use App\Models\Consultation;
use App\Models\LabOrderItem;
use App\Models\LabPackage;
use App\Models\LabTest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tests a doctor advised at a visit that our lab has not been asked to do since. A test counts as done once the
 * patient has a lab order for it, not cancelled, on or after the day of the visit, whoever placed the order.
 *
 * Names are compared loosely, by letters and digits only. Two whole names are one test when they are the same, or
 * when one is inside the other from the start of a word and the shorter has three letters or more: "Lipid profile"
 * finds "Fasting Lipid Profile - FLP", and "CBP" finds "Complete Blood Picture - CBP". The other names a test goes by
 * count only when they are exactly the same: a short form in brackets ("AST" for "SGOT (AST)", "TSH" for "Thyroid
 * Profile (T3, T4, TSH)"), each side of a dash, the initials of a long name, and the common names of the same test
 * ("CBC" and "Hemogram" are "CBP"). Nothing is looked for in the middle of a word, so "ASO" is not found in
 * "Ultrasound", nor "Echo" in "Catecholamines". A test ordered in a package counts under the package's name as well.
 * A test done under quite another name can still be listed.
 *
 * The tests-not-done report and the front desk's To do both read from here, so they always agree. The Tests button
 * on the desk's day list ticks advised tests by rules of its own (LabOrderForm.tsx), close to these but not the same.
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
            ->with(['patient:id,uhid,title,name,mobile', 'doctor:id,name', 'appointment:id,token_no,scheduled_at'])->orderBy('completed_at')
            ->get(['id', 'appointment_id', 'patient_id', 'doctor_id', 'tests_advised', 'completed_at']);
        if ($visits->isEmpty()) {
            return collect();
        }
        // Orders count from the day of the visit itself. The desk can order the tests from the day list as soon as the
        // doctor writes them, and the doctor may finish the pad a day later.
        $since = $visits->mapWithKeys(function (Consultation $visit) {
            $booked = $visit->appointment?->scheduled_at;
            $day = $booked && $booked->lt($visit->completed_at) ? $booked : $visit->completed_at;

            return [$visit->id => $day->copy()->startOfDay()->format('Y-m-d H:i:s')];
        });
        // The same few names come up again and again over a long period, so each is worked out once.
        $known = [];
        $namesOf = function (?string $name) use (&$known): array {
            return $known[(string) $name] ??= self::names($name);
        };
        // Every test our lab was asked to do for these patients from the day of the first of these visits on. A test
        // ordered in a package goes by the package's name too; each name is kept whole, on its own.
        $ordered = LabOrderItem::join('lab_orders as o', 'o.id', '=', 'lab_order_items.lab_order_id')
            ->whereIn('o.patient_id', $visits->pluck('patient_id')->unique())->where('o.ordered_at', '>=', $since->min())->where('o.status', '!=', 'cancelled')->toBase()
            ->get(['o.patient_id', 'o.ordered_at', 'lab_order_items.test_name', 'lab_order_items.package_name'])
            ->map(fn ($item) => [
                'patient_id' => $item->patient_id, 'at' => (string) $item->ordered_at,
                'names' => array_values(array_filter([$namesOf($item->test_name), $namesOf($item->package_name)], fn (array $names) => $names['whole'] !== '')),
            ])
            ->groupBy('patient_id');

        return $visits->map(function (Consultation $visit) use ($ordered, $namesOf, $since) {
            $advised = collect(preg_split('/[\r\n,;]+/', (string) $visit->tests_advised))->map(fn ($name) => trim($name))->filter()->unique(fn ($name) => self::plain($name))->values();
            $done = ($ordered[$visit->patient_id] ?? collect())->filter(fn (array $item) => $item['at'] >= $since[$visit->id])->flatMap(fn (array $item) => $item['names']);
            $missing = $advised->filter(function (string $name) use ($done, $namesOf) {
                $names = $namesOf($name);

                return $names['whole'] !== '' && ! $done->contains(fn (array $have) => self::same($names, $have));
            })->values();

            return $missing->isEmpty() ? null : ['visit' => $visit, 'advised' => $advised, 'missing' => $missing];
        })->filter()->values();
    }

    /**
     * The same visits with only the missing tests our own lab does, each under the lab's own name rather than as the
     * doctor wrote it: a test, or a package of tests under the package's name. A visit with none of those left drops
     * out. A test that matches nothing in the lab's list of tests and packages is taken to be done outside, and is
     * left to the report.
     *
     * @param  Collection<int, array{visit: Consultation, advised: Collection<int, string>, missing: Collection<int, string>}>  $visits
     * @return Collection<int, array{visit: Consultation, advised: Collection<int, string>, missing: Collection<int, string>}>
     */
    public static function ourLab(Collection $visits): Collection
    {
        if ($visits->isEmpty()) {
            return $visits;
        }
        // The lab's tests first, then its packages, each by its own name.
        $ours = LabTest::where('is_active', true)->orderBy('name')->pluck('name')
            ->concat(LabPackage::where('is_active', true)->orderBy('name')->pluck('name'))
            ->map(fn (string $name) => ['name' => $name] + self::names($name));
        $found = [];
        $lab = function (string $name) use ($ours, &$found): ?string {
            if (! array_key_exists($name, $found)) {
                $names = self::names($name);
                // One known by exactly that name first. Then, of those whose whole name is inside the other, the
                // nearest in length: "Creatinine" is "Serum Creatinine" rather than "24 Hours Creatinine".
                $test = $ours->first(fn (array $t) => array_intersect($names['keys'], $t['keys']) !== [])
                    ?? $ours->filter(fn (array $t) => self::within($names, $t))->sortBy(fn (array $t) => abs(strlen($t['whole']) - strlen($names['whole'])))->first();
                $found[$name] = $test['name'] ?? null;
            }

            return $found[$name];
        };

        return $visits->map(function (array $row) use ($lab) {
            $missing = $row['missing']->map($lab)->filter()->unique()->values();

            return $missing->isEmpty() ? null : ['missing' => $missing] + $row;
        })->filter()->values();
    }

    /**
     * Every name a test may go by, as letters and digits only. `whole` is the name as written, and `starts` where each
     * of its words begins. `keys` is that name and the other names it goes by: the name without what is in brackets;
     * what is in brackets ("(TSH)"), and each part of it between commas ("(T3, T4, TSH)"); each side of a dash
     * ("Complete Blood Picture - CBP"); the initials of a name of three words or more; and the other common names of
     * the same test. These follow namesOf() in LabOrderForm.tsx, with the commas and the dash as well.
     *
     * @return array{whole: string, starts: list<int>, keys: list<string>}
     */
    public static function names(?string $name): array
    {
        $full = trim(mb_strtolower((string) $name));
        $whole = self::plain($full);
        if ($whole === '') {
            return ['whole' => '', 'starts' => [], 'keys' => []];
        }
        $starts = [];
        $at = 0;
        foreach (preg_split('/[^a-z0-9]+/', $full, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $starts[] = $at;
            $at += strlen($word);
        }
        preg_match_all('/\(([^)]+)\)/u', $full, $brackets);
        $inside = array_merge(...array_map(fn (string $short) => preg_split('/\s*,\s*/u', $short) ?: [], $brackets[1]));
        $plain = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\([^)]*\)/u', ' ', $full)));
        $parts = $plain === '' ? [] : (preg_split('/\s+[-–—]\s+/u', $plain) ?: []);
        $names = [$full, $plain, ...$brackets[1], ...$inside, ...$parts];
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

        return ['whole' => $whole, 'starts' => $starts, 'keys' => $keys];
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
     * Whether two tests are one: the whole name of one inside the whole name of the other, or any name of the one
     * exactly the same as a name of the other.
     *
     * @param  array{whole: string, starts: list<int>, keys: list<string>}  $one
     * @param  array{whole: string, starts: list<int>, keys: list<string>}  $other
     */
    private static function same(array $one, array $other): bool
    {
        return self::within($one, $other) || array_intersect($one['keys'], $other['keys']) !== [];
    }

    /**
     * Whether the whole name of one is inside the whole name of the other, the shorter having three letters or more,
     * and starting where a word of the longer starts: "cbp" is inside "complete blood picture - cbp", and "lipid
     * profile" inside "fasting lipid profile". Never from the middle of a word, so "echo" is not in "catecholamines".
     *
     * @param  array{whole: string, starts: list<int>, keys: list<string>}  $one
     * @param  array{whole: string, starts: list<int>, keys: list<string>}  $other
     */
    private static function within(array $one, array $other): bool
    {
        [$short, $long] = strlen($one['whole']) <= strlen($other['whole']) ? [$one, $other] : [$other, $one];
        if (strlen($short['whole']) < 3) {
            return false;
        }
        foreach ($long['starts'] as $at) {
            if (str_starts_with(substr($long['whole'], $at), $short['whole'])) {
                return true;
            }
        }

        return false;
    }
}
