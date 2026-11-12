<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->text('quick_notes')->nullable()->after('spo2');           // the doctor's own notes; never printed
            $table->unsignedSmallInteger('resp_rate')->nullable()->after('spo2');
            $table->text('history')->nullable()->after('complaints');
            $table->text('past_medication')->nullable()->after('history');
            // Pallor, icterus, cyanosis, clubbing, lymphadenopathy, edema: each "present", "absent" or not recorded.
            $table->json('general_exam')->nullable()->after('past_medication');
            $table->text('general_exam_notes')->nullable()->after('general_exam');
            $table->text('assessment_plan')->nullable()->after('diagnosis');
            $table->string('refer_to', 500)->nullable()->after('advice');
        });

        // Allergies belong to the patient, not to one visit, so every later visit sees them.
        Schema::table('patients', function (Blueprint $table) {
            $table->string('allergies', 1000)->nullable()->after('blood_group');
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->dropColumn(['quick_notes', 'resp_rate', 'history', 'past_medication', 'general_exam', 'general_exam_notes', 'assessment_plan', 'refer_to']);
        });
        Schema::table('patients', fn (Blueprint $table) => $table->dropColumn('allergies'));
    }
};
