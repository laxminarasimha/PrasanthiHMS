<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Once a finished visit is locked it is never rewritten; later corrections are added beside it.
        Schema::create('consultation_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Who opened or changed which record, and when. Rows are only ever added.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable(); // kept as text too, so the log still reads if the login is removed
            $table->string('action', 40)->index();
            $table->foreignId('patient_id')->nullable()->index();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('summary', 1000)->nullable();
            $table->string('ip', 45)->nullable();
            $table->dateTime('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('consultation_amendments');
    }
};
