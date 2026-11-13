<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_records', function (Blueprint $table) {
            // Set when the form was filled in during an OPD visit rather than an IPD stay.
            $table->foreignId('appointment_id')->nullable()->after('admission_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('form_records', fn (Blueprint $table) => $table->dropConstrainedForeignId('appointment_id'));
    }
};
