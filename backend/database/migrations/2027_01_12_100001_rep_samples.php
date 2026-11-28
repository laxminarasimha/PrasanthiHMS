<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Samples left by medical representatives: what came in, and each time some of it was given out or thrown away.
 * Samples are not for sale, so they are kept apart from the pharmacy's stock and never appear on a bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rep_samples')) {
            Schema::create('rep_samples', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('rep_company_id')->constrained('rep_companies')->cascadeOnDelete();
                $table->foreignId('medical_rep_id')->nullable()->constrained('medical_reps')->nullOnDelete();
                $table->foreignId('rep_visit_id')->nullable()->constrained('rep_visits')->nullOnDelete();
                $table->foreignId('rep_product_id')->nullable()->constrained('rep_products')->nullOnDelete();
                // The doctor the samples were left for, when they were left for one.
                $table->foreignId('doctor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name', 255);
                $table->unsignedInteger('quantity');
                // What the quantity counts: tablets, strips, bottles.
                $table->string('unit', 40)->nullable();
                $table->date('expiry')->nullable();
                $table->date('received_on');
                $table->string('notes', 1000)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index('received_on');
            });
        }

        if (! Schema::hasTable('rep_sample_issues')) {
            Schema::create('rep_sample_issues', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rep_sample_id')->constrained('rep_samples')->cascadeOnDelete();
                // given (to a patient or anyone else), or discarded (expired, damaged).
                $table->string('kind', 12)->default('given');
                $table->unsignedInteger('quantity');
                $table->date('given_on');
                $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
                $table->string('note', 255)->nullable();
                $table->foreignId('given_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rep_sample_issues');
        Schema::dropIfExists('rep_samples');
    }
};
