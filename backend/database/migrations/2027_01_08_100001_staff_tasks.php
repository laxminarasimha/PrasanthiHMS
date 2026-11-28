<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Things a person has to do that the system cannot know by itself: written by the person for themself, or given
 * to them by someone who runs the hospital. One row is one thing for one person; a repeating one makes its next
 * row when it is ticked off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_tasks')) {
            return;
        }

        Schema::create('staff_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 255);
            $table->text('notes')->nullable();
            $table->foreignId('assigned_to')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->boolean('important')->default(false);
            // none, daily, weekly or monthly: when it is done, the next one is written for the next day, week or month.
            $table->string('repeats', 12)->default('none');
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('done_note', 255)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['assigned_to', 'done_at', 'cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_tasks');
    }
};
