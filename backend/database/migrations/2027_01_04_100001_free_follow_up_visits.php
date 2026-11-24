<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A visit that is a free follow-up: to the same doctor within the hospital's follow-up days of a consultation.
 * Visits from before this are all left as consultations.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('appointments', 'is_follow_up')) {
            Schema::table('appointments', fn (Blueprint $table) => $table->boolean('is_follow_up')->default(false)->after('status'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('appointments', 'is_follow_up')) {
            Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn('is_follow_up'));
        }
    }
};
