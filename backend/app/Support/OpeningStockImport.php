<?php

namespace App\Support;

use App\Models\Medicine;
use App\Models\StockBatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Opening stock from a sheet. Every line is checked before anything is added: a line that cannot
 * be right stops the whole sheet, a line that merely looks odd is pointed out, and the totals are
 * shown so they can be compared with the pharmacy's own count before the stock goes live.
 */
class OpeningStockImport
{
    public const MAX_LINES = 3000;

    private const USUAL_GST = [0.0, 5.0, 12.0, 18.0, 28.0];

    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    /**
     * Reads the sheet and says what is wrong or odd on each line.
     *
     * @param  list<array<string, mixed>>  $rows  as typed in the sheet: medicine, batch_no, expiry, units_per_pack, quantity, cost_price, mrp, gst_percent, hsn_code, rack_no, box_no
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int|float>}
     */
    public static function check(array $rows, bool $addMissing): array
    {
        $medicines = Medicine::get(['id', 'name', 'units_per_pack', 'gst_percent', 'hsn_code'])->keyBy(fn (Medicine $m) => self::key($m->name));
        $inStock = StockBatch::where('units_in_stock', '>', 0)->get(['medicine_id', 'batch_no'])
            ->mapWithKeys(fn (StockBatch $b) => [$b->medicine_id.'|'.self::key($b->batch_no) => true]);
        $seen = [];
        $soon = today()->addDays(90);

        $checked = [];
        foreach (array_values($rows) as $index => $row) {
            $text = fn (string $field) => trim((string) (is_scalar($row[$field] ?? null) ? $row[$field] : ''));
            $number = fn (string $field) => self::number($text($field));
            $errors = [];
            $warnings = [];

            $name = preg_replace('/\s+/', ' ', $text('medicine'));
            $medicine = $name !== '' ? $medicines->get(self::key($name)) : null;
            if ($name === '') {
                $errors[] = 'The medicine name is missing.';
            } elseif (! $medicine && ! $addMissing) {
                $errors[] = 'This medicine is not in the medicine list. Correct the name, add the medicine, or tick "Add medicines that are not in the list".';
            } elseif (! $medicine) {
                $warnings[] = 'Not in the medicine list: it will be added.';
            }

            $batch = $text('batch_no');
            if ($batch === '') {
                $errors[] = 'The batch number is missing.';
            } elseif (mb_strlen($batch) > 50) {
                $errors[] = 'The batch number is too long.';
            }

            $expiry = self::expiry($text('expiry'));
            if (! $expiry) {
                $errors[] = $text('expiry') === '' ? 'The expiry is missing.' : 'The expiry "'.$text('expiry').'" cannot be read. Write it as 11/2027 or Nov-2027.';
            } elseif ($expiry->lt(today())) {
                $errors[] = 'This batch has already expired ('.$expiry->format('M Y').').';
            } elseif ($expiry->lte($soon)) {
                $warnings[] = 'Expires within three months ('.$expiry->format('M Y').').';
            } elseif ($expiry->gt(today()->addYears(8))) {
                $errors[] = 'The expiry ('.$expiry->format('M Y').') is too far away to be right.';
            }

            $perPack = $text('units_per_pack') === '' ? $medicine?->units_per_pack : $number('units_per_pack');
            if ($perPack === null || $perPack < 1 || $perPack > 10000 || floor((float) $perPack) != $perPack) {
                $errors[] = 'Units in a pack must be a whole number of 1 or more.';
                $perPack = null;
            } elseif ($medicine && (int) $perPack !== (int) $medicine->units_per_pack) {
                $warnings[] = "The medicine list says a pack holds {$medicine->units_per_pack}; the sheet says ".(int) $perPack.'.';
            }

            $quantity = $number('quantity');
            if ($quantity === null || $quantity < 1 || floor($quantity) != $quantity) {
                $errors[] = 'The count on the shelf must be a whole number of units, 1 or more.';
                $quantity = null;
            } elseif ($quantity > 1000000) {
                $errors[] = 'The count on the shelf is too large to be right.';
                $quantity = null;
            }

            $mrp = $number('mrp');
            if ($mrp === null || $mrp <= 0) {
                $errors[] = 'The MRP per pack must be more than zero.';
                $mrp = null;
            }
            $cost = $number('cost_price');
            if ($cost === null || $cost < 0) {
                $errors[] = 'The purchase rate per pack is missing or not a number.';
                $cost = null;
            } elseif ($mrp !== null && $cost > $mrp) {
                $errors[] = 'The purchase rate is more than the MRP.';
            } elseif ($cost == 0.0) {
                $warnings[] = 'The purchase rate is zero, so this stock will show no cost.';
            }

            $gst = $text('gst_percent') === '' ? ($medicine ? (float) $medicine->gst_percent : null) : $number('gst_percent');
            if ($gst === null || $gst < 0 || $gst > 40) {
                $errors[] = 'GST % must be between 0 and 40.';
                $gst = null;
            } elseif (! in_array((float) $gst, self::USUAL_GST, true)) {
                $warnings[] = "GST of {$gst}% is unusual.";
            }

            // The same batch twice would double the stock, whether twice in the sheet or once here and once already on the shelf.
            $same = self::key($name).'|'.self::key($batch);
            if ($name !== '' && $batch !== '') {
                if (isset($seen[$same])) {
                    // Counted as the sheet shows it, where the headings are row 1.
                    $errors[] = 'The same medicine and batch is also on row '.($seen[$same] + 1).'.';
                } else {
                    $seen[$same] = $index + 1;
                }
                if ($medicine && isset($inStock[$medicine->id.'|'.self::key($batch)])) {
                    $errors[] = 'This batch is already in stock. Remove the line; to correct its count use Stock by batch.';
                }
            }

            $ready = $perPack && $quantity && $mrp !== null && $cost !== null;
            $checked[] = [
                'line' => $index + 1,
                'medicine' => $medicine?->name ?? $name,
                'medicine_id' => $medicine?->id,
                'batch_no' => $batch,
                'expiry' => $expiry?->toDateString(),
                'units_per_pack' => $perPack ? (int) $perPack : null,
                'quantity' => $quantity ? (int) $quantity : null,
                'mrp' => $mrp, 'cost_price' => $cost, 'gst_percent' => $gst,
                'hsn_code' => mb_substr($text('hsn_code') ?: (string) $medicine?->hsn_code, 0, 20) ?: null,
                'rack_no' => mb_substr($text('rack_no'), 0, 30) ?: null,
                'box_no' => mb_substr($text('box_no'), 0, 30) ?: null,
                'at_cost' => $ready ? round($cost / $perPack * $quantity, 2) : 0,
                'at_mrp' => $ready ? round($mrp / $perPack * $quantity, 2) : 0,
                'errors' => $errors, 'warnings' => $warnings,
            ];
        }

        $rows = collect($checked);

        return ['rows' => $checked, 'totals' => [
            'lines' => $rows->count(),
            'with_errors' => $rows->filter(fn ($r) => $r['errors'])->count(),
            'with_warnings' => $rows->filter(fn ($r) => ! $r['errors'] && $r['warnings'])->count(),
            'new_medicines' => $rows->filter(fn ($r) => ! $r['errors'] && ! $r['medicine_id'])->unique(fn ($r) => self::key($r['medicine']))->count(),
            'units' => (int) $rows->sum('quantity'),
            'at_cost' => round((float) $rows->sum('at_cost'), 2),
            'at_mrp' => round((float) $rows->sum('at_mrp'), 2),
        ]];
    }

