<?php

namespace App\Support;

use App\Models\Admission;
use App\Models\Appointment;
use App\Models\DischargeSummary;
use App\Models\Medicine;
use App\Models\MedicineSubscription;
use App\Models\PrescriptionItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The parts of a patient's page that go only to those whose work needs them. Each is left out, not sent empty, for
 * anyone else, so the page shows a part only when its person may see it.
 *
 *  - discharge_summaries: approved summaries of the stays the page lists. Those who read clinical notes also get the
 *    diagnosis; those who work in IPD without reading notes (the front desk) get a list to print from, without it.
 *  - medicines_prescribed: what the latest finished visits prescribed and what approved discharge summaries sent home.
 *  - monthly_medicines: the patient's monthly medicine lists from the patient app, active and paused.
 *    These two go to those who read clinical notes and to the pharmacy.
 *
 * A doctor sees every doctor's entries here, as in case history. Everything is built from the visits and stays the
 * page has already fetched, with a few small queries of its own.
 */
class PatientPage
{
    /** How many finished visits' prescriptions are listed. */
    public const PRESCRIPTIONS = 10;

    /** How many monthly lists are listed. */
    public const LISTS = 20;

    /**
     * @param  Collection<int, Appointment>  $visits  the visits the page lists, with their consultation and doctor
     * @param  Collection<int, Admission>  $admissions  the stays the page lists, with their doctor
     * @return array<string, list<array<string, mixed>>>
     */
    public static function sections(User $user, Request $request, int $patientId, Collection $visits, Collection $admissions): array
    {
        $clinical = $user->seesClinical();
        $pharmacy = $user->mayInPharmacy($request);
        $toPrint = ! $clinical && $user->allows('canWorkIpd');
        if (! $clinical && ! $pharmacy && ! $toPrint) {
            return [];
        }

        $summaries = self::approvedSummaries($admissions);
        $out = [];
        if ($clinical || $toPrint) {
            $out['discharge_summaries'] = self::dischargeSummaries($admissions, $summaries, $clinical);
        }
        if ($clinical || $pharmacy) {
            // A medicine's name opens its pharmacy page only for those who may open the pharmacy's screens.
            $out['medicines_prescribed'] = self::prescribed($visits, $admissions, $summaries, $pharmacy);
            $out['monthly_medicines'] = self::monthly($patientId);
        }

        return $out;
    }

    /**
     * The approved summaries of these stays, by stay. One still being written or waiting for approval is left out:
     * it can still change.
     *
     * @param  Collection<int, Admission>  $admissions
     * @return Collection<int, DischargeSummary>
     */
    private static function approvedSummaries(Collection $admissions): Collection
    {
        if ($admissions->isEmpty()) {
            return collect();
        }

        return DischargeSummary::whereIn('admission_id', $admissions->modelKeys())->whereNotNull('approved_at')
            ->get(['id', 'admission_id', 'diagnosis', 'medicines', 'follow_up_on', 'approved_at'])
            ->keyBy('admission_id');
    }

    /**
     * One line per approved summary, newest stay first, with what the print needs. The diagnosis, its first line only,
     * goes only to those who read clinical notes.
     *
     * @param  Collection<int, Admission>  $admissions
     * @param  Collection<int, DischargeSummary>  $summaries
     * @return list<array<string, mixed>>
     */
    private static function dischargeSummaries(Collection $admissions, Collection $summaries, bool $withDiagnosis): array
    {
        return $admissions->filter(fn (Admission $a) => $summaries->has($a->id))->map(function (Admission $a) use ($summaries, $withDiagnosis) {
            $summary = $summaries->get($a->id);
            $row = [
                'admission_id' => $a->id, 'ipd_no' => $a->ipd_no, 'status' => $a->status,
                'admitted_at' => $a->admitted_at?->format('Y-m-d H:i'), 'discharged_at' => $a->discharged_at?->format('Y-m-d H:i'),
                'doctor' => $a->doctor?->only(['id', 'name']),
                'approved_at' => $summary->approved_at->format('Y-m-d H:i'),
                'follow_up_on' => $summary->follow_up_on?->format('Y-m-d'),
            ];
            if ($withDiagnosis) {
                $row['diagnosis'] = self::firstLine($summary->diagnosis);
            }

            return $row;
        })->values()->all();
    }

