<?php

namespace App\Support;

use App\Models\Medicine;

/**
 * How many tablets or capsules one line of a prescription comes to, from the dose, how often and for how long, so the
 * pharmacy counter can start the quantity there instead of at 1. The working is shown beside it in plain words, and
 * the pharmacist can always change the number.
 *
 * Nothing is guessed. Only a few plain ways of writing are read:
 *  - how often: a pattern such as 1-0-1, 1-1-1-1 or ½-0-½, or OD, BD, TDS, QID, HS and "8 hourly", the words the ward
 *    chart reads;
 *  - the dose: a number of tablets or capsules, such as "1 tab", "2 tablets", "½ tablet" or "1 cap". With a pattern
 *    and no dose, the pattern's numbers are the tablets. With OD, BD and the like the dose must be written;
 *  - for how long: days, weeks (7 days) or months (30 days).
 * Anything else, such as SOS, Stat, alternate days, once a week, "To continue" or a strength like "40 mg", gives no
 * quantity, and the counter starts at 1 as before. Half tablets are made a whole one in the total; a capsule is never
 * halved.
 */
class RxQuantity
{
    /** The days in a month, as the hospital counts a month of tablets. */
    public const MONTH = 30;

    /** The longest course worked out. A longer one is typed at the counter. */
    private const MOST_DAYS = 365;

    /** The most tablets or capsules taken at one time. A bigger number is more likely a strength than a count. */
    private const MOST_AT_ONCE = 4;

    /** How many times a day, for the words the ward chart reads (frontend/src/pages/ipd/doseTiming.ts), and HS. */
    private const TIMES_A_DAY = [
        'od' => 1, 'once daily' => 1, 'once a day' => 1, 'daily' => 1, 'hs' => 1,
        'bd' => 2, 'bid' => 2, 'twice daily' => 2, 'twice a day' => 2,
        'tds' => 3, 'tid' => 3, 'thrice daily' => 3, 'three times a day' => 3,
        'qid' => 4, 'qds' => 4, 'four times a day' => 4,
    ];

    /** Words in a medicine's type that say it is not a tablet or a capsule, such as "Tablet / Syrup". */
    private const OTHER_FORMS = '/\b(syrups?|syp|suspensions?|solutions?|drops?|creams?|ointments?|gels?|lotions?|injections?|inj|liquids?|powders?|sachets?|sprays?|inhalers?)\b/u';

    /**
     * The count for a medicine the pharmacy stocks, or null. Only for a tablet or a capsule, by the medicine's own type,
     * sold one at a time from a strip (every batch on sale holds more than one to a pack), and only when a dose that
     * names a form names that one. The caller passes null when the name is shared by more than one medicine in use,
     * so a count is never worked out for the wrong one.
     *
     * @return array{quantity: int, worked_out: string}|null
     */
    public static function forStock(?Medicine $medicine, ?string $dose, ?string $frequency, ?string $duration): ?array
    {
        $form = self::formOf($medicine?->type);
        $batches = $form ? $medicine->sellableBatches : null;
        if (! $batches || $batches->isEmpty() || $batches->contains(fn ($batch) => (int) $batch->units_per_pack <= 1)) {
            return null;
        }
        $count = self::count($dose, $frequency, $duration);
        if (! $count || ($count['form'] && $count['form'] !== $form) || ($form === 'capsule' && $count['halves'])) {
            return null;
        }

        return ['quantity' => $count['quantity'], 'worked_out' => $count['worked_out']];
    }

    /**
     * What a line comes to, read from what the doctor wrote, or null when it is not written in a way that can be
     * counted. `form` is the form the dose names ("tablet" or "capsule"), if it names one, and `halves` says whether
     * a half tablet came into it.
     *
     * @return array{quantity: int, worked_out: string, form: ?string, halves: bool}|null
     */
    public static function count(?string $dose, ?string $frequency, ?string $duration): ?array
    {
        $days = self::days(self::tidy($duration));
        $each = self::tidy($dose) === '' ? null : self::dose(self::tidy($dose));
        if (! $days || (self::tidy($dose) !== '' && ! $each)) {
            return null; // no course length, or a dose written some other way, such as 40 mg or 5 ml
        }

        // Everything is counted in half tablets, so no half is lost along the way.
        $pattern = self::pattern(self::tidy($frequency));
        if ($pattern !== null) {
            // With a dose written, the pattern can only say when (1 or 0); 2-0-2 or ½-0-½ would leave it unclear which number is the count.
            if ($each && array_filter($pattern, fn (int $part) => $part !== 0 && $part !== 2)) {
                return null;
            }
            $perDay = $each ? $each[0] * count(array_filter($pattern)) : array_sum($pattern);
            $halves = $each ? $each[0] % 2 === 1 : (bool) array_filter($pattern, fn (int $part) => $part % 2 === 1);
            $how = ($each ? self::shown($dose).' ' : '').preg_replace('/\s*[-–]\s*/u', '-', self::shown($frequency));
        } else {
            // OD, BD and the like say how often, not how many: the dose has to say that, in tablets or capsules.
            $times = self::timesADay(self::tidy($frequency));
            if (! $times || ! $each) {
                return null;
            }
            $perDay = $each[0] * $times;
            $halves = $each[0] % 2 === 1;
            $how = self::shown($dose).' '.self::shown($frequency);
        }

        $total = $perDay * $days[0];
        // A half tablet left over is made a whole one.
        $quantity = intdiv($total + 1, 2);
        if ($quantity < 1) {
            return null;
        }
        $exact = $total % 2 === 1 ? (intdiv($total, 2) ?: '').'½' : null;

        return [
            'quantity' => $quantity,
            'worked_out' => "{$how} for {$days[1]} = {$quantity}".($exact ? " ({$exact} rounded up)" : ''),
            'form' => $each[1] ?? null,
            'halves' => $halves,
        ];
    }

