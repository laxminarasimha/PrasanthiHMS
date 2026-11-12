<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('type', 30)->nullable();       // Tablet, Syrup, Injection...
            $table->string('manufacturer')->nullable();
            $table->string('category')->nullable();
            $table->string('hsn_code', 20)->nullable();
            $table->decimal('gst_percent', 5, 2)->default(12);
            $table->unsignedSmallInteger('units_per_pack')->default(10);
            // Warn when the units in stock across all batches fall to this number.
            $table->unsignedInteger('reorder_level')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('stockists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 20)->nullable();
            $table->string('gstin', 20)->nullable();
            $table->string('address')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stockist_id')->constrained();
            $table->string('invoice_no', 50);
            $table->date('invoice_date');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['stockist_id', 'invoice_no']);
        });

        // One row per batch received. Stock is counted in single units (tablets, bottles).
        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained();
            $table->foreignId('purchase_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('batch_no', 50);
            $table->date('expiry')->index(); // last day of the expiry month
            $table->unsignedSmallInteger('units_per_pack');
            $table->unsignedInteger('packs');
            $table->unsignedInteger('free_packs')->default(0);
            $table->decimal('pack_price', 10, 2); // purchase price before tax
            $table->decimal('pack_mrp', 10, 2);
            $table->decimal('unit_mrp', 10, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('gst_percent', 5, 2)->default(0);
            $table->unsignedInteger('units_received');
            $table->unsignedInteger('units_in_stock');
            $table->string('rack_no', 30)->nullable();
            $table->timestamps();
            $table->index(['medicine_id', 'expiry']);
        });

        Schema::create('pharmacy_sales', function (Blueprint $table) {
            $table->id();
            $table->string('bill_no', 20)->nullable()->unique();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_phone', 20)->nullable();
            $table->string('referred_doctor')->nullable();
            $table->dateTime('sold_at')->index();
            $table->decimal('subtotal', 10, 2)->default(0);     // after line discounts, GST included
            $table->decimal('discount', 10, 2)->default(0);     // bill-level discount in rupees
            $table->decimal('tax', 10, 2)->default(0);          // GST contained in the total
            $table->decimal('round_off', 6, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->decimal('paid', 10, 2)->default(0);
            $table->string('payment_mode', 20)->default('cash');
            $table->string('reference')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('pharmacy_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained();
            $table->foreignId('stock_batch_id')->constrained();
            $table->string('medicine_name');
            $table->string('batch_no', 50);
            $table->date('expiry');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 10, 2);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('gst_percent', 5, 2)->default(0);
            $table->decimal('amount', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_sale_items');
        Schema::dropIfExists('pharmacy_sales');
        Schema::dropIfExists('stock_batches');
        Schema::dropIfExists('purchase_invoices');
        Schema::dropIfExists('stockists');
        Schema::dropIfExists('medicines');
    }
};
