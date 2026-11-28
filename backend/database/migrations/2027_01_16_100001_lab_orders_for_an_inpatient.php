<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A lab order made from an IPD case remembers the case: its bill goes on the IPD bill, and the lab sees the bed. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('lab_orders', 'admission_id')) {
            Schema::table('lab_orders', fn (Blueprint $table) => $table->unsignedBigInteger('admission_id')->nullable()->index());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('lab_orders', 'admission_id')) {
            Schema::table('lab_orders', function (Blueprint $table) {
                $table->dropIndex(['admission_id']);
                $table->dropColumn('admission_id');
            });
        }
    }
};
