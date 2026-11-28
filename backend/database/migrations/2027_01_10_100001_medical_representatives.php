<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medical representatives: the companies whose medicines they speak for, the people themselves, and each time one
 * comes to meet a doctor. None of this is about a patient, so it is kept apart from visits, bills and their reports.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rep_companies')) {
            Schema::create('rep_companies', function (Blueprint $table) {
                $table->id();
                $table->string('name', 255)->unique();
                $table->string('notes', 1000)->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('medical_reps')) {
            Schema::create('medical_reps', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rep_company_id')->constrained('rep_companies')->cascadeOnDelete();
                $table->string('name', 255);
                $table->string('mobile', 20)->nullable();
                // The company's division the person works for, and the area they cover.
                $table->string('division', 255)->nullable();
                $table->string('area', 255)->nullable();
                $table->string('manager_name', 255)->nullable();
                $table->string('manager_mobile', 20)->nullable();
                $table->string('notes', 1000)->nullable();
                $table->boolean('is_active')->default(true);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index('mobile');
            });
        }

        if (! Schema::hasTable('rep_visits')) {
            Schema::create('rep_visits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('medical_rep_id')->constrained('medical_reps')->cascadeOnDelete();
                $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
                $table->dateTime('arrived_at');
                // waiting, met, not_met, or come_back (asked to come on another day).
                $table->string('status', 12)->default('waiting');
                $table->string('purpose', 255)->nullable();
                // Written by the doctor after the meeting.
                $table->text('discussed')->nullable();
                $table->text('offers')->nullable();
                // The day the rep was asked to come back, or said they would come next.
                $table->date('next_on')->nullable();
                $table->dateTime('closed_at')->nullable();
                $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['doctor_id', 'arrived_at']);
                $table->index('arrived_at');
            });
        }

        if (! Schema::hasTable('rep_company_files')) {
            Schema::create('rep_company_files', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rep_company_id')->constrained('rep_companies')->cascadeOnDelete();
                $table->string('title', 255);
                $table->string('path', 500);
                $table->string('original_name', 255);
                $table->string('mime', 100)->nullable();
                $table->unsignedBigInteger('size')->default(0);
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rep_company_files');
        Schema::dropIfExists('rep_visits');
        Schema::dropIfExists('medical_reps');
        Schema::dropIfExists('rep_companies');
    }
};
