<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An IPD charge can stand for an unpaid lab, front desk or pharmacy bill moved onto the IPD bill.
        Schema::table('admission_charges', function (Blueprint $table) {
            $table->string('source_type', 20)->nullable()->after('created_by'); // bill or pharmacy
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->index(['source_type', 'source_id']);
        });

        // The part of a pharmacy bill that will be collected through an IPD bill instead.
        Schema::table('pharmacy_sales', function (Blueprint $table) {
            $table->decimal('ipd_transfer', 10, 2)->default(0)->after('paid');
        });
    }

    public function down(): void
    {
        Schema::table('admission_charges', function (Blueprint $table) {
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropColumn(['source_type', 'source_id']);
        });
        Schema::table('pharmacy_sales', fn (Blueprint $table) => $table->dropColumn('ipd_transfer'));
    }
};
