<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Doctors outside the hospital: those who send patients here, and those patients are sent to.
        Schema::create('referral_doctors', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 5); // by: refers patients to us; to: we refer patients to them
            $table->string('name');
            $table->string('specialization')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('place')->nullable();
            $table->timestamps();
            $table->unique(['direction', 'name']);
        });

        Schema::table('patients', function (Blueprint $table) {
            // Set when this record was a duplicate and its history was moved to another patient.
            $table->foreignId('merged_into_id')->nullable()->after('created_by')->constrained('patients')->nullOnDelete();
        });

        Schema::create('patient_merges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('from_id');
            $table->string('from_uhid', 20);
            $table->string('from_name');
            $table->unsignedBigInteger('to_id');
            $table->string('to_uhid', 20);
            $table->string('to_name');
            $table->json('moved'); // how many rows of each kind were moved
            $table->foreignId('merged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_merges');
        Schema::table('patients', fn (Blueprint $table) => $table->dropConstrainedForeignId('merged_into_id'));
        Schema::dropIfExists('referral_doctors');
    }
};
