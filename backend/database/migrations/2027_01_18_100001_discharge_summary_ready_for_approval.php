<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A discharge summary handed to the doctor for approval: when, and by whom. The doctor of the case is told once, at
 * that moment, rather than every time the summary is saved while it is still being written. Withdrawing an approval
 * clears it, so the summary can be handed over again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('discharge_summaries', 'ready_at')) {
            Schema::table('discharge_summaries', function (Blueprint $table) {
                $table->timestamp('ready_at')->nullable();
                $table->unsignedBigInteger('ready_by')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('discharge_summaries', 'ready_at')) {
            Schema::table('discharge_summaries', function (Blueprint $table) {
                $table->dropColumn(['ready_at', 'ready_by']);
            });
        }
    }
};
