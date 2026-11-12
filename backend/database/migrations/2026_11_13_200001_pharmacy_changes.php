<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A record of every edit to a saved bill, purchase invoice, batch or medicine: who, when, what and why.
        Schema::create('pharmacy_changes', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20); // sale, invoice, stock, medicine
            $table->unsignedBigInteger('subject_id');
            $table->string('label'); // bill number, invoice number or medicine name as it was at the time
            $table->text('summary'); // one change per line, "Quantity: 10 → 6"
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_changes');
    }
};
