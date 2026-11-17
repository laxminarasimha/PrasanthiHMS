<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a medicine contains, with strengths: "Paracetamol 650 mg", "Amoxicillin 500 mg + Clavulanic acid 125 mg".
        // Brands with the same composition are options for one another when ordering.
        Schema::table('medicines', function (Blueprint $table) {
            $table->string('composition')->nullable()->after('name');
            $table->index('composition');
        });
    }

    public function down(): void
    {
        Schema::table('medicines', function (Blueprint $table) {
            $table->dropIndex(['composition']);
            $table->dropColumn('composition');
        });
    }
};
