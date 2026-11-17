<?php

namespace App\Support;

use App\Models\PastRecord;
use App\Models\PatientHealthItem;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The draft of an old paper: what a machine made of the scan before a person has checked it.
 * A draft is never part of the patient's record. It only saves typing on the checking screen.
 */
class PastRecordDraft
{
    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    /** How printed papers label the parts worth lifting into the draft. */
    private const LABELS = [
        'complaints' => '(?:chief |presenting )?complaints?|c\/o|history',
        'findings' => '(?:general |clinical )?examination|on examination|o\/e|findings|vitals',
        'advice' => 'advice|advised|follow[- ]?up|review',
    ];

    public const MAX_MEDICINES = 40;

    public const MAX_RESULTS = 150;

    public const MAX_FACTS = 20;

    /**
     * Brings a draft, whoever wrote it, to the one shape the checking screen works with.
     * Anything unexpected is dropped rather than trusted.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public static function tidy(array $draft): array
    {
        $text = fn (mixed $v, int $max = 255) => is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
        $rows = fn (mixed $list, int $most) => array_slice(array_values(array_filter(is_array($list) ? $list : [], 'is_array')), 0, $most);

        $date = $text($draft['record_date'] ?? '', 30);
        $kind = $text($draft['kind'] ?? '', 20);

        return [
            'kind' => array_key_exists($kind, PastRecord::KINDS) ? $kind : null,
            'record_date' => self::date($date)?->toDateString(),
            'doctor_name' => $text($draft['doctor_name'] ?? ''),
            'hospital_name' => $text($draft['hospital_name'] ?? ''),
            'patient_name' => $text($draft['patient_name'] ?? ''),
            'complaints' => $text($draft['complaints'] ?? '', 5000),
            'diagnosis' => $text($draft['diagnosis'] ?? '', 5000),
            'findings' => $text($draft['findings'] ?? '', 5000),
            'advice' => $text($draft['advice'] ?? '', 5000),
            'notes' => $text($draft['notes'] ?? '', 5000),
            'allergies' => $text($draft['allergies'] ?? '', 500),
            'medicines' => array_values(array_filter(array_map(fn (array $m) => [
                'name' => $text($m['name'] ?? ''), 'dose' => $text($m['dose'] ?? '', 100), 'frequency' => $text($m['frequency'] ?? '', 100),
                'duration' => $text($m['duration'] ?? '', 100), 'instructions' => $text($m['instructions'] ?? ''),
            ], $rows($draft['medicines'] ?? [], self::MAX_MEDICINES)), fn (array $m) => $m['name'] !== '')),
            'results' => array_values(array_filter(array_map(fn (array $r) => [
                'test' => $text($r['test'] ?? ''), 'parameter' => $text($r['parameter'] ?? ''), 'value' => $text($r['value'] ?? ''),
                'unit' => $text($r['unit'] ?? '', 30), 'reference_range' => $text($r['reference_range'] ?? ''),
            ], $rows($draft['results'] ?? [], self::MAX_RESULTS)), fn (array $r) => $r['parameter'] !== '' && $r['value'] !== '')),
            'facts' => array_values(array_filter(array_map(fn (array $f) => [
                'kind' => $text($f['kind'] ?? '', 20), 'title' => $text($f['title'] ?? ''), 'since' => $text($f['since'] ?? '', 30),
            ], $rows($draft['facts'] ?? [], self::MAX_FACTS)), fn (array $f) => $f['title'] !== '' && array_key_exists($f['kind'], PatientHealthItem::KINDS))),
            'unsure' => array_values(array_filter(array_map(fn ($u) => $text($u, 200), array_slice(is_array($draft['unsure'] ?? null) ? $draft['unsure'] : [], 0, 40)))),
        ];
    }

    /**
     * A first draft from the plain text a reading program made of a printed page: the date, the
     * doctor, and any table of test values or medicines. Running prose is left for the person
     * checking, who sees the whole text beside the form.
     *
     * @return array<string, mixed>
     */
    public static function fromText(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $draft = ['medicines' => [], 'results' => []];

        // Tables, as the reading program writes them: | cell | cell |
        $table = [];
        foreach ([...$lines, ''] as $line) {
            if (preg_match('/^\s*\|.*\|\s*$/u', $line)) {
                $cells = array_map(fn ($c) => trim(strip_tags(str_replace(['<br>', '<br/>', '**'], [' ', ' ', ''], $c))), explode('|', trim(trim($line), '|')));
                if (! preg_match('/^[\s:\-|]+$/', $line)) {
                    $table[] = $cells;
                }

                continue;
            }
            if ($table) {
                self::readTable($table, $draft);
                $table = [];
            }
        }

        // Values written on plain lines: "Haemoglobin  13.2  g/dL  12.0 - 16.0". Only lines that end in a range are taken.
        if (! $draft['results']) {
            foreach ($lines as $line) {
                if (preg_match('/^\s*([A-Za-z][A-Za-z0-9 .,()\/%-]{1,60}?)\s*[:\-]?\s+([<>]?\d[\d,.]*)\s*([A-Za-zµ%\/^0-9.]{0,15})\s+\(?(\d[\d.]*\s*(?:-|–|to)\s*\d[\d.]*)\)?\s*$/u', $line, $m)) {
                    $draft['results'][] = ['test' => '', 'parameter' => trim($m[1]), 'value' => $m[2], 'unit' => $m[3], 'reference_range' => preg_replace('/\s+/', ' ', $m[4])];
                }
            }
        }

        foreach ($lines as $line) {
            $plain = trim(str_replace(['**', '#', '|'], ' ', $line));
            // A date is taken from the first line that says it is one, label and value together.
            if (! isset($draft['record_date']) && preg_match('/\bdate\b/iu', $plain) && ($found = self::dateIn($plain))) {
                $draft['record_date'] = $found->toDateString();
            }
            // In a table each cell is looked at by itself, so a name does not run on into the next cell.
            foreach (explode('|', str_replace(['**', '#'], '', $line)) as $cell) {
                $cell = trim($cell, " \t:");
                if (! isset($draft['diagnosis']) && preg_match('/^(?:provisional |final |clinical )?(?:diagnosis|impression)\s*[:\-]\s*(.+)$/iu', $cell, $m)) {
                    $draft['diagnosis'] = $m[1];
                }
                // Lines a printed paper labels itself: "Complaints: …", "Examination: …", "Advice: …".
                foreach (self::LABELS as $field => $label) {
                    if (preg_match('/^(?:'.$label.')\s*[:\-]\s*(.+)$/iu', $cell, $m)) {
                        $draft[$field] = trim(($draft[$field] ?? '')."\n".$m[1]);
                    }
                }
                if (! isset($draft['doctor_name']) && preg_match('/\bDr\.?\s+([A-Z][A-Za-z.]*(?:\s+[A-Z][A-Za-z.]*){0,3})/u', $cell, $m)) {
                    $draft['doctor_name'] = 'Dr. '.$m[1];
                }
            }
        }
        $draft['record_date'] ??= self::dateIn($text)?->toDateString();
        // A printed paper usually opens with the name of the place it came from, as a heading.
        foreach ($lines as $line) {
            if (preg_match('/^#{1,3}\s+(.{3,80})$/u', trim($line), $m)) {
                $draft['hospital_name'] = trim(str_replace('**', '', $m[1]));
                break;
            }
        }

        $lower = mb_strtolower($text);
        $draft['kind'] = match (true) {
            str_contains($lower, 'discharge summary') => 'discharge',
            count($draft['results']) >= 3 => 'lab',
            (bool) preg_match('/\b(x-?ray|ultrasound|usg|sonography|ct scan|mri|echocardiogra|radiolog)/u', $lower) => 'imaging',
            (bool) $draft['medicines'] => 'visit',
            default => null,
        };

        return self::tidy($draft);
    }

