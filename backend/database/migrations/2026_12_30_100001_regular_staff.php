<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a member of staff is in the daily attendance and on the payroll. Structure only: the column starts empty
 * for everyone, and empty means "by the usual rule" (doctors and consultants are not; everyone else is).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('wf_employees', 'regular')) {
            Schema::table('wf_employees', fn (Blueprint $table) => $table->boolean('regular')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('wf_employees', 'regular')) {
            Schema::table('wf_employees', fn (Blueprint $table) => $table->dropColumn('regular'));
        }
    }
};
