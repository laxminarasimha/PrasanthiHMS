<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The cash float. Not all the cash counted at the end of a day is handed over: some stays in the drawer for
 * change and small payments the next morning. What is kept is what the next day starts with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('day_closes', function (Blueprint $table) {
            $table->decimal('opening_cash', 12, 2)->default(0)->after('closed_at');
            $table->decimal('kept_cash', 12, 2)->default(0)->after('counted_cash');
        });
    }

    public function down(): void
    {
        Schema::table('day_closes', fn (Blueprint $table) => $table->dropColumn(['opening_cash', 'kept_cash']));
    }
};
