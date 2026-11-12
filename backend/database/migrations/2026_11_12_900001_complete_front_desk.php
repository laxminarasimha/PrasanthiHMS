<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            // Which counter the bill belongs to: appointment, lab or other (walk-in services).
            $table->string('kind', 20)->default('appointment')->after('appointment_id')->index();
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->decimal('discount', 10, 2)->default(0)->after('unit_price'); // rupees off this line
        });

        Schema::table('appointments', function (Blueprint $table) {
            // The patient's place in a doctor's line for the day.
            $table->unsignedSmallInteger('token_no')->nullable()->after('status');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->unsignedTinyInteger('priority')->default(2)->after('gst_percent'); // 1 high, 3 low: order in pick lists
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->date('document_date');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Existing bills: lab bills are the ones a lab order points to; a bill with no visit is a walk-in.
        DB::table('bills')->whereIn('id', DB::table('lab_orders')->whereNotNull('bill_id')->pluck('bill_id'))->update(['kind' => 'lab']);
        DB::table('bills')->whereNull('appointment_id')->where('kind', 'appointment')->update(['kind' => 'other']);

        // Existing visits get tokens in the order they were booked, per doctor per day.
        $counters = [];
        foreach (DB::table('appointments')->orderBy('scheduled_at')->orderBy('id')->get(['id', 'doctor_id', 'scheduled_at']) as $row) {
            $key = $row->doctor_id.'|'.substr($row->scheduled_at, 0, 10);
            $counters[$key] = ($counters[$key] ?? 0) + 1;
            DB::table('appointments')->where('id', $row->id)->update(['token_no' => $counters[$key]]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('priority'));
        Schema::table('appointments', fn (Blueprint $table) => $table->dropColumn('token_no'));
        Schema::table('bill_items', fn (Blueprint $table) => $table->dropColumn('discount'));
        Schema::table('bills', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
