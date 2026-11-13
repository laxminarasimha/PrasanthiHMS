<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A numbered bill groups some of a case's charges, e.g. an interim bill every few days.
        Schema::create('admission_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();
            $table->string('bill_no', 30)->nullable()->index();
            $table->date('billed_on');
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // A cancelled bill keeps its number so numbers are never used twice.
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('admission_charges', function (Blueprint $table) {
            $table->foreignId('admission_bill_id')->nullable()->after('admission_id')->constrained('admission_bills')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('admission_charges', fn (Blueprint $table) => $table->dropConstrainedForeignId('admission_bill_id'));
        Schema::dropIfExists('admission_bills');
    }
};
