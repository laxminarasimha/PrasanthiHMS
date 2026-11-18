<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Money the hospital pays out day to day: wages advanced, tea, repairs, a courier. Cash ones come out of the drawer. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('expense_no', 20)->nullable()->unique();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('spent_on')->index();
            $table->string('category', 60)->index();
            $table->string('paid_to')->nullable();
            $table->string('description', 500)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('mode', 20)->default('cash');
            $table->string('reference')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // An entry made by mistake is cancelled, not erased: it stays on record with who cancelled it and why.
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
