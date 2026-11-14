<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The standard list of diagnoses and their codes. Loaded with `php artisan hms:import-icd`.
        Schema::create('icd_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('name', 300);
        });

        // Coded diagnoses for a visit, kept beside the doctor's own wording: [{code, name}]
        Schema::table('consultations', fn (Blueprint $table) => $table->json('diagnosis_codes')->nullable()->after('diagnosis'));
    }

    public function down(): void
    {
        Schema::table('consultations', fn (Blueprint $table) => $table->dropColumn('diagnosis_codes'));
        Schema::dropIfExists('icd_codes');
    }
};
