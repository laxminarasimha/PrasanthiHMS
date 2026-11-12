<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::table('medicines', function (Blueprint $table) {
            // Schedule H/H1/X drugs, which need a prescription and a register.
            $table->boolean('is_scheduled')->default(false)->after('category');
        });

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->decimal('paid', 12, 2)->default(0)->after('total');
        });

        // Every rupee the pharmacy takes or gives back, so collections can be reported by day and mode.
        Schema::create('pharmacy_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_sale_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10); // payment or refund
            $table->decimal('amount', 10, 2);
            $table->string('mode', 20);
            $table->string('reference')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::table('pharmacy_sales', function (Blueprint $table) {
            $table->decimal('returned', 10, 2)->default(0)->after('total'); // value of medicines taken back
            $table->dateTime('voided_at')->nullable()->after('reference');
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable()->after('voided_by');
        });

        Schema::table('pharmacy_sale_items', function (Blueprint $table) {
            $table->unsignedInteger('returned_quantity')->default(0)->after('quantity');
            $table->decimal('unit_cost', 10, 2)->default(0)->after('unit_price'); // what one unit cost us, before tax
        });

        Schema::create('pharmacy_sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pharmacy_sale_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->decimal('refunded', 10, 2)->default(0);
            $table->string('refund_mode', 20)->nullable();
            $table->json('lines'); // [{item_id, medicine_name, batch_no, quantity, amount}]
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained();
            $table->unsignedInteger('before');
            $table->unsignedInteger('after');
            $table->string('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('stock_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_no', 20)->nullable()->unique();
            $table->foreignId('stockist_id')->constrained();
            $table->date('returned_on');
            $table->decimal('total', 12, 2)->default(0);
            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_batch_id')->constrained();
            $table->foreignId('medicine_id')->constrained();
            $table->string('medicine_name');
            $table->string('batch_no', 50);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 10, 2);
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_no', 20)->nullable()->unique();
            $table->foreignId('stockist_id')->constrained();
            $table->date('order_date');
            $table->string('status', 20)->default('open'); // open, received, cancelled
            $table->string('remarks', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained();
            $table->string('medicine_name');
            $table->unsignedSmallInteger('units_per_pack');
            $table->unsignedInteger('packs');
            $table->timestamps();
        });

        // Carry existing data forward: one payment row per bill already paid, a manufacturer row per
        // name already typed, and the unit cost on lines already sold.
        $now = now();
        foreach (DB::table('pharmacy_sales')->where('paid', '>', 0)->get() as $sale) {
            DB::table('pharmacy_payments')->insert([
                'pharmacy_sale_id' => $sale->id, 'type' => 'payment', 'amount' => $sale->paid, 'mode' => $sale->payment_mode,
                'reference' => $sale->reference, 'received_by' => $sale->created_by, 'created_at' => $sale->sold_at, 'updated_at' => $sale->sold_at,
            ]);
        }
        foreach (DB::table('medicines')->whereNotNull('manufacturer')->where('manufacturer', '!=', '')->distinct()->pluck('manufacturer') as $name) {
            DB::table('manufacturers')->insertOrIgnore(['name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        }
        foreach (DB::table('pharmacy_sale_items')->get(['id', 'stock_batch_id']) as $item) {
            $batch = DB::table('stock_batches')->find($item->stock_batch_id);
            if ($batch && $batch->units_per_pack > 0) {
                DB::table('pharmacy_sale_items')->where('id', $item->id)->update([
                    'unit_cost' => round($batch->pack_price * (1 - $batch->discount_percent / 100) / $batch->units_per_pack, 2),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('stock_return_items');
        Schema::dropIfExists('stock_returns');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('pharmacy_sale_returns');
        Schema::table('pharmacy_sale_items', fn (Blueprint $table) => $table->dropColumn(['returned_quantity', 'unit_cost']));
        Schema::table('pharmacy_sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['returned', 'voided_at', 'void_reason']);
        });
        Schema::dropIfExists('pharmacy_payments');
        Schema::table('purchase_invoices', fn (Blueprint $table) => $table->dropColumn('paid'));
        Schema::table('medicines', fn (Blueprint $table) => $table->dropColumn('is_scheduled'));
        Schema::dropIfExists('manufacturers');
    }
};
