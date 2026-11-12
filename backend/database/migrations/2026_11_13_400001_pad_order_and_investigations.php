<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The order this doctor wants the visit pad sections in; empty means the standard order.
            $table->json('pad_section_order')->nullable()->after('pad_hidden_sections');
        });

        Schema::table('consultations', function (Blueprint $table) {
            // Test results the doctor types in from reports the patient brings: [{name, result, date}].
            $table->json('investigations')->nullable()->after('tests_advised');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('pad_section_order'));
        Schema::table('consultations', fn (Blueprint $table) => $table->dropColumn('investigations'));
    }
};
