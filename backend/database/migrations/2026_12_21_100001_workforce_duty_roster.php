<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The duty roster: which shift a member of staff works on a given day, where it differs from their usual one, or
 * that the day is their day off. A day with no line here is their usual shift and their usual weekly off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wf_roster')) {
            return;
        }
        Schema::create('wf_roster', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('employee_id')->constrained('wf_employees');
            $table->date('work_date');
            $table->foreignId('shift_id')->nullable()->constrained('wf_shifts')->nullOnDelete(); // null with is_off: a day off
            $table->boolean('is_off')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'work_date']);
            $table->index(['branch_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wf_roster');
    }
};
