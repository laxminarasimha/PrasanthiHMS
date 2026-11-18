<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every change to the count of a batch, with who did it and what it was for: the pharmacy's stock ledger.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained();
            $table->string('kind', 24);
            $table->integer('units'); // in is positive, out is negative
            $table->unsignedInteger('balance_after');
            $table->string('reference')->nullable(); // the bill, invoice or stock count it belongs to
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['medicine_id', 'created_at']);
            $table->index(['kind', 'created_at']);
        });

        // A physical count of what is on the shelf, checked against what the records say.
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('count_no')->nullable()->unique();
            $table->string('title');
            $table->string('scope', 20); // all, rack, letters, spot, chosen, reported
            $table->string('scope_detail')->nullable();
            $table->boolean('blind')->default(true); // the people counting are not shown what the records say
            $table->string('status', 12)->default('counting'); // counting, submitted, approved, cancelled
            $table->text('notes')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medicine_id')->constrained();
            $table->string('medicine_name');
            $table->string('batch_no');
            $table->date('expiry')->nullable();
            $table->string('rack_no')->nullable();
            $table->decimal('unit_cost', 10, 2)->default(0);
            $table->decimal('unit_mrp', 10, 2)->default(0);
            $table->unsignedInteger('expected_units')->nullable(); // what the records said at the moment it was counted
            $table->unsignedInteger('counted_units')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('counted_at')->nullable();
            $table->boolean('recount')->default(false);
            $table->string('reason', 20)->nullable();
            $table->string('note')->nullable();
            $table->integer('applied_units')->nullable(); // what approval added to or took off the batch
            $table->timestamps();
            $table->unique(['stock_count_id', 'stock_batch_id']);
        });

        // A correction now says what kind of difference it was, what it was worth, and which count found it.
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->string('category', 20)->nullable()->after('reason');
            $table->foreignId('stock_count_id')->nullable()->after('category')->constrained()->nullOnDelete();
            $table->decimal('unit_cost', 10, 2)->nullable()->after('stock_count_id');
            $table->decimal('unit_mrp', 10, 2)->nullable()->after('unit_cost');
            $table->index('category');
        });

        // The ledger starts from what each batch holds today.
        $now = now();
        DB::table('stock_batches')->orderBy('id')->chunkById(500, function ($batches) use ($now) {
            DB::table('stock_movements')->insert($batches->map(fn ($b) => [
                'stock_batch_id' => $b->id, 'medicine_id' => $b->medicine_id, 'kind' => 'baseline', 'units' => $b->units_in_stock,
                'balance_after' => $b->units_in_stock, 'created_at' => $now, 'updated_at' => $now,
            ])->all());
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropConstrainedForeignId('stock_count_id');
            $table->dropColumn(['category', 'unit_cost', 'unit_mrp']);
        });
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_movements');
    }
};