    /**
     * What the patient was given to take, newest first: the medicines of the latest finished visits among those the
     * page lists, and the take-home medicines of approved discharge summaries (never of a stay that ended in death).
     *
     * With `$match`, a medicine is paired with the pharmacy's own only where exactly one active medicine has that
     * very name, in any case. Two with the same name match neither; a name is never guessed.
     *
     * @param  Collection<int, Appointment>  $visits
     * @param  Collection<int, Admission>  $admissions
     * @param  Collection<int, DischargeSummary>  $summaries
     * @return list<array<string, mixed>>
     */
    private static function prescribed(Collection $visits, Collection $admissions, Collection $summaries, bool $match): array
    {
        $finished = $visits->filter(fn (Appointment $a) => $a->consultation?->completed_at !== null)->keyBy(fn (Appointment $a) => $a->consultation->id);
        $items = $finished->isEmpty() ? collect() : PrescriptionItem::whereIn('consultation_id', $finished->keys())
            ->orderBy('position')->orderBy('id')
            ->get(['consultation_id', 'medicine', 'dose', 'frequency', 'timing', 'duration', 'instructions'])
            ->groupBy('consultation_id');

        $rows = $finished->filter(fn (Appointment $a, $consultation) => $items->has($consultation))
            ->sortByDesc(fn (Appointment $a) => $a->consultation->completed_at->format('Y-m-d H:i:s'))
            ->take(self::PRESCRIPTIONS)
            ->map(fn (Appointment $a, $consultation) => [
                'key' => "v{$a->id}", 'kind' => 'visit', 'date' => $a->consultation->completed_at->format('Y-m-d H:i'),
                'doctor' => $a->doctor?->only(['id', 'name']), 'appointment_id' => $a->id,
                'medicines' => $items->get($consultation)->map(fn (PrescriptionItem $m) => [
                    'medicine' => $m->medicine,
                    'directions' => self::directions([$m->dose, $m->frequency, $m->timing, $m->duration, $m->instructions]),
                ])->values()->all(),
            ]);

        $home = $admissions->filter(fn (Admission $a) => $summaries->has($a->id) && $a->status !== 'died')->map(function (Admission $a) use ($summaries) {
            $summary = $summaries->get($a->id);
            // Written by hand on the summary, so any part may be missing.
            $medicines = collect(is_array($summary->medicines) ? $summary->medicines : [])
                ->filter(fn ($m) => is_array($m) && is_string($m['medicine'] ?? null) && trim($m['medicine']) !== '')
                ->map(fn (array $m) => [
                    'medicine' => trim($m['medicine']),
                    'directions' => self::directions([$m['dose'] ?? null, $m['frequency'] ?? null, $m['timing'] ?? null, $m['duration'] ?? null, $m['instructions'] ?? null]),
                ])->values()->all();

            return $medicines === [] ? null : [
                'key' => "d{$a->id}", 'kind' => 'discharge', 'date' => ($a->discharged_at ?? $summary->approved_at)->format('Y-m-d H:i'),
                'doctor' => $a->doctor?->only(['id', 'name']), 'admission_id' => $a->id, 'ipd_no' => $a->ipd_no,
                'medicines' => $medicines,
            ];
        })->filter();

        $rows = $rows->concat($home)->sortByDesc('date')->values();
        if (! $match) {
            return $rows->all();
        }

        $ids = self::medicineIds($rows->flatMap(fn (array $row) => array_column($row['medicines'], 'medicine')));

        return $rows->map(function (array $row) use ($ids) {
            $row['medicines'] = array_map(fn (array $m) => $m + ['medicine_id' => $ids[mb_strtolower(trim($m['medicine']))] ?? null], $row['medicines']);

            return $row;
        })->all();
    }

    /**
     * The patient's monthly lists from the patient app that are still kept (active or paused), the next due first.
     * Due soon means within the week the pharmacy makes packs up for.
     *
     * @return list<array<string, mixed>>
     */
    private static function monthly(int $patientId): array
    {
        $soon = today()->addDays(7);

        return MedicineSubscription::where('patient_id', $patientId)->whereIn('status', ['active', 'paused'])
            ->orderBy('next_due_on')->orderBy('id')->limit(self::LISTS)
            ->get(['id', 'title', 'items', 'day_of_month', 'status', 'next_due_on', 'last_prepared_on', 'notes'])
            ->map(fn (MedicineSubscription $s) => [
                'id' => $s->id, 'title' => $s->title, 'status' => $s->status, 'day_of_month' => (int) $s->day_of_month,
                'next_due_on' => $s->next_due_on?->format('Y-m-d'),
                'due_soon' => $s->status === 'active' && $s->next_due_on !== null && $s->next_due_on->lte($soon),
                'last_prepared_on' => $s->last_prepared_on?->format('Y-m-d'), 'notes' => $s->notes,
                // As the patient chose them: from the pharmacy's list (with its id) or typed in by the patient.
                'items' => collect(is_array($s->items) ? $s->items : [])->filter(fn ($i) => is_array($i))->map(fn (array $i) => [
                    'medicine_id' => isset($i['medicine_id']) ? (int) $i['medicine_id'] : null,
                    'name' => (string) ($i['name'] ?? ''),
                    'quantity' => isset($i['quantity']) ? (int) $i['quantity'] : null,
                ])->values()->all(),
            ])->all();
    }

    /**
     * The pharmacy's medicine for each name, where exactly one active medicine has that very name in any case.
     *
     * @param  Collection<int, string>  $names
     * @return array<string, int> the name in lower case => the medicine's id
     */
    private static function medicineIds(Collection $names): array
    {
        $names = $names->map(fn ($name) => mb_strtolower(trim((string) $name)))->filter()->unique()->values();
        if ($names->isEmpty()) {
            return [];
        }

        return Medicine::where('is_active', true)->whereIn(DB::raw('LOWER(name)'), $names->all())->get(['id', 'name'])
            ->groupBy(fn (Medicine $m) => mb_strtolower($m->name))
            ->filter(fn (Collection $same) => $same->count() === 1)
            ->map(fn (Collection $same) => (int) $same->first()->id)
            ->all();
    }

    /**
     * How to take a medicine, as one line: dose, how often, when, for how long, anything else.
     *
     * @param  list<mixed>  $parts
     */
    private static function directions(array $parts): string
    {
        return implode(' · ', array_filter(array_map(fn ($part) => is_scalar($part) ? trim((string) $part) : '', $parts), fn (string $part) => $part !== ''));
    }

    private static function firstLine(?string $text): ?string
    {
        $line = trim(preg_split('/\R/', trim((string) $text), 2)[0]);

        return $line === '' ? null : $line;
    }
}
