<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Money a patient leaves with the hospital ahead of their bills, and what happens to it.
        Schema::create('patient_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10); // deposit: money in; used: put against a bill; refund: money handed back
            $table->decimal('amount', 10, 2);
            $table->string('mode', 20)->nullable(); // how the money moved; empty when used against a bill
            $table->string('reference')->nullable();
            $table->foreignId('bill_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['patient_id', 'type']);
        });

        Schema::table('patients', function (Blueprint $table) {
            // Booked in a hurry with only a name and number; the rest is filled in when they arrive.
            $table->boolean('is_temporary')->default(false)->after('dob_estimated');
            $table->date('date_of_birth')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('patients', fn (Blueprint $table) => $table->dropColumn('is_temporary'));
        Schema::dropIfExists('patient_deposits');
    }
};
