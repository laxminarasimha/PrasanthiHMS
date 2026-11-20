<?php

namespace App\Support;

use App\Models\Medicine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The national drug list: Common Drug Codes for India (CDCI) from NRCeS, C-DAC, under CC BY 4.0. These are the codes the
 * ABDM Drug Registry uses. It is loaded whole from the government's flat files, and a medicine of ours is linked to one
 * of its brands only when a member of staff picks it: the list suggests, a person decides.
 */
class DrugReference
{
    /** The international (US) names the list uses for some drugs, and what is written in India. */
    private const INDIAN_NAMES = ['acetaminophen' => 'Paracetamol', 'albuterol' => 'Salbutamol'];

    /** Words in a pharmacy's own medicine name that say what form it is, and the words the national list uses for it. */
    private const FORMS = [
        'tab' => 'tablet', 'tabs' => 'tablet', 'tablet' => 'tablet', 'tablets' => 'tablet', 'cap' => 'capsule', 'caps' => 'capsule', 'capsule' => 'capsule', 'capsules' => 'capsule',
        'syp' => 'syrup', 'syrup' => 'syrup', 'susp' => 'suspension', 'suspension' => 'suspension', 'inj' => 'injection', 'injection' => 'injection', 'drops' => 'drops', 'drop' => 'drops',
        'oint' => 'ointment', 'ointment' => 'ointment', 'cream' => 'cream', 'gel' => 'gel', 'lotion' => 'lotion', 'sachet' => 'sachet', 'spray' => 'spray', 'inhaler' => 'inhal',
        'respules' => 'inhal', 'powder' => 'powder', 'soln' => 'solution', 'solution' => 'solution', 'infusion' => 'infusion',
    ];

    /**
     * Loads the seven flat files from a folder into a database, adding new entries and bringing changed ones up to date.
     * Nothing is ever removed, so a medicine linked to an entry a later release drops keeps its link.
     *
     * @return array<string, int> how many of each were read
     */
    public static function load(string $folder, ?string $connection = null): array
    {
        $read = fn (string $file) => self::rows($folder.'/'.$file);
        $forms = collect($read('DrugFormMaster.txt'))->mapWithKeys(fn ($r) => [$r[0] => $r[1] ?? null]);
        $routes = collect($read('RouteOfAdministrationMaster.txt'))->mapWithKeys(fn ($r) => [$r[0] => $r[1] ?? null]);
        $products = collect($read('ProductMaster.txt'))->mapWithKeys(fn ($r) => [$r[0] => $r[1] ?? null]);
        $suppliers = collect($read('SupplierMaster.txt'))->mapWithKeys(fn ($r) => [$r[0] => $r[1] ?? null]);
        $text = fn (?string $value, int $max = 65000) => ($value = self::repair(trim((string) $value))) === '' ? null : mb_substr($value, 0, $max);
        $date = fn (?string $value) => preg_match('/^(\d{4})(\d{2})(\d{2})$/', trim((string) $value), $m) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;

        $tables = [
            'drug_ref_substances' => ['SubstanceMaster.txt', fn ($r) => ['code' => $r[0], 'name' => mb_substr(trim($r[1] ?? ''), 0, 255)]],
            'drug_ref_generics' => ['GenericMaster.txt', fn ($r) => [
                'code' => $r[0], 'name' => mb_substr(self::repair(trim($r[1] ?? '')), 0, 400), 'substance_codes' => $text($r[2] ?? null, 255),
                'form' => $text($forms[$r[4] ?? ''] ?? null, 150),
                'route' => $text(collect(explode('+', $r[3] ?? ''))->map(fn ($c) => $routes[$c] ?? null)->filter()->implode(', '), 150),
                'therapeutic_role' => $text($r[5] ?? null), 'indication' => $text($r[6] ?? null), 'contra_indication' => $text($r[7] ?? null), 'interactions' => $text($r[8] ?? null),
                'updated_on' => $date($r[11] ?? null),
            ]],
            'drug_ref_brands' => ['BrandMaster.txt', fn ($r) => [
                'code' => $r[0], 'name' => mb_substr(self::repair(trim($r[1] ?? '')), 0, 255), 'product_name' => $text($products[$r[2] ?? ''] ?? null, 150),
                'generic_code' => $text($r[4] ?? null, 20), 'supplier' => $text($suppliers[$r[3] ?? ''] ?? null, 255), 'updated_on' => $date($r[8] ?? null),
            ]],
        ];

        // A cut-short or wrong file is refused before anything is written.
        foreach (['GenericMaster.txt' => 1000, 'BrandMaster.txt' => 1000] as $file => $least) {
            $count = iterator_count(self::stream($folder.'/'.$file));
            if ($count < $least) {
                throw new RuntimeException("{$file} holds only {$count} entries, which is not a full national list. Nothing was loaded.");
            }
        }

        $counts = [];
        $db = DB::connection($connection);
        $db->transaction(function () use ($db, $tables, $folder, &$counts) {
            foreach ($tables as $table => [$file, $shape]) {
                $counts[$table] = 0;
                $chunk = [];
                // Read and written 500 at a time, so a large file never has to fit in memory at once.
                foreach (self::stream($folder.'/'.$file) as $row) {
                    $chunk[] = $shape($row);
                    if (count($chunk) === 500) {
                        $db->table($table)->upsert($chunk, ['code'], array_values(array_diff(array_keys($chunk[0]), ['code'])));
                        $counts[$table] += 500;
                        $chunk = [];
                    }
                }
                if ($chunk) {
                    $db->table($table)->upsert($chunk, ['code'], array_values(array_diff(array_keys($chunk[0]), ['code'])));
                    $counts[$table] += count($chunk);
                }
            }
        });

        return ['substances' => $counts['drug_ref_substances'], 'generics' => $counts['drug_ref_generics'], 'brands' => $counts['drug_ref_brands']];
    }

