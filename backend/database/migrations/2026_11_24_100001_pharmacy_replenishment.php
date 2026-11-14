<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What to keep on the shelf, and whom the medicine is usually bought from.
        Schema::table('medicines', function (Blueprint $table) {
            $table->unsignedInteger('desired_stock')->nullable()->after('reorder_level'); // units to fill the shelf up to when ordering
            $table->foreignId('preferred_stockist_id')->nullable()->after('desired_stock')->constrained('stockists')->nullOnDelete();
        });

        // How long a supplier takes to deliver, and how long they give to pay.
        Schema::table('stockists', function (Blueprint $table) {
            $table->unsignedSmallInteger('lead_days')->default(2)->after('address');
            $table->unsignedSmallInteger('credit_days')->default(0)->after('lead_days');
        });

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('invoice_date');
            $table->foreignId('purchase_order_id')->nullable()->after('stockist_id')->constrained()->nullOnDelete();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->date('expected_on')->nullable()->after('order_date');
            $table->dateTime('approved_at')->nullable()->after('status');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
        });

        // Each payment to a supplier, so a statement can show when and how every rupee was paid.
        Schema::create('purchase_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('mode', 20)->default('cash');
            $table->string('reference')->nullable();
            $table->date('paid_on');
            $table->string('remarks', 500)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('paid_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_payments');
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['expected_on', 'approved_at']);
        });
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
            $table->dropColumn('due_date');
        });
        Schema::table('stockists', fn (Blueprint $table) => $table->dropColumn(['lead_days', 'credit_days']));
        Schema::table('medicines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_stockist_id');
            $table->dropColumn('desired_stock');
        });
    }
};
