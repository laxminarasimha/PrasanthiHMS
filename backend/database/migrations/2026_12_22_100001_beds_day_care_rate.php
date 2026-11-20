<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A ward bed can also be used for day care, at a rate of its own. A bed may carry a day-care rate beside its usual
 * daily rate, and an admission may be a day-care one: its bed charges then use the day-care rate. Nothing existing
 * changes: no bed has a day-care rate and no admission is day care until someone says so.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('beds', 'day_care_charge')) {
            Schema::table('beds', function (Blueprint $table) {
                $table->decimal('day_care_charge', 10, 2)->nullable()->after('daily_charge');
            });
        }
        if (! Schema::hasColumn('admissions', 'is_day_care')) {
            Schema::table('admissions', function (Blueprint $table) {
                $table->boolean('is_day_care')->default(false)->after('status');
            });
        }
    }

    public function down(): void
    {
        Schema::table('beds', fn (Blueprint $table) => $table->dropColumn('day_care_charge'));
        Schema::table('admissions', fn (Blueprint $table) => $table->dropColumn('is_day_care'));
    }
};
