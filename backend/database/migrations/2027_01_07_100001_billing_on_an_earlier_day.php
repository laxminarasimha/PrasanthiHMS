<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bill or a payment that was missed can be entered on a later day and still carry the day it belongs to.
 * These records are dated by when they were made, so the day it was really typed in is kept beside it:
 * empty for everything entered on its own day.
 */
return new class extends Migration
{
    private const TABLES = ['bills', 'payments', 'pharmacy_payments', 'admission_payments', 'patient_deposits'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasColumn($name, 'entered_at')) {
                Schema::table($name, fn (Blueprint $table) => $table->timestamp('entered_at')->nullable());
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (Schema::hasColumn($name, 'entered_at')) {
                Schema::table($name, fn (Blueprint $table) => $table->dropColumn('entered_at'));
            }
        }
    }
};
