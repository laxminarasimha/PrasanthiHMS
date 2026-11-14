<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_batches', function (Blueprint $table) {
            $table->decimal('discount_amount', 12, 2)->default(0)->after('discount_percent'); // rupees off the line, besides any %
            $table->boolean('tax_on_free')->default(false)->after('gst_percent');              // the stockist charged GST on the free packs too
            $table->string('hsn_code', 20)->nullable()->after('tax_on_free');
            $table->string('box_no', 30)->nullable()->after('rack_no');
        });

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->decimal('other_charges', 12, 2)->default(0)->after('tax');  // freight, packing
            $table->decimal('cash_discount', 12, 2)->default(0)->after('other_charges'); // off the whole bill
            $table->decimal('round_off', 6, 2)->default(0)->after('cash_discount');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', fn (Blueprint $table) => $table->dropColumn(['other_charges', 'cash_discount', 'round_off']));
        Schema::table('stock_batches', fn (Blueprint $table) => $table->dropColumn(['discount_amount', 'tax_on_free', 'hsn_code', 'box_no']));
    }
};
