<?php

namespace App\Support;

use App\Models\ReferralDoctor;
use Illuminate\Support\Collection;

/**
 * Ties a "referred by" name to the hospital's list of referring doctors, so "Dr. Ravi Kumar",
 * "ravi kumar" and "Dr Ravi Kumar, Cardiology" all count as the same doctor in the reports.
 * A name that is not on the list is kept exactly as typed.
 */
class Referral
{
    /** A name without its "Dr", dots, extra spaces or capitals, for comparing. */
    public static function plain(?string $name): string
    {
        $name = mb_strtolower(trim((string) $name));
        $name = preg_replace('/^(dr|doctor)\b[\s.]*/u', '', $name) ?? $name;

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? $name);
    }

    /** The doctor on the list that this text names, if any. Text picked from the list may carry ", speciality, place" after the name. */
    public static function find(?string $text): ?ReferralDoctor
    {
        if (trim((string) $text) === '') {
            return null;
        }
        $list = self::list();

        return $list->get(self::plain($text)) ?? $list->get(self::plain(str($text)->before(',')->value()));
    }

    /**
     * What to save for a typed or picked name: the list's own spelling and its id when the doctor is on the list.
     *
     * @return array{referred_by: ?string, referral_doctor_id: ?int}
     */
    public static function fields(?string $text): array
    {
        $text = trim((string) $text);
        $doctor = self::find($text);

        return ['referred_by' => $doctor?->name ?? ($text === '' ? null : $text), 'referral_doctor_id' => $doctor?->id];
    }

    /**
     * How a report groups a row and what it calls the group. Rows saved before the link existed are matched by name.
     *
     * @return array{0: string, 1: string}
     */
    public static function group(?int $doctorId, ?string $text): array
    {
        $doctor = ($doctorId ? self::list()->firstWhere('id', $doctorId) : null) ?? self::find($text);
        if ($doctor) {
            return ['doctor-'.$doctor->id, $doctor->name];
        }
        $text = trim((string) $text);

        return $text === '' ? ['', 'Not referred (self)'] : ['typed-'.self::plain($text), $text];
    }

    /**
     * Doctors who refer to us, by plain name. Read once per request, and again after the list changes.
     *
     * @return Collection<string, ReferralDoctor>
     */
    private static function list(): Collection
    {
        if (! app()->bound('referral.list')) {
            app()->instance('referral.list', ReferralDoctor::where('direction', 'by')->get()->keyBy(fn (ReferralDoctor $d) => self::plain($d->name)));
        }

        return app('referral.list');
    }

    public static function forget(): void
    {
        app()->forgetInstance('referral.list');
    }
}
