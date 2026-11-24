<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lab: normal ranges that depend on the patient's age and sex, and tests done at an outside lab.
 * Structure only. Nothing already recorded is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Extra normal ranges of one field, each for a sex, an age band, or both. The plain range stays where it was.
        if (! Schema::hasColumn('lab_test_parameters', 'ranges')) {
            Schema::table('lab_test_parameters', fn (Blueprint $table) => $table->json('ranges')->nullable());
        }

        // A test the hospital sends to another lab, and what that lab charges for it.
        if (! Schema::hasColumn('lab_tests', 'outside_lab')) {
            Schema::table('lab_tests', function (Blueprint $table) {
                $table->string('outside_lab')->nullable();
                $table->decimal('outside_cost', 10, 2)->nullable();
            });
        }

        // The same for one test on one order, with when its sample left the hospital.
        if (! Schema::hasColumn('lab_order_items', 'outside_lab')) {
            Schema::table('lab_order_items', function (Blueprint $table) {
                $table->string('outside_lab')->nullable();
                $table->decimal('outside_cost', 10, 2)->nullable();
                $table->dateTime('sent_at')->nullable();
            });
        }
        if (! Schema::hasColumn('lab_order_items', 'sent_by')) {
            Schema::table('lab_order_items', fn (Blueprint $table) => $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete());
        }
    }

    public function down(): void
    {
        Schema::table('lab_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sent_by');
            $table->dropColumn(['outside_lab', 'outside_cost', 'sent_at']);
        });
        Schema::table('lab_tests', fn (Blueprint $table) => $table->dropColumn(['outside_lab', 'outside_cost']));
        Schema::table('lab_test_parameters', fn (Blueprint $table) => $table->dropColumn('ranges'));
    }
};
