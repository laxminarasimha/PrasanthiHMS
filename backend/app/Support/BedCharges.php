<?php

namespace App\Support;

use App\Models\Admission;
use App\Models\AdmissionBed;
use App\Models\Bed;
use App\Models\DayClose;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The bed history of a stay, and the bed charge added to the bill for each day of it.
 *
 * A day of stay is 24 hours counted from the time of admission, and a day that has begun is charged in full: the same
 * rule the "fill in bed charges" button has always used. Day 1 begins at admission, day 2 exactly 24 hours later, and
 * so on. Each day is charged for the bed the patient is in when that day begins (or the first bed they are given
 * during it), at that bed's daily rate.
 *
 * The host has no timer to run things at midnight, so nothing happens "at" a time. Instead the charges are brought
 * up to date whenever a case or the list of cases is opened, and once in a while after any page has been served.
 */
class BedCharges
{
    public const SETTING = 'ipd_bed_charges';

    /**
     * @return array{auto: bool, from: ?string}
     */
    public static function settings(): array
    {
        return Setting::group(self::SETTING, ['auto' => false, 'from' => null]);
    }

    // ---- Which bed, when ----

    /** The patient is given a bed, moves to another, or gives it up. Closes the bed they were in and opens the new one. */
    public static function moveTo(Admission $admission, ?int $bedId, ?Carbon $at = null, ?int $userId = null): void
    {
        $at ??= now();
        AdmissionBed::where('admission_id', $admission->id)->whereNull('to_at')->update(['to_at' => $at]);
        if ($bedId && ($bed = Bed::find($bedId))) {
            AdmissionBed::create(['admission_id' => $admission->id, 'bed_id' => $bed->id, 'ward' => $bed->ward, 'bed_no' => $bed->bed_no, 'from_at' => $at, 'moved_by' => $userId]);
        }
    }

    /** The stay has ended: the bed is given up at that time. */
    public static function leave(Admission $admission, Carbon $at): void
    {
        AdmissionBed::where('admission_id', $admission->id)->whereNull('to_at')->update(['to_at' => $at]);
    }

    /**
     * Makes the history agree with the bed the case says the patient is in. It normally does already; this covers a case
     * whose bed was set without the history being written (one admitted in the minutes while this was being released).
     */
    public static function reconcile(Admission $admission): void
    {
        // A stay that has ended holds no bed: whatever was still open closes at the time of leaving.
        if ($admission->discharged_at) {
            self::leave($admission, $admission->discharged_at);

            return;
        }
        $open = AdmissionBed::where('admission_id', $admission->id)->whereNull('to_at')->latest('id')->first();
        if (($open?->bed_id) === $admission->bed_id) {
            return;
        }
        // With no history at all the patient has been in this bed since they came; otherwise the change is noticed now.
        $first = ! AdmissionBed::where('admission_id', $admission->id)->exists();
        self::moveTo($admission, $admission->bed_id, $first ? $admission->admitted_at : now());
    }

    // ---- The daily charge ----

    /** When day $n of the stay begins (day 1 at admission). */
    private static function dayStarts(Admission $admission, int $n): Carbon
    {
        return $admission->admitted_at->copy()->addHours(24 * ($n - 1));
    }

    /** Whether day $n has begun by $until. Day 1 always has; a stay that ends exactly as a day would begin does not start it. */
    private static function begun(Admission $admission, int $n, Carbon $until): bool
    {
        return $n === 1 || self::dayStarts($admission, $n)->lt($until);
    }

    /**
     * Adds the bed charge for every day of the stay that has begun and has not been dealt with yet.
     * Safe to call as often as you like: each day is handled once. Returns how many charges were added.
     */
    public static function bringUpToDate(Admission $admission): int
    {
        self::reconcile($admission);
        // Nothing to do for most calls, so look before locking anything.
        if ($admission->bill_approved_at || ! self::begun($admission, $admission->bed_days_done + 1, $admission->discharged_at ?? now())) {
            return 0;
        }

        return DB::transaction(function () use ($admission) {
            $settings = self::settings();
            $locked = Admission::whereKey($admission->id)->lockForUpdate()->first();
            if (! $locked || $locked->bill_approved_at) {
                return 0;
            }
            $until = $locked->discharged_at ?? now();
            $stays = AdmissionBed::where('admission_id', $locked->id)->orderBy('from_at')->orderBy('id')->get();
            $beds = Bed::whereIn('id', $stays->pluck('bed_id')->filter())->get()->keyBy('id');
            $added = 0;

            for ($n = $locked->bed_days_done + 1; self::begun($locked, $n, $until); $n++) {
                $starts = self::dayStarts($locked, $n);
                $ends = $starts->copy()->addHours(24);
                // The bed they were in as the day began; failing that, the first one they were given during it.
                $stay = $stays->first(fn (AdmissionBed $s) => $s->from_at->lte($starts) && (! $s->to_at || $s->to_at->gt($starts)))
                    ?? $stays->first(fn (AdmissionBed $s) => $s->from_at->gt($starts) && $s->from_at->lt($ends) && $s->from_at->lte($until));
                if (! $stay && $ends->gt($until)) {
                    // No bed yet, and the day is not over: it may still be given one. Looked at again next time.
                    break;
                }

                $rate = (float) ($stay ? ($beds->get($stay->bed_id)?->daily_charge ?? 0) : 0);
                $wanted = $settings['auto'] && $locked->auto_bed_charge && $rate > 0 && (! $settings['from'] || $starts->gte(Carbon::parse($settings['from'])));
                if ($wanted) {
                    // A day whose accounts are already closed cannot take a new line, so it goes on today and says which day it is for.
                    $late = DayClose::isClosed($starts);
                    $locked->charges()->create([
                        'description' => "Bed charge, day {$n} ({$stay->ward} · Bed {$stay->bed_no})",
                        'category' => 'Bed',
                        'charged_on' => $late ? today() : $starts->toDateString(),
                        'quantity' => 1, 'unit_price' => $rate, 'amount' => $rate,
                        'remarks' => $late ? 'For '.$starts->format('j M Y').'; added later because that day was closed' : null,
                        'source_type' => 'bed', 'source_id' => $n,
                    ]);
                    $added++;
                }
                $locked->bed_days_done = $n;
            }

            if ($locked->isDirty('bed_days_done')) {
                $locked->save();
            }
            if ($added) {
                $locked->recalculate();
            }
            $admission->setRawAttributes($locked->getAttributes(), true);

            return $added;
        });
    }

    /**
     * Brings every open case up to date, at most once every ten minutes. Called from the list of cases,
     * and after any page has been served, so the totals on the list are right without each case being opened.
     */
    public static function sweep(bool $force = false): void
    {
        try {
            if (! $force && ! Cache::add('bed_charges_swept', 1, 600)) {
                return;
            }
            Admission::where('status', 'admitted')->whereNull('bill_approved_at')->get()->each(fn (Admission $a) => self::bringUpToDate($a));
        } catch (Throwable $e) {
            // Never at the cost of the page that happened to trigger it; the next visit tries again.
            report($e);
        }
    }
}
