<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->string('sub_category', 100)->nullable()->after('category');
            // Inventory classes a pharmacy uses to decide what to watch closely.
            $table->string('ved', 1)->nullable()->after('sub_category'); // V vital, E essential, D desirable
            $table->string('sde', 1)->nullable()->after('ved');          // S scarce, D difficult, E easy to get
            $table->string('fsn', 1)->nullable()->after('sde');          // F fast, S slow, N non-moving
        });

        Schema::table('stockists', fn (Blueprint $table) => $table->string('email')->nullable()->after('phone'));
    }

    public function down(): void
    {
        Schema::table('stockists', fn (Blueprint $table) => $table->dropColumn('email'));
        Schema::table('medicines', fn (Blueprint $table) => $table->dropColumn(['sub_category', 'ved', 'sde', 'fsn']));
    }
};