    /** "tablet" or "capsule" when a medicine's own type says it is one, such as Tablet, Dispersible Tablet or Capsule. */
    private static function formOf(?string $type): ?string
    {
        $type = self::tidy($type);
        $tablet = preg_match('/\b(tablets?|tabs?)\b/u', $type) === 1;
        $capsule = preg_match('/\b(capsules?|caps?)\b/u', $type) === 1;
        if ($tablet === $capsule || preg_match(self::OTHER_FORMS, $type)) {
            return null; // neither, both, or another form named with it
        }

        return $tablet ? 'tablet' : 'capsule';
    }

    /**
     * A dose written as tablets or capsules, as [half tablets, "tablet" or "capsule"]. Null for anything else, such
     * as 40 mg, 5 ml, 1 puff or a bare number.
     *
     * @return array{0: int, 1: string}|null
     */
    private static function dose(string $text): ?array
    {
        if (! preg_match('/^(.+?)\s*(tabs?|tablets?|caps?|capsules?)$/u', $text, $m)) {
            return null;
        }
        $halves = self::halves(trim($m[1]));

        return $halves && $halves <= 2 * self::MOST_AT_ONCE ? [$halves, str_starts_with($m[2], 'tab') ? 'tablet' : 'capsule'] : null;
    }

    /**
     * A pattern such as 1-0-1, 1-1-1-1 or ½-0-½: the half tablets at each time of day, or null.
     *
     * @return list<int>|null
     */
    private static function pattern(string $text): ?array
    {
        $parts = preg_split('/\s*[-–]\s*/u', $text);
        if (count($parts) < 3 || count($parts) > 4) {
            return null;
        }
        $halves = array_map(fn (string $part) => self::halves($part), $parts);

        return in_array(null, $halves, true) || max($halves) > 2 * self::MOST_AT_ONCE ? null : $halves;
    }

    /** How many times a day OD, BD, TDS, QID, HS, "8 hourly", "every 6 hours" or "q12h" means, or null. */
    private static function timesADay(string $text): ?int
    {
        // B.D. is BD.
        $word = str_replace('.', '', $text);
        if (isset(self::TIMES_A_DAY[$word])) {
            return self::TIMES_A_DAY[$word];
        }
        if (preg_match('/^(\d{1,2})\s*-?\s*(?:hourly|hrly)$/', $text, $m) || preg_match('/^every\s+(\d{1,2})\s*(?:h|hr|hrs|hours?)$/', $text, $m) || preg_match('/^q\s*(\d{1,2})\s*h$/', $text, $m)) {
            $hours = (int) $m[1];

            // Only a whole number of times a day: 48 hourly is alternate days, which is not counted.
            return $hours >= 1 && $hours <= 24 && 24 % $hours === 0 ? intdiv(24, $hours) : null;
        }

        return null;
    }

    /**
     * For how long, as [days, how it is put on screen]: "5 days", "1 week (7 days)", "1 month (30 days)". It may start
     * with "x" or "for". A bare number, "To continue" and anything else give null.
     *
     * @return array{0: int, 1: string}|null
     */
    private static function days(string $text): ?array
    {
        if (! preg_match('/^(?:(?:x|×|for)\s*)?(\d{1,3})\s*(d|days?|w|wks?|weeks?|mo|mon|mths?|months?)$/u', $text, $m)) {
            return null;
        }
        $count = (int) $m[1];
        [$each, $unit] = match ($m[2][0]) {
            'd' => [1, 'day'],
            'w' => [7, 'week'],
            default => [self::MONTH, 'month'],
        };
        $days = $count * $each;
        if ($count < 1 || $days > self::MOST_DAYS) {
            return null;
        }
        $label = $count.' '.$unit.($count === 1 ? '' : 's');

        return [$days, $each === 1 ? $label : "{$label} ({$days} days)"];
    }

    /** A number of tablets written plainly, in halves: "1" is 2; "½", "1/2" and "0.5" are 1; "1½", "1 1/2" and "1.5" are 3. */
    private static function halves(string $text): ?int
    {
        if (preg_match('/^\d{1,2}$/', $text)) {
            return 2 * (int) $text;
        }
        if (preg_match('/^(\d?) ?½$/u', $text, $m) || preg_match('/^(?:(\d) )?1\/2$/', $text, $m)) {
            return 2 * (int) ($m[1] ?? 0) + 1;
        }
        if (preg_match('/^(\d?)\.([05])$/', $text, $m)) {
            return 2 * (int) $m[1] + ($m[2] === '5' ? 1 : 0);
        }

        return null;
    }

    /** Lower case, single spaces, and no full stop at the end, for reading. */
    private static function tidy(?string $text): string
    {
        return mb_strtolower(self::shown($text));
    }

    /** As the doctor wrote it, with single spaces and no full stop at the end, for showing. */
    private static function shown(?string $text): string
    {
        return rtrim((string) preg_replace('/\s+/u', ' ', trim((string) $text)), '. ');
    }
}
