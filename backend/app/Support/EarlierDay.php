<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Billing that is entered on a later day than it belongs to: a bill that was missed, or money that was taken and
 * not written down. The record carries the day it belongs to, so the lists, the reports and the day close count it
 * on that day, and it keeps a note of when it was really typed in.
 *
 * Nothing here gets round the day close. The date is put on the record before it is saved, so a day whose accounts
 * are closed refuses it like any other entry (see DayClose::lockMoney); an admin reopens that day first.
 */
class EarlierDay
{
    /** How far back an entry may be dated. Far enough for any bill found missing, near enough to catch a wrong year. */
    public const DAYS_BACK = 365;

    /**
     * What a date sent with a bill or a payment has to be.
     *
     * @return list<string>
     */
    public static function rule(): array
    {
        return ['nullable', 'date', 'before_or_equal:today', 'after_or_equal:'.today()->subDays(self::DAYS_BACK)->toDateString()];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $field): array
    {
        return [
            "{$field}.before_or_equal" => 'The date cannot be after today.',
            "{$field}.after_or_equal" => 'The date cannot be more than a year back.',
        ];
    }

    /** The moment an entry belongs to when that is a day before today; nothing when it is today. */
    public static function at(?string $day): ?Carbon
    {
        if (! filled($day)) {
            return null;
        }
        $on = Carbon::parse($day)->startOfDay();

        // The time of day is the time of typing, so several entries for one day keep the order they were made in.
        return $on->isSameDay(today()) ? null : $on->setTimeFrom(now());
    }

    /**
     * Puts the earlier day on a record that has not been saved yet, in the column that says which day it counts on.
     * `$noted` is for the records dated by when they were made, which would otherwise lose the day of typing.
     */
    public static function date(Model $record, ?Carbon $at, string $column = 'created_at', bool $noted = true): Model
    {
        if ($at) {
            $record->{$column} = $at;
            if ($noted) {
                $record->entered_at = now();
            }
        }

        return $record;
    }

    /** Money cannot be said to have come in before the bill it pays was made. */
    public static function notBefore(?Carbon $at, mixed $billed, string $field = 'paid_on'): void
    {
        $billed = $billed ? Carbon::parse($billed) : null;
        if ($at && $billed && $at->toDateString() < $billed->toDateString()) {
            throw ValidationException::withMessages([$field => 'The money cannot be dated before the bill, which is of '.$billed->format('j M Y').'.']);
        }
    }

    /** Written in the activity log beside the entry itself, so anything dated back can be found and read. */
    public static function log(?Carbon $at, ?int $patientId, ?Model $subject, string $what): void
    {
        if ($at) {
            AuditLog::record('billing.earlier_day', $patientId, $subject, $what.' entered on '.now()->format('j M Y').' for '.$at->format('j M Y'));
        }
    }
}
