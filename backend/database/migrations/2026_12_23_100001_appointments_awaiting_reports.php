<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A visit the doctor has paused to wait for lab reports: still on-going, but the patient is away at the lab rather
 * than with the doctor, so the doctor's list can say so and put them after those waiting to be seen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('appointments', 'awaiting_reports')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->boolean('awaiting_reports')->default(false)->after('status');
            });
        }
    }

    public function down(): void
    {
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn('awaiting_reports'));
    }
};
