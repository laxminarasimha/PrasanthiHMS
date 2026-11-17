<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Family members looked after from one mobile app login: a parent's, a child's, a spouse's record.
        Schema::create('patient_account_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('relation', 30);
            $table->timestamps();
            $table->index(['patient_account_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_account_members');
    }
};
