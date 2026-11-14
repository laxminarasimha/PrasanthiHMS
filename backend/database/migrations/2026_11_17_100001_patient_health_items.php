<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The lasting facts of a patient's health, kept once and shown at every visit:
        // ongoing conditions, regular medicines, past surgeries, family history, habits, vaccines.
        Schema::create('patient_health_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20)->index();
            $table->string('title');
            $table->string('detail', 500)->nullable();
            $table->string('since', 30)->nullable(); // as the patient tells it: "2018", "10 years", "Mar 2024"
            $table->boolean('is_active')->default(true); // false: resolved, stopped
            $table->date('ended_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['patient_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_health_items');
    }
};
