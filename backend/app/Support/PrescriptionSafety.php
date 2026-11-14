<?php

namespace App\Support;

/**
 * Two plain checks made while prescribing: does a medicine match something the patient is
 * allergic to, and is the same medicine on the prescription twice. It knows a short list of
 * common drug families and nothing about doses or interactions between different drugs, so
 * it is a second pair of eyes, not a replacement for the doctor's own check.
 */
class PrescriptionSafety
{
    /** Common allergy words and the medicine names that belong to them. */
    private const FAMILIES = [
        'penicillin' => ['penicillin', 'amoxicillin', 'amoxycillin', 'ampicillin', 'cloxacillin', 'piperacillin', 'amoxyclav', 'augmentin', 'clavulan'],
        'cephalosporin' => ['cef', 'ceph'],
        'sulfa' => ['sulfa', 'sulpha', 'cotrimoxazole', 'co-trimoxazole', 'septran', 'bactrim', 'sulfasalazine'],
        'nsaid' => ['ibuprofen', 'diclofenac', 'aceclofenac', 'naproxen', 'ketorolac', 'piroxicam', 'etoricoxib', 'indomethacin', 'mefenamic', 'nimesulide', 'aspirin', 'brufen', 'voveran'],
        'aspirin' => ['aspirin', 'ecosprin', 'acetylsalicylic'],
        'quinolone' => ['floxacin'],
        'macrolide' => ['azithromycin', 'erythromycin', 'clarithromycin', 'azee', 'azithral'],
        'tetracycline' => ['tetracycline', 'doxycycline', 'minocycline'],
        'paracetamol' => ['paracetamol', 'acetaminophen', 'dolo', 'calpol', 'crocin'],
        'opioid' => ['tramadol', 'morphine', 'codeine', 'fentanyl', 'pentazocine'],
        'ace inhibitor' => ['enalapril', 'ramipril', 'lisinopril', 'captopril', 'perindopril'],
        'metformin' => ['metformin', 'glycomet'],
        'iodine' => ['iodine', 'povidone', 'betadine'],
    ];

    /** Other spellings of the family names, as people type them in the allergy box. */
    private const ALIASES = ['sulpha' => 'sulfa', 'sulphonamide' => 'sulfa', 'sulfonamide' => 'sulfa', 'nsaids' => 'nsaid', 'painkiller' => 'nsaid', 'pain killer' => 'nsaid', 'penicillins' => 'penicillin', 'cephalosporins' => 'cephalosporin', 'quinolones' => 'quinolone', 'fluoroquinolone' => 'quinolone'];

    private const NOISE = ['drug', 'drugs', 'tablet', 'tablets', 'tab', 'injection', 'inj', 'group', 'allergy', 'allergic', 'none', 'known', 'nil', 'no', 'not', 'and', 'the', 'to'];

    /**
     * @param  list<string>  $medicines  names as written on the prescription
     * @return list<array{kind: string, medicine: string, message: string}>
     */
    public static function warnings(?string $allergies, array $medicines): array
    {
        $medicines = array_values(array_filter(array_map(fn ($m) => trim((string) $m), $medicines)));
        $warnings = [];

        foreach (self::allergyTerms($allergies) as $allergy => $needles) {
            foreach ($medicines as $medicine) {
                $name = mb_strtolower($medicine);
                foreach ($needles as $needle) {
                    if (str_contains($name, $needle)) {
                        $warnings["a|{$allergy}|{$name}"] = ['kind' => 'allergy', 'medicine' => $medicine, 'message' => "The patient is recorded as allergic to {$allergy}. Check {$medicine} before giving it."];
                        break;
                    }
                }
            }
        }

        // The same medicine twice: same name, or the same first word (Paracetamol 500 and Paracetamol 650).
        $seen = [];
        foreach ($medicines as $medicine) {
            $key = self::firstWord($medicine);
            if ($key === '') {
                continue;
            }
            if (isset($seen[$key])) {
                $warnings["d|{$key}"] = ['kind' => 'duplicate', 'medicine' => $medicine, 'message' => mb_strtolower($seen[$key]) === mb_strtolower($medicine)
                    ? "{$seen[$key]} is on the prescription twice."
                    : "{$seen[$key]} and {$medicine} look like the same medicine. Check that both are meant."];
            } else {
                $seen[$key] = $medicine;
            }
        }

        return array_values($warnings);
    }

    /**
     * Breaks the allergy box into things to look for: each word typed, plus the members of any drug family named.
     *
     * @return array<string, list<string>>  what the patient is allergic to => text to look for in a medicine name
     */
    private static function allergyTerms(?string $allergies): array
    {
        $terms = [];
        foreach (preg_split('/[,;\/\n]+|\band\b/i', mb_strtolower((string) $allergies)) ?: [] as $part) {
            $part = trim(preg_replace('/[^a-z0-9 \-]/', ' ', $part));
            $words = array_values(array_filter(explode(' ', $part), fn ($w) => mb_strlen($w) >= 4 && ! in_array($w, self::NOISE, true)));
            if (! $words) {
                continue;
            }
            $label = implode(' ', $words);
            $needles = $words;
            foreach (array_merge([$label], $words) as $word) {
                $family = self::ALIASES[$word] ?? $word;
                if (isset(self::FAMILIES[$family])) {
                    $needles = array_merge($needles, self::FAMILIES[$family]);
                }
                // Naming one member of a family (amoxicillin) flags its relatives too.
                foreach (self::FAMILIES as $members) {
                    if (in_array($word, $members, true) && mb_strlen($word) >= 6) {
                        $needles = array_merge($needles, array_filter($members, fn ($m) => mb_strlen($m) >= 5));
                    }
                }
            }
            $terms[$label] = array_values(array_unique($needles));
        }

        return $terms;
    }

    private static function firstWord(string $medicine): string
    {
        $name = preg_replace('/^(tab|tablet|cap|capsule|syp|syrup|inj|injection|t|c)\.?\s+/i', '', mb_strtolower(trim($medicine)));
        $word = preg_split('/[\s\-\/]+/', (string) $name)[0] ?? '';

        return mb_strlen($word) >= 4 ? $word : (string) $name;
    }
}
