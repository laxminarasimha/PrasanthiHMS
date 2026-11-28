<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A doctor can advise admission on the visit pad. IPD sees that advice until the patient is admitted (the admission
 * remembers which visit it came from) or someone marks that the patient is not coming.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('consultations', 'admit_advised_at')) {
            Schema::table('consultations', function (Blueprint $table) {
                $table->timestamp('admit_advised_at')->nullable()->index();
                $table->string('admit_reason', 500)->nullable();
                $table->timestamp('admit_closed_at')->nullable();
            });
        }
        if (! Schema::hasColumn('admissions', 'consultation_id')) {
            Schema::table('admissions', fn (Blueprint $table) => $table->unsignedBigInteger('consultation_id')->nullable()->index());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('admissions', 'consultation_id')) {
            Schema::table('admissions', function (Blueprint $table) {
                $table->dropIndex(['consultation_id']);
                $table->dropColumn('consultation_id');
            });
        }
        if (Schema::hasColumn('consultations', 'admit_advised_at')) {
            Schema::table('consultations', function (Blueprint $table) {
                $table->dropIndex(['admit_advised_at']);
                $table->dropColumn(['admit_advised_at', 'admit_reason', 'admit_closed_at']);
            });
        }
    }
};