    /**
     * Mends text that was turned into UTF-8 twice somewhere upstream: "lactamaseâ€“negative" back to "lactamase–negative".
     * Left as it is when the mending would not give clean text.
     */
    public static function repair(string $value): string
    {
        // Some text in the files was turned twice, so it may need mending twice.
        for ($round = 0; $round < 2 && preg_match('/[ÃÂâ][\x{0080}-\x{00BF}\x{0152}-\x{2122}]/u', $value); $round++) {
            $mended = mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
            if (! mb_check_encoding($mended, 'UTF-8') || substr_count($mended, '?') > substr_count($value, '?')) {
                break;
            }
            $value = $mended;
        }

        // Where the whole text could not be mended (it also holds signs the old code page lacks), the usual pieces are.
        return strtr($value, ['â€“' => '–', 'â€”' => '—', 'â€™' => '’', 'â€˜' => '‘', 'â€œ' => '“', 'â€¢' => '•', 'â€¦' => '…', 'â‰¥' => '≥', 'â‰¤' => '≤', 'Â°' => '°', 'Â±' => '±', 'Âµ' => 'µ', 'Ã©' => 'é', 'Â ' => ' ']);
    }

    /**
     * The rows of one small tab-separated file, without its heading line.
     *
     * @return list<list<string>>
     */
    private static function rows(string $path): array
    {
        return iterator_to_array(self::stream($path), false);
    }

