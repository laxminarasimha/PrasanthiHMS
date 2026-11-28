<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A pharmacy bill made from a visit's prescription card at the counter remembers that visit, so the prescription shows
 * as filled by that bill. Bills made before this, and bills typed without the card, are left empty: which bill filled
 * which prescription is not guessed for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('pharmacy_sales', 'consultation_id')) {
            Schema::table('pharmacy_sales', fn (Blueprint $table) => $table->unsignedBigInteger('consultation_id')->nullable()->index());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pharmacy_sales', 'consultation_id')) {
            Schema::table('pharmacy_sales', function (Blueprint $table) {
                $table->dropIndex(['consultation_id']);
                $table->dropColumn('consultation_id');
            });
        }
    }
};
