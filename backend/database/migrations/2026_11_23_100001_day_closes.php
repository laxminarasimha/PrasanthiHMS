<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row each time a day's accounts are closed. Reopening marks the row; it is never removed,
        // so the history of who closed and who reopened a day stays complete.
        Schema::create('day_closes', function (Blueprint $table) {
            $table->id();
            $table->date('day')->index();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('closed_by_name');
            $table->dateTime('closed_at');
            $table->decimal('expected_cash', 12, 2);
            $table->decimal('counted_cash', 12, 2);
            $table->decimal('variance', 12, 2);
            $table->text('variance_note')->nullable();
            $table->unsignedInteger('exceptions')->default(0); // how many were still open when the day was closed
            $table->text('override_reason')->nullable();
            $table->json('snapshot'); // the figures and the open exceptions as they stood at closing
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reopened_by_name')->nullable();
            $table->dateTime('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('day_closes');
    }
};
