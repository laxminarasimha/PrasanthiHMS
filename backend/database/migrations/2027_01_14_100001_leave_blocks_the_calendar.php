<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A doctor's approved leave blocks their appointment calendar by itself. The block remembers which leave made it,
 * so it goes when the leave is cancelled, and cannot be taken off the calendar on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('doctor_blocks', 'leave_request_id')) {
            Schema::table('doctor_blocks', fn (Blueprint $table) => $table->unsignedBigInteger('leave_request_id')->nullable()->unique());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('doctor_blocks', 'leave_request_id')) {
            Schema::table('doctor_blocks', function (Blueprint $table) {
                $table->dropUnique(['leave_request_id']);
                $table->dropColumn('leave_request_id');
            });
        }
    }
};
