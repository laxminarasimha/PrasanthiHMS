<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A test done more than once on one lab order (a CBP repeated the same day): each time is its own
 * line with its own sample and report, numbered 1, 2, 3. A test done once has no number, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('lab_order_items', 'repeat_no')) {
            Schema::table('lab_order_items', fn (Blueprint $table) => $table->unsignedTinyInteger('repeat_no')->nullable()->after('test_name'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('lab_order_items', 'repeat_no')) {
            Schema::table('lab_order_items', fn (Blueprint $table) => $table->dropColumn('repeat_no'));
        }
    }
};