    /**
     * @param  list<list<string>>  $table  rows of cells; the first row is the headings
     * @param  array<string, mixed>  $draft
     */
    private static function readTable(array $table, array &$draft): void
    {
        $head = array_map('mb_strtolower', array_shift($table) ?? []);
        $column = function (string $pattern) use ($head): ?int {
            foreach ($head as $i => $cell) {
                if (preg_match($pattern, $cell)) {
                    return $i;
                }
            }

            return null;
        };

        $value = $column('/result|value|observed|finding/u');
        $medicine = $column('/medicine|drug|brand|\brx\b|formulation|tablet/u');
        if ($value !== null) {
            $name = $column('/test|investigation|parameter|description|analyte|name|particular/u') ?? 0;
            $unit = $column('/unit/u');
            $range = $column('/range|reference|normal|interval/u');
            $test = '';
            foreach ($table as $row) {
                $label = $row[$name] ?? '';
                $reading = $row[$value] ?? '';
                if ($label !== '' && $reading === '') {
                    // A line with a name and no value is the heading for the values under it.
                    $test = $label;

                    continue;
                }
                $draft['results'][] = ['test' => $test, 'parameter' => $label, 'value' => $reading, 'unit' => $unit !== null ? ($row[$unit] ?? '') : '', 'reference_range' => $range !== null ? ($row[$range] ?? '') : ''];
            }
        } elseif ($medicine !== null) {
            $dose = $column('/dose|strength/u');
            $frequency = $column('/freq|dosage|schedule|when|times/u');
            $duration = $column('/duration|days|period/u');
            $instructions = $column('/instruction|remark|note|advice/u');
            foreach ($table as $row) {
                $draft['medicines'][] = [
                    'name' => $row[$medicine] ?? '', 'dose' => $dose !== null ? ($row[$dose] ?? '') : '', 'frequency' => $frequency !== null ? ($row[$frequency] ?? '') : '',
                    'duration' => $duration !== null ? ($row[$duration] ?? '') : '', 'instructions' => $instructions !== null ? ($row[$instructions] ?? '') : '',
                ];
            }
        }
    }

