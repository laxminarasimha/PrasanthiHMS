<?php

namespace App\Support;

use App\Models\Admission;
use App\Models\AdmissionNote;
use App\Models\AdmissionOrder;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use Illuminate\Support\Str;

/**
 * What the records of a stay already say, laid out as text for the discharge summary:
 * lab results, medicines given, and operations. The doctor adds it to the summary with one tap and edits it there;
 * nothing is written into the summary by itself.
 */
class StaySummary
{
    /**
     * @param  bool  $withChart  Whether the reader may see the bedside chart; its orders are left out otherwise.
     * @return array{investigations: string, treatment_given: string, procedures: string, operative_findings: string, medicines: list<array<string, string|null>>}
     */
    public static function for(Admission $admission, bool $withChart): array
    {
        $orders = $withChart
            ? AdmissionOrder::where('admission_id', $admission->id)->where('kind', 'medicine')->orderBy('started_at')->orderBy('id')->get()
            : collect();
        $operations = AdmissionNote::where('admission_id', $admission->id)->where('kind', 'ot')->orderBy('noted_at')->orderBy('id')->get();

        return [
            'investigations' => self::investigations($admission),
            'treatment_given' => $orders->map(fn (AdmissionOrder $o) => trim(implode(' ', array_filter([$o->title, $o->dose, $o->route, $o->frequency])))
                .' ('.($o->stopped_at ? self::day($o->started_at).' to '.self::day($o->stopped_at) : 'from '.self::day($o->started_at)).')')->implode("\n"),
            'procedures' => $operations->filter(fn (AdmissionNote $n) => filled($n->details['procedure'] ?? null))->map(function (AdmissionNote $n) {
                $team = collect(['surgeon' => 'Surgeon', 'assistant' => 'Assistant', 'anaesthetist' => 'Anaesthetist', 'anaesthesia' => 'Anaesthesia'])
                    ->filter(fn ($label, $key) => filled($n->details[$key] ?? null))
                    ->map(fn ($label, $key) => "{$label}: {$n->details[$key]}")->implode('; ');

                return $n->details['procedure'].' on '.$n->noted_at->format('j M Y').($team ? " ({$team})" : '');
            })->implode("\n"),
            'operative_findings' => $operations->filter(fn (AdmissionNote $n) => filled($n->details['findings'] ?? null))
                ->map(fn (AdmissionNote $n) => $operations->count() > 1 && filled($n->details['procedure'] ?? null) ? "{$n->details['procedure']}: {$n->details['findings']}" : $n->details['findings'])
                ->implode("\n"),
            // What is still running on the chart is the starting point for the medicines to go home with.
            'medicines' => $orders->whereNull('stopped_at')->map(fn (AdmissionOrder $o) => [
                'medicine' => $o->title, 'dose' => $o->dose ? Str::limit($o->dose, 47) : null, 'frequency' => $o->frequency ? Str::limit($o->frequency, 47) : null,
                'timing' => null, 'duration' => null, 'instructions' => null,
            ])->values()->all(),
        ];
    }

    /** One line for each test on each day it was reported: "14 Nov · Lipid Profile: Total Cholesterol 232 mg/dL (high); HDL 38 mg/dL (low)". */
    private static function investigations(Admission $admission): string
    {
        $from = $admission->admitted_at->copy()->startOfDay();
        $to = ($admission->discharged_at ?? now())->copy()->endOfDay();
        $items = LabOrderItem::whereIn('lab_order_id', LabOrder::where('patient_id', $admission->patient_id)->whereBetween('ordered_at', [$from, $to])->select('id'))
            ->whereNotNull('reported_at')->with('results')->orderBy('reported_at')->orderBy('id')->limit(80)->get();

        return $items->map(function (LabOrderItem $item) {
            $values = $item->results->filter(fn ($r) => ! $r->is_heading && filled($r->value))->map(function ($r) use ($item) {
                $name = strcasecmp($r->parameter, $item->test_name) === 0 ? '' : $r->parameter.' ';

                return $name.trim($r->value.' '.$r->unit).($r->flag ? " ({$r->flag})" : '');
            });
            $said = $values->isNotEmpty() ? $values->implode('; ') : Str::limit(trim((string) preg_replace('/\s+/', ' ', (string) $item->report_text)), 200);

            return $said === '' ? null : self::day($item->reported_at)." · {$item->test_name}: {$said}";
        })->filter()->implode("\n");
    }

    private static function day(\DateTimeInterface $at): string
    {
        return $at->format('j M');
    }
}
