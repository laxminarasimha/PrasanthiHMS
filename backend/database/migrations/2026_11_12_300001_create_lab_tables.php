<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The catalogue: one row per orderable test or report (e.g. "Lipid Profile").
        Schema::create('lab_tests', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->nullable();
            $table->string('name');
            $table->string('department')->nullable();
            $table->string('sample_type', 50)->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedSmallInteger('print_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // What gets measured within a test (e.g. "HDL Cholesterol" inside "Lipid Profile").
        Schema::create('lab_test_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_test_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('unit', 30)->nullable();
            $table->string('reference_range')->nullable();
            $table->string('method')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('lab_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 20)->nullable()->unique();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained()->nullOnDelete();
            $table->string('referred_by')->nullable();
            $table->string('status', 20)->default('pending')->index(); // pending, partial, completed
            $table->dateTime('ordered_at')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('lab_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_test_id')->nullable()->constrained()->nullOnDelete();
            // Copied from the catalogue so later edits do not rewrite old reports.
            $table->string('test_name');
            $table->string('department')->nullable();
            $table->string('sample_type', 50)->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('remarks', 1000)->nullable();
            $table->dateTime('reported_at')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('lab_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_order_item_id')->constrained()->cascadeOnDelete();
            $table->string('parameter');
            $table->string('unit', 30)->nullable();
            $table->string('reference_range')->nullable();
            $table->string('method')->nullable();
            $table->string('value')->nullable();
            $table->string('flag', 10)->nullable(); // high, low, or null for normal/not judged
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_results');
        Schema::dropIfExists('lab_order_items');
        Schema::dropIfExists('lab_orders');
        Schema::dropIfExists('lab_test_parameters');
        Schema::dropIfExists('lab_tests');
    }
};
