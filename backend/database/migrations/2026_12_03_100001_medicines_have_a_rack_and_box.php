<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where each medicine is kept: its usual rack and box. Until now a place could only be typed on each batch as it
 * was bought, so most medicines had none and the counter could not be told where to look.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->string('rack_no', 30)->nullable()->index();
            $table->string('box_no', 30)->nullable();
        });

        // A place already typed on a batch that is still on the shelf is that medicine's place: the newest such batch decides.
        $placed = DB::table('stock_batches')->where('units_in_stock', '>', 0)->whereNotNull('rack_no')->where('rack_no', '!=', '')
            ->orderBy('id')->get(['medicine_id', 'rack_no', 'box_no'])->keyBy('medicine_id');
        foreach ($placed as $medicineId => $batch) {
            DB::table('medicines')->where('id', $medicineId)->update(['rack_no' => $batch->rack_no, 'box_no' => $batch->box_no ?: null]);
            // Its other stock on the shelf, entered without a place, is at the same place.
            DB::table('stock_batches')->where('medicine_id', $medicineId)->where('units_in_stock', '>', 0)
                ->where(fn ($q) => $q->whereNull('rack_no')->orWhere('rack_no', ''))
                ->update(['rack_no' => $batch->rack_no, 'box_no' => $batch->box_no ?: null]);
        }
    }

    public function down(): void
    {
        Schema::table('medicines', fn (Blueprint $table) => $table->dropColumn(['rack_no', 'box_no']));
    }
};
