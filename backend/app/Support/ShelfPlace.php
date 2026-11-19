<?php

namespace App\Support;

use App\Models\Medicine;
use App\Models\StockBatch;

/**
 * Where medicines are kept: a rack, and a box on it. The place belongs to the medicine, so it is set once and every
 * screen that shows the medicine can say where to find it. Each batch on the shelf carries the same place, which is
 * what the counter, the stock list and the stock counts read; a batch deliberately kept somewhere else keeps its own.
 */
class ShelfPlace
{
    /** A place as typed, tidied: no stray spaces, and nothing rather than an empty string. */
    public static function tidy(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $value));

        return $value === '' ? null : mb_substr($value, 0, 30);
    }

    /**
     * Puts a medicine in a rack and box, and its stock with it. Batches that were at the medicine's old place, or
     * had no place, follow; a batch someone set apart on the stock screen is left where it is.
     *
     * @return int how many batches on the shelf moved with it
     */
    public static function set(Medicine $medicine, ?string $rack, ?string $box): int
    {
        $rack = self::tidy($rack);
        // A box without a rack says nothing about where to look.
        $box = $rack === null ? null : self::tidy($box);
        [$oldRack, $oldBox] = [$medicine->rack_no, $medicine->box_no];
        if ($oldRack === $rack && $oldBox === $box) {
            return 0;
        }
        $medicine->forceFill(['rack_no' => $rack, 'box_no' => $box])->save();

        $same = fn ($q, string $column, ?string $old) => $q->where(fn ($w) => $w->whereNull($column)->orWhere($column, '')->when($old !== null, fn ($x) => $x->orWhere($column, $old)));

        return StockBatch::where('medicine_id', $medicine->id)->where('units_in_stock', '>', 0)
            ->where(fn ($q) => $same($q, 'rack_no', $oldRack))->where(fn ($q) => $same($q, 'box_no', $oldBox))
            ->update(['rack_no' => $rack, 'box_no' => $box]);
    }

    /** A place in words, as the screens and the change record say it. */
    public static function label(?string $rack, ?string $box): string
    {
        return $rack === null || $rack === '' ? 'no place' : "Rack {$rack}".($box !== null && $box !== '' ? ", box {$box}" : '');
    }

    /**
     * New stock arriving. With no place typed it goes where the medicine is kept; a place typed for a medicine
     * that has none yet becomes that medicine's place, so it is only ever typed once.
     */
    public static function arriving(StockBatch $batch): void
    {
        $batch->rack_no = self::tidy($batch->rack_no);
        $batch->box_no = self::tidy($batch->box_no);
        $medicine = Medicine::find($batch->medicine_id);
        if (! $medicine) {
            return;
        }
        if ($batch->rack_no === null) {
            $batch->rack_no = $medicine->rack_no;
            $batch->box_no = $medicine->rack_no === null ? null : $medicine->box_no;
        } elseif ($medicine->rack_no === null) {
            $medicine->forceFill(['rack_no' => $batch->rack_no, 'box_no' => $batch->box_no])->save();
        }
    }
}
