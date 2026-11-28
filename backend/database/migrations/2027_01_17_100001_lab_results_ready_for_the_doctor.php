<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an order's results are ready, the doctor who asked for them is told and has them on a list until they have
 * looked. `ready_tests` is how many tests were finished when the doctor was last told, so results that come later
 * (from an outside lab) are told again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('lab_orders', 'ready_at')) {
            Schema::table('lab_orders', function (Blueprint $table) {
                $table->timestamp('ready_at')->nullable();
                $table->unsignedBigInteger('ready_for')->nullable();
                $table->unsignedSmallInteger('ready_tests')->default(0);
                $table->timestamp('seen_at')->nullable();
                $table->index(['ready_for', 'seen_at']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('lab_orders', 'ready_at')) {
            Schema::table('lab_orders', function (Blueprint $table) {
                $table->dropIndex(['ready_for', 'seen_at']);
                $table->dropColumn(['ready_at', 'ready_for', 'ready_tests', 'seen_at']);
            });
        }
    }
};
