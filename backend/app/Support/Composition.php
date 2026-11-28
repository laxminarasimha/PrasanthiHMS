<?php

namespace App\Support;

use App\Models\DrugIngredient;
use App\Models\Medicine;
use App\Models\RepProduct;

/**
 * What a medicine contains, as individual drugs. A combination is written with a plus sign
 * between its drugs: "Paracetamol 500 mg + Caffeine 30 mg". Each drug is kept as its own record
 * and linked to the medicine, so a search for one drug finds it alone and inside combinations.
 */
class Composition
{
    // Also the "per" words of the national drug list's strengths: "100 mcg/actuation", "4 mg/sachet", "1 g/vial".
    private const UNIT = '(?:mcg|µg|mg|g|kg|ml|l|iu|units?|%|meq|mmol|million\s+iu|million\s+units?|lakh\s+iu|billion\s+cfu|cfu|actuations?|doses?|vials?|sachets?)';

    /**
     * The drugs named in a composition as typed, each with its strength if one is written.
     *
     * @return list<array{name: string, strength: ?string}>
     */
    public static function parts(?string $text): array
    {
        $parts = [];
        foreach (preg_split('/\s*\+\s*|\s+&\s+|\s+and\s+/iu', (string) $text) ?: [] as $piece) {
            $piece = preg_replace('/\s+/u', ' ', trim($piece, " \t\n\r,;."));
            if ($piece === '' || $piece === null) {
                continue;
            }
            $strength = null;
            // The strength is the number-and-unit at the end: "650 mg", "5 mg/5 ml", "0.1 % w/w".
            if (preg_match('/^(.*?\D)\s*(\d[\d.,]*\s*'.self::UNIT.'(?:\s*\/\s*\d*[\d.,]*\s*'.self::UNIT.')?(?:\s*[wv]\/[wv])?)$/iu', $piece, $m) && trim($m[1]) !== '') {
                $piece = trim($m[1], " \t-");
                $strength = preg_replace('/\s+/', ' ', preg_replace('/(\d)\s*([a-zµ%])/iu', '$1 $2', strtolower(trim($m[2]))));
            }
            $key = mb_strtolower($piece);
            // The same drug written twice in one composition is one drug.
            if (! isset($parts[$key])) {
                $parts[$key] = ['name' => mb_substr($piece, 0, 150), 'strength' => $strength ? mb_substr($strength, 0, 60) : null];
            }
        }

        return array_values($parts);
    }

    /**
     * A composition written out the standard way, without saving anything: each drug under the
     * spelling already on record if there is one, strengths spaced, drugs in A to Z order.
     */
    public static function standard(?string $text): ?string
    {
        $named = [];
        foreach (self::parts($text) as $part) {
            $known = DrugIngredient::whereRaw('lower(name) = ?', [mb_strtolower($part['name'])])->value('name');
            $named[] = trim(($known ?? mb_strtoupper(mb_substr($part['name'], 0, 1)).mb_substr($part['name'], 1)).' '.($part['strength'] ?? ''));
        }
        sort($named, SORT_NATURAL | SORT_FLAG_CASE);

        return $named ? mb_substr(implode(' + ', $named), 0, 255) : null;
    }

    /**
     * Records what a medicine contains: links it to each of its drugs and writes the composition
     * out in one standard way, so the same composition typed two ways is still one composition.
     */
    public static function apply(Medicine $medicine, ?string $text): void
    {
        [$links, $composition] = self::resolve($text);
        $medicine->ingredients()->sync($links);
        $medicine->forceFill(['composition' => $composition])->saveQuietly();
    }

    /**
     * The same for a brand in a company's catalogue, so it can be set beside the pharmacy's own medicines by what
     * it contains. The composition is what the staff typed from the catalogue; nothing is worked out for them.
     */
    public static function applyToProduct(RepProduct $product, ?string $text): void
    {
        [$links, $composition] = self::resolve($text);
        $product->ingredients()->sync($links);
        $product->forceFill(['composition' => $composition])->saveQuietly();
    }

    /**
     * The drugs of a composition as links to their records (made when a drug is new), and the composition written
     * the standard way.
     *
     * @return array{0: array<int, array{strength: ?string, position: int}>, 1: ?string}
     */
    private static function resolve(?string $text): array
    {
        $links = [];
        $named = [];
        foreach (self::parts($text) as $part) {
            $drug = DrugIngredient::whereRaw('lower(name) = ?', [mb_strtolower($part['name'])])->first()
                ?? DrugIngredient::create(['name' => mb_strtoupper(mb_substr($part['name'], 0, 1)).mb_substr($part['name'], 1)]);
            $links[$drug->id] = ['strength' => $part['strength']];
            $named[] = trim($drug->name.' '.($part['strength'] ?? ''));
        }
        // In A to Z order, so "A + B" and "B + A" are the same composition.
        sort($named, SORT_NATURAL | SORT_FLAG_CASE);
        $position = 0;
        foreach (array_keys($links) as $id) {
            $links[$id]['position'] = $position++;
        }

        return [$links, $named ? mb_substr(implode(' + ', $named), 0, 255) : null];
    }
}
