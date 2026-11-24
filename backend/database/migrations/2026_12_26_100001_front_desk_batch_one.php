<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Four small front desk additions: a doctor's own slot length, why a bill was discounted and who allowed it,
 * and a link from a patient or lab order to the referring doctor on the hospital's list. Structure only:
 * no existing row is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'slot_minutes')) {
            Schema::table('users', fn (Blueprint $table) => $table->unsignedSmallInteger('slot_minutes')->nullable()->after('availability'));
        }
        if (! Schema::hasColumn('bills', 'discount_reason')) {
            Schema::table('bills', function (Blueprint $table) {
                $table->string('discount_reason')->nullable()->after('discount');
                $table->string('discount_allowed_by', 100)->nullable()->after('discount_reason');
            });
        }
        foreach (['patients', 'lab_orders'] as $name) {
            if (! Schema::hasColumn($name, 'referral_doctor_id')) {
                Schema::table($name, fn (Blueprint $table) => $table->foreignId('referral_doctor_id')->nullable()->after('referred_by')->constrained('referral_doctors')->nullOnDelete());
            }
        }
    }

    public function down(): void
    {
        foreach (['patients', 'lab_orders'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('referral_doctor_id'));
        }
        Schema::table('bills', fn (Blueprint $table) => $table->dropColumn(['discount_reason', 'discount_allowed_by']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('slot_minutes'));
    }
};
