<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ward');
            $table->string('bed_no', 20);
            $table->decimal('daily_charge', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'ward', 'bed_no']);
        });

        Schema::create('ipd_services', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('admissions', function (Blueprint $table) {
            $table->id();
            $table->string('ipd_no', 20)->nullable()->unique();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained('users');
            $table->foreignId('bed_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('admitted_at')->index();
            $table->dateTime('discharged_at')->nullable();
            // admitted, discharged, lama (left against advice), dama (discharged against advice), absconded, died
            $table->string('status', 20)->default('admitted')->index();
            $table->string('reason', 500)->nullable();
            $table->string('attendant_name')->nullable();
            $table->string('attendant_phone', 20)->nullable();
            $table->text('discharge_summary')->nullable();
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('paid', 12, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('admission_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ipd_service_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->string('category')->nullable();
            $table->date('charged_on');
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 10, 2);
            $table->decimal('amount', 12, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('admission_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10); // advance, payment or refund
            $table->decimal('amount', 12, 2);
            $table->string('mode', 20);
            $table->string('reference')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_payments');
        Schema::dropIfExists('admission_charges');
        Schema::dropIfExists('admissions');
        Schema::dropIfExists('ipd_services');
        Schema::dropIfExists('beds');
    }
};
