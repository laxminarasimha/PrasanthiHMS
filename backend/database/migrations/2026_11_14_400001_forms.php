<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A form the hospital designs once: an assessment sheet, a consent, a progress note.
        Schema::create('form_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('access', 10)->default('both'); // doctor, ipd or both
            $table->json('fields'); // [{id, label, type, options}]
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // One filled-in form. It keeps its own copy of the questions, so changing the template later does not alter it.
        Schema::create('form_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_template_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admission_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('fields');
            $table->json('values')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_records');
        Schema::dropIfExists('form_templates');
    }
};
