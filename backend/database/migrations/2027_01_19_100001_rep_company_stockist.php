<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stockist who supplies a medicine company's brands. A brand ordered from the company's catalogue goes to this
 * stockist unless another one is chosen on the order. Chosen by those who run the pharmacy; one for each company.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rep_companies', 'stockist_id')) {
            Schema::table('rep_companies', fn (Blueprint $table) => $table->unsignedBigInteger('stockist_id')->nullable()->index());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rep_companies', 'stockist_id')) {
            Schema::table('rep_companies', function (Blueprint $table) {
                $table->dropIndex(['stockist_id']);
                $table->dropColumn('stockist_id');
            });
        }
    }
};
