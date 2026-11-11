<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            // Hospital-wide patient number shown to staff; assigned after insert.
            $table->string('uhid', 20)->nullable()->unique();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->index();
            $table->string('gender', 10);
            $table->date('date_of_birth');
            // True when staff entered an age and the birth date was worked out from it.
            $table->boolean('dob_estimated')->default(false);
            $table->string('mobile', 20)->index();
            $table->string('mobile_alt', 20)->nullable();
            $table->string('email')->nullable();
            $table->string('blood_group', 5)->nullable();
            $table->string('preferred_language', 30)->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('pincode', 10)->nullable();
            $table->string('care_of')->nullable();
            $table->string('occupation')->nullable();
            $table->string('referred_by')->nullable();
            $table->string('channel', 50)->nullable();
            $table->string('tag', 50)->nullable();
            $table->string('existing_id', 50)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
