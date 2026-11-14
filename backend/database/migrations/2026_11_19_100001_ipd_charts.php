<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The bedside chart of an inpatient: observations, what the doctor ordered, what was given, fluids in and out.
        Schema::create('admission_vitals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();
            $table->dateTime('recorded_at')->index();
            $table->decimal('temperature_f', 4, 1)->nullable();
            $table->unsignedSmallInteger('pulse')->nullable();
            $table->unsignedSmallInteger('bp_systolic')->nullable();
            $table->unsignedSmallInteger('bp_diastolic')->nullable();
            $table->unsignedSmallInteger('resp_rate')->nullable();
            $table->unsignedSmallInteger('spo2')->nullable();
            $table->unsignedSmallInteger('blood_sugar')->nullable(); // bedside glucose, mg/dL
            $table->unsignedTinyInteger('pain_score')->nullable();   // 0 to 10
            $table->string('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('admission_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // medicine, or another instruction: diet, nursing, investigation
            $table->string('title');    // the medicine, or the instruction in words
            $table->string('dose', 60)->nullable();
            $table->string('route', 30)->nullable();     // oral, IV, IM...
            $table->string('frequency', 60)->nullable(); // "8 hourly", "1-0-1", "once at night", "if fever"
            $table->string('instructions', 500)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('stopped_at')->nullable();
            $table->foreignId('ordered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('stopped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Each time a medicine order was acted on: given, held back, or refused by the patient.
        Schema::create('admission_doses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_order_id')->constrained()->cascadeOnDelete();
            $table->dateTime('given_at')->index();
            $table->string('status', 20)->default('given');
            $table->string('remarks')->nullable();
            $table->foreignId('given_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('admission_fluids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();
            $table->dateTime('recorded_at')->index();
            $table->string('direction', 3); // in, out
            $table->string('kind', 30);     // oral, IV fluid, urine, drain...
            $table->unsignedInteger('amount_ml');
            $table->string('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_fluids');
        Schema::dropIfExists('admission_doses');
        Schema::dropIfExists('admission_orders');
        Schema::dropIfExists('admission_vitals');
    }
};