    /** The first date written anywhere in a piece of text, read the Indian way: day first. */
    private static function dateIn(string $text): ?Carbon
    {
        $patterns = [
            '/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/u',
            '/\b(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4}|\d{2})\b/u',
            '/\b(\d{1,2})(?:st|nd|rd|th)?[\s\-.,]+([A-Za-z]{3,9})[\s\-.,]+(\d{4}|\d{2})\b/u',
            '/\b([A-Za-z]{3,9})[\s.]+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})\b/u',
        ];
        $best = null;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE) && ($date = self::date($m[0][0])) && ($best === null || $m[0][1] < $best[0])) {
                $best = [$m[0][1], $date];
            }
        }

        return $best[1] ?? null;
    }

    /** One date however it is written, or nothing if it is not a real day between 1950 and today. */
    public static function date(string $value): ?Carbon
    {
        $value = trim($value);
        $year = fn (string $y) => strlen($y) === 2 ? (int) ((int) $y > (int) date('y') ? '19'.$y : '20'.$y) : (int) $y;
        $parts = null;

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $m)) {
            $parts = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4}|\d{2})$/', $value, $m)) {
            $parts = [$year($m[3]), (int) $m[2], (int) $m[1]];
        } elseif (preg_match('/^(\d{1,2})(?:st|nd|rd|th)?[\s\-.,]+([A-Za-z]{3})[A-Za-z]*[\s\-.,]+(\d{4}|\d{2})$/', $value, $m) && isset(self::MONTHS[strtolower($m[2])])) {
            $parts = [$year($m[3]), self::MONTHS[strtolower($m[2])], (int) $m[1]];
        } elseif (preg_match('/^([A-Za-z]{3})[A-Za-z]*[\s.]+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})$/', $value, $m) && isset(self::MONTHS[strtolower($m[1])])) {
            $parts = [(int) $m[3], self::MONTHS[strtolower($m[1])], (int) $m[2]];
        }
        if (! $parts || ! checkdate($parts[1], $parts[2], $parts[0])) {
            return null;
        }

        try {
            $date = Carbon::create($parts[0], $parts[1], $parts[2])->startOfDay();
        } catch (Throwable) {
            return null;
        }

        return $date->year >= 1950 && $date->lte(today()) ? $date : null;
    }
}
