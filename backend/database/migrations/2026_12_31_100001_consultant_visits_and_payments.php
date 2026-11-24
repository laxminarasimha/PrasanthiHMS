<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the hospital owes its consultants and what it has paid them. Two new tables, both empty:
 * the visits a consultant makes to another doctor's inpatient, each with the fee it earns them, and the
 * payments made to a consultant for a month. Nothing existing is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('consultant_visits')) {
            Schema::create('consultant_visits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained();
                $table->foreignId('admission_id')->constrained();
                $table->foreignId('patient_id')->constrained();
                $table->foreignId('doctor_id')->constrained('users'); // the consultant who came
                $table->date('visited_on')->index();
                $table->string('kind', 10); // first | later
                $table->decimal('fee', 10, 2); // what the hospital pays the consultant for it, as the rates stood that day
                $table->string('note', 255)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('cancelled_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('cancel_reason', 255)->nullable();
                $table->timestamps();
                $table->index(['doctor_id', 'visited_on']);
            });
        }
        if (! Schema::hasTable('consultant_payments')) {
            Schema::create('consultant_payments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->nullable()->constrained();
                $table->foreignId('doctor_id')->constrained('users');
                $table->string('for_month', 7)->index(); // the month it settles, e.g. 2026-11
                $table->date('paid_on');
                $table->decimal('amount', 12, 2);
                $table->string('mode', 20);
                $table->string('reference', 120)->nullable();
                $table->string('note', 255)->nullable();
                $table->foreignId('expense_id')->nullable()->constrained('expenses')->nullOnDelete(); // the line it wrote in the cash book
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->dateTime('cancelled_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('cancel_reason', 255)->nullable();
                $table->timestamps();
                $table->index(['doctor_id', 'for_month']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consultant_payments');
        Schema::dropIfExists('consultant_visits');
    }
};
