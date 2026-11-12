<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Days or hours when a doctor is not seeing patients: leave, surgery, a conference.
        Schema::create('doctor_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('users')->cascadeOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('start_time', 5)->nullable(); // both empty: the whole day
            $table->string('end_time', 5)->nullable();
            $table->string('reason', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['doctor_id', 'from_date', 'to_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_blocks');
    }
};