    /**
     * Puts a checked sheet with no errors on the shelf, all of it or none of it.
     *
     * @param  list<array<string, mixed>>  $checked  the rows as returned by check()
     */
    public static function save(array $checked): int
    {
        return DB::transaction(function () use ($checked) {
            $made = [];
            foreach ($checked as $row) {
                $medicineId = $row['medicine_id'] ?? ($made[self::key($row['medicine'])] ??= Medicine::create([
                    'name' => $row['medicine'], 'units_per_pack' => $row['units_per_pack'], 'gst_percent' => $row['gst_percent'],
                    'hsn_code' => $row['hsn_code'], 'reorder_level' => 0, 'is_active' => true,
                ])->id);

                // Quantity is in single units; MRP and cost are per pack, as printed on the strip and the bill.
                StockBatch::create([
                    'medicine_id' => $medicineId, 'batch_no' => $row['batch_no'], 'expiry' => $row['expiry'],
                    'units_per_pack' => $row['units_per_pack'], 'packs' => (int) ceil($row['quantity'] / $row['units_per_pack']),
                    'pack_price' => $row['cost_price'], 'pack_mrp' => $row['mrp'], 'unit_mrp' => round($row['mrp'] / $row['units_per_pack'], 2),
                    'gst_percent' => $row['gst_percent'], 'units_received' => $row['quantity'], 'units_in_stock' => $row['quantity'],
                    'rack_no' => $row['rack_no'], 'box_no' => $row['box_no'], 'hsn_code' => $row['hsn_code'],
                ]);
            }

            return count($checked);
        });
    }

    private static function key(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/', ' ', trim($value)));
    }

    /** A number as people type it in a sheet: "1,250.50", "₹ 45", "12%". */
    private static function number(string $value): ?float
    {
        $clean = preg_replace('/[,\s₹%]|rs\.?/i', '', $value);

        return $clean !== '' && is_numeric($clean) ? (float) $clean : null;
    }

    /** The last day of the month an expiry names, however it is written: 2027-11, 11/2027, 11/27, Nov-27, Nov 2027, 30/11/2027. */
    private static function expiry(string $value): ?Carbon
    {
        $value = mb_strtolower(trim($value));
        $year = fn (string $y) => (int) (strlen($y) === 2 ? '20'.$y : $y);
        $month = null;
        $yyyy = null;

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})(?:[-\/.]\d{1,2})?$/', $value, $m)) {
            [$yyyy, $month] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/^(?:\d{1,2}[-\/.])?(\d{1,2})[-\/.](\d{4}|\d{2})$/', $value, $m)) {
            [$month, $yyyy] = [(int) $m[1], $year($m[2])];
        } elseif (preg_match('/^(?:\d{1,2}[-\/. ])?([a-z]{3})[a-z]*[-\/. ,]*(\d{4}|\d{2})$/', $value, $m) && isset(self::MONTHS[$m[1]])) {
            [$month, $yyyy] = [self::MONTHS[$m[1]], $year($m[2])];
        }
        if (! $month || $month > 12 || ! $yyyy || $yyyy < 2000 || $yyyy > 2100) {
            return null;
        }

        try {
            return Carbon::create($yyyy, $month, 1)->endOfMonth()->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }
}
