<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The ledger was started a few minutes before the screens began writing to it.
     * Anything sold or received in that gap is brought into the starting figure once, here,
     * so those batches are not shown as records that do not add up.
     */
    public function up(): void
    {
        $now = now();
        $ledger = DB::table('stock_movements')->selectRaw('stock_batch_id, sum(units) as units')->groupBy('stock_batch_id')->pluck('units', 'stock_batch_id');

        DB::table('stock_batches')->orderBy('id')->chunkById(500, function ($batches) use ($ledger, $now) {
            $rows = [];
            foreach ($batches as $batch) {
                $gap = (int) $batch->units_in_stock - (int) ($ledger[$batch->id] ?? 0);
                if ($gap !== 0) {
                    $rows[] = [
                        'stock_batch_id' => $batch->id, 'medicine_id' => $batch->medicine_id, 'kind' => 'baseline', 'units' => $gap,
                        'balance_after' => $batch->units_in_stock, 'note' => 'Sold or received while the stock record was being switched on',
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
            if ($rows) {
                DB::table('stock_movements')->insert($rows);
            }
        });
    }

    public function down(): void
    {
        // Nothing to undo: the lines only record what the stock already was.
    }
};