    /**
     * The rows of a tab-separated file one at a time, without its heading line. Only lines that start with a code count.
     *
     * @return \Generator<int, list<string>>
     */
    private static function stream(string $path): \Generator
    {
        $handle = is_file($path) ? fopen($path, 'r') : false;
        if (! $handle) {
            throw new RuntimeException('Missing from the package: '.basename($path));
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim((string) preg_replace('/^\xEF\xBB\xBF/', '', $line), "\r\n");
                if (preg_match('/^\d+\t/', $line)) {
                    yield explode("\t", $line);
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * What a generic contains, written the way our compositions are: "Amoxicillin 250 mg + Clavulanic acid 125 mg" from
     * "Amoxicillin 250 mg and clavulanic acid (as clavulanate potassium) 125 mg oral tablet". Null when it cannot be read.
     */
    public static function composition(string $generic, ?string $form): ?string
    {
        $text = (string) preg_replace('/\s*\((?:as|equivalent to)\b[^)]*\)/i', '', $generic);
        if ($form && ($at = mb_stripos($text, ' '.$form)) !== false) {
            $text = mb_substr($text, 0, $at);
        } elseif (preg_match('/^(.*\d[\d.,]*\s*[a-zµ%]+(?:\s*\/\s*[\d.,]*\s*[a-z]+)?)/iu', $text, $m)) {
            $text = $m[1];
        }
        $text = (string) preg_replace(['/\bmicrograms?\b/i', '/\bmillion units?\b/i', '/\bmilliliters?\b/i'], ['mcg', 'million units', 'ml'], $text);

        $parts = [];
        foreach (preg_split('/\s+and\s+|\s*\+\s*/i', trim($text)) ?: [] as $part) {
            $part = trim($part);
            if ($part === '' || ! preg_match('/[a-z]/i', $part)) {
                return null;
            }
            $first = mb_strtolower((string) strtok($part, ' '));
            if (isset(self::INDIAN_NAMES[$first])) {
                $part = self::INDIAN_NAMES[$first].mb_substr($part, mb_strlen($first));
            }
            $parts[] = mb_strtoupper(mb_substr($part, 0, 1)).mb_substr($part, 1);
        }

        return $parts ? mb_substr(implode(' + ', $parts), 0, 255) : null;
    }

    /**
     * Brands from the list as the screens show them: each with its generic, what it contains and who makes it.
     *
     * @param  Collection<int, object>  $brands
     * @return Collection<int, array<string, mixed>>
     */
    public static function present(Collection $brands): Collection
    {
        $generics = DB::table('drug_ref_generics')->whereIn('code', $brands->pluck('generic_code')->filter()->unique())->get(['code', 'name', 'form', 'route', 'indication', 'contra_indication', 'interactions'])->keyBy('code');

        return $brands->map(function ($brand) use ($generics) {
            $generic = $generics->get($brand->generic_code);

            return [
                'code' => $brand->code, 'name' => $brand->name, 'product_name' => $brand->product_name, 'supplier' => $brand->supplier,
                'generic' => $generic ? ['code' => $generic->code, 'name' => $generic->name, 'form' => $generic->form, 'route' => $generic->route] : null,
                'composition' => $generic ? self::composition($generic->name, $generic->form) : null,
                'score' => $brand->score ?? null,
            ];
        })->values();
    }

    /**
     * The brands on the list most likely to be this medicine, best first: same name on the pack, same strength, same form,
     * same maker. Suggestions only; staff choose.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function suggest(Medicine $medicine, int $limit = 6): Collection
    {
        $words = self::words($medicine->name);
        $units = ['mg', 'mcg', 'ml', 'gm', 'g', 'iu', 'kg', 'ltr', 'l', 'w', 'v'];
        $letters = array_values(array_filter($words, fn ($w) => preg_match('/^[a-z]/', $w) && ! isset(self::FORMS[$w]) && ! in_array($w, $units, true)));
        if (! $letters) {
            return collect();
        }
        $escape = fn (string $s) => str_replace(['%', '_'], ['\%', '\_'], $s);
        $stem = mb_strlen($letters[0]) >= 3 || ! isset($letters[1]) ? $letters[0] : $letters[0].' '.$letters[1];
        // The name as written up to its first number, so "R-CIN 600" is looked for as "R-Cin" too.
        $written = trim((string) preg_replace('/\s*\d.*$/', '', mb_strtolower(trim($medicine->name))), ' -');
        $candidates = DB::table('drug_ref_brands')
            ->where(fn ($q) => $q->where('product_name', 'like', $escape($stem).'%')->when(mb_strlen($written) >= 3 && $written !== $stem, fn ($q) => $q->orWhere('product_name', 'like', $escape($written).'%')))
            ->limit(400)->get();

        $numbers = self::numbers($medicine->name);
        $form = collect($words)->map(fn ($w) => self::FORMS[$w] ?? null)->filter()->first() ?? self::FORMS[mb_strtolower(strtok((string) $medicine->type, ' /'))] ?? null;
        $maker = collect(self::words((string) $medicine->manufacturer))->first(fn ($w) => mb_strlen($w) >= 4 && ! in_array($w, ['pharma', 'pharmaceuticals', 'limited', 'private', 'laboratories', 'healthcare'], true));

        return self::present($candidates->map(function ($brand) use ($words, $letters, $numbers, $form, $maker) {
            $product = self::words((string) $brand->product_name);
            $score = count(array_diff($product, $words)) === 0 ? 3 + count($product) : 0;
            // A word of ours the pack name lacks is usually a variant: "Montair LC" is not plain "Montair".
            $score -= 2 * count(array_diff($letters, $product));
            $theirs = self::numbers($brand->name);
            // "Augmentin 625" is 500 mg + 125 mg.
            $total = (string) array_sum(array_map('floatval', $theirs));
            foreach ($numbers as $n) {
                $score += in_array($n, $theirs, true) || ($n === $total && count($theirs) > 1) ? 3 : -1;
            }
            if ($numbers) {
                $score -= count(array_diff($theirs, $numbers));
            }
            $name = mb_strtolower($brand->name);
            if ($form && str_contains($name, $form)) {
                $score += 2;
            }
            if ($maker && str_contains(mb_strtolower((string) $brand->supplier), $maker)) {
                $score += 2;
            }
            $brand->score = $score;

            return $brand;
        })->filter(fn ($brand) => $brand->score > 0)->sortByDesc('score')->take($limit)->values());
    }

    /**
     * Brands whose name has what was typed, for when the suggestions are not right.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function search(string $term, int $limit = 25): Collection
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return collect();
        }
        $like = str_replace(['%', '_'], ['\%', '\_'], $term);

        return self::present(DB::table('drug_ref_brands')->where('name', 'like', $like.'%')->orWhere('product_name', 'like', $like.'%')->orderBy('name')->limit($limit)->get());
    }

    /**
     * Generics this medicine may be, for one named by what it contains ("Amoxicillin 500mg Capsule") rather than a brand:
     * the drug named first, the same strength, the same form.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function suggestGenerics(Medicine $medicine, int $limit = 4): Collection
    {
        $words = self::words($medicine->name);
        $first = collect($words)->first(fn ($w) => preg_match('/^[a-z]{4,}$/', $w) && ! isset(self::FORMS[$w]));
        if (! $first) {
            return collect();
        }
        $numbers = self::numbers($medicine->name);
        $form = collect($words)->map(fn ($w) => self::FORMS[$w] ?? null)->filter()->first();
        // The list names paracetamol and salbutamol by their international names.
        $looked = array_search(ucfirst($first), self::INDIAN_NAMES, true) ?: $first;

        return DB::table('drug_ref_generics')->where('name', 'like', str_replace(['%', '_'], ['\%', '\_'], $looked).'%')->limit(300)->get(['code', 'name', 'form', 'route'])
            ->map(function ($g) use ($numbers, $form) {
                $theirs = self::numbers($g->name);
                $score = 1 + 3 * count(array_intersect($numbers, $theirs)) - count(array_diff($theirs, $numbers));
                $score += $form && str_contains(mb_strtolower($g->name), $form) ? 2 : 0;
                $g->score = $score;

                return $g;
            })
            ->filter(fn ($g) => ! $numbers || $g->score > 2)->sortBy(fn ($g) => -$g->score * 1000 + mb_strlen($g->name))->take($limit)->values()
            ->map(fn ($g) => ['code' => $g->code, 'name' => $g->name, 'form' => $g->form, 'route' => $g->route, 'composition' => self::composition($g->name, $g->form)]);
    }

    /**
     * Generics whose name has every word typed ("azithromycin 500 injection"), for a medicine whose own brand is not on
     * the list: it can still be linked to what it is.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function searchGenerics(string $term, int $limit = 25): Collection
    {
        $words = array_filter(preg_split('/\s+/', mb_strtolower(trim($term))) ?: [], fn ($w) => mb_strlen($w) >= 2);
        if (! $words) {
            return collect();
        }
        $query = DB::table('drug_ref_generics');
        foreach ($words as $word) {
            $query->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $word).'%');
        }

        return $query->orderByRaw('LENGTH(name)')->limit($limit)->get(['code', 'name', 'form', 'route'])
            ->map(fn ($g) => ['code' => $g->code, 'name' => $g->name, 'form' => $g->form, 'route' => $g->route, 'composition' => self::composition($g->name, $g->form)]);
    }

    /** @return list<string> a name in lower case, cut into words and numbers ("DOLO-650 TAB" gives dolo, 650, tab) */
    private static function words(string $name): array
    {
        return array_values(array_filter(preg_split('/[^a-z0-9.]+|(?<=[a-z])(?=\d)|(?<=\d)(?=[a-z])/', mb_strtolower($name)) ?: [], fn ($w) => $w !== '' && $w !== '.'));
    }

    /** @return list<string> the numbers in a name, "0.5" and "500" as written, without trailing zeros after a point */
    private static function numbers(string $name): array
    {
        preg_match_all('/\d+(?:\.\d+)?/', $name, $m);

        return array_values(array_unique(array_map(fn ($n) => str_contains($n, '.') ? rtrim(rtrim($n, '0'), '.') : $n, $m[0])));
    }
}
