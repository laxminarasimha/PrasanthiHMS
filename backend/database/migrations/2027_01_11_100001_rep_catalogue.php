<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalogue of each medicine company: the brands its representatives speak for, what each contains, and the
 * price and scheme on offer. A product is linked to its individual drugs the same way the pharmacy's own medicines
 * are, so the two can be set side by side by what they contain.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rep_products')) {
            Schema::create('rep_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rep_company_id')->constrained('rep_companies')->cascadeOnDelete();
                $table->string('name', 255);
                $table->string('composition', 255)->nullable();
                // Tablet, syrup, injection and the like; and the pack as the company sells it ("strip of 10").
                $table->string('form', 60)->nullable();
                $table->string('pack', 100)->nullable();
                $table->decimal('mrp', 10, 2)->nullable();
                // What the company offers the hospital a pack for.
                $table->decimal('rate', 10, 2)->nullable();
                $table->string('scheme', 255)->nullable();
                $table->string('notes', 1000)->nullable();
                // The pharmacy's own medicine of the same brand name, when it keeps one.
                $table->foreignId('medicine_id')->nullable()->constrained('medicines')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['rep_company_id', 'name']);
                $table->index('composition');
            });
        }

        if (! Schema::hasTable('rep_product_ingredients')) {
            Schema::create('rep_product_ingredients', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rep_product_id')->constrained('rep_products')->cascadeOnDelete();
                $table->foreignId('drug_ingredient_id')->constrained('drug_ingredients')->cascadeOnDelete();
                $table->string('strength', 60)->nullable();
                $table->unsignedSmallInteger('position')->default(0);
                $table->unique(['rep_product_id', 'drug_ingredient_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rep_product_ingredients');
        Schema::dropIfExists('rep_products');
    }
};
