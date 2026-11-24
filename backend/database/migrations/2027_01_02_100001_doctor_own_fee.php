<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A doctor's own OPD consultation. A service could already belong to one doctor; this marks the one that is billed
 * by itself when that doctor is chosen for a visit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('services', 'is_doctor_fee')) {
            Schema::table('services', fn (Blueprint $table) => $table->boolean('is_doctor_fee')->default(false)->after('doctor_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('services', 'is_doctor_fee')) {
            Schema::table('services', fn (Blueprint $table) => $table->dropColumn('is_doctor_fee'));
        }
    }
};
