<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A doctor's share of the money collected on their own patients' bills, as a percentage for each counter.
 * Empty until the hospital sets it. Structure only: no existing row is changed.
 */
return new class extends Migration
{
    private const COLUMNS = ['share_front_desk', 'share_lab', 'share_ipd'];

    public function up(): void
    {
        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn('users', $column)) {
                Schema::table('users', fn (Blueprint $table) => $table->decimal($column, 5, 2)->nullable());
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(self::COLUMNS));
    }
};
