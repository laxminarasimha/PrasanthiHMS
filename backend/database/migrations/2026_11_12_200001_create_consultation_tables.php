<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('users');
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // Vitals
            $table->decimal('weight_kg', 5, 1)->nullable();
            $table->decimal('height_cm', 5, 1)->nullable();
            $table->unsignedSmallInteger('bp_systolic')->nullable();
            $table->unsignedSmallInteger('bp_diastolic')->nullable();
            $table->unsignedSmallInteger('pulse')->nullable();
            $table->decimal('temperature_f', 4, 1)->nullable();
            $table->unsignedTinyInteger('spo2')->nullable();

            // Clinical notes
            $table->text('complaints')->nullable();
            $table->text('findings')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('tests_advised')->nullable();
            $table->text('advice')->nullable();
            $table->date('follow_up_on')->nullable();

            // Set when the doctor finishes the visit; drafts have no completion time.
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'completed_at']);
        });

        Schema::create('prescription_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->string('medicine')->index();
            $table->string('dose', 50)->nullable();       // e.g. "1 tablet", "5 ml"
            $table->string('frequency', 50)->nullable();  // e.g. "1-0-1"
            $table->string('timing', 30)->nullable();     // e.g. "After food"
            $table->string('duration', 50)->nullable();   // e.g. "5 days"
            $table->string('instructions')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('consultations');
    }
};
