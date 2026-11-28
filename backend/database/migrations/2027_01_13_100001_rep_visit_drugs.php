<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The drugs a medical representative spoke about at a visit, kept one to a line the way a prescription is written:
 * the brand, what it contains, what it is for, and how the doctor would give it. They stay as a reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rep_visit_drugs')) {
            Schema::create('rep_visit_drugs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('rep_visit_id')->constrained('rep_visits')->cascadeOnDelete();
                $table->foreignId('rep_product_id')->nullable()->constrained('rep_products')->nullOnDelete();
                $table->string('name', 255);
                $table->string('composition', 255)->nullable();
                $table->string('uses', 255)->nullable();
                $table->string('dose', 50)->nullable();
                $table->string('frequency', 50)->nullable();
                $table->string('timing', 30)->nullable();
                $table->string('duration', 50)->nullable();
                $table->string('note', 255)->nullable();
                // What was on offer that day: the MRP of a pack, the price the hospital gets it at, and any scheme.
                $table->decimal('mrp', 10, 2)->nullable();
                $table->decimal('rate', 10, 2)->nullable();
                $table->string('scheme', 255)->nullable();
                // Kept as the doctor's usual way of giving this brand, so prescribing it fills the row in.
                $table->boolean('keep')->default(false);
                $table->unsignedSmallInteger('position')->default(0);
                $table->timestamps();
            });
        }
        if (! Schema::hasColumn('rep_visit_drugs', 'mrp')) {
            Schema::table('rep_visit_drugs', function (Blueprint $table) {
                $table->decimal('mrp', 10, 2)->nullable();
                $table->decimal('rate', 10, 2)->nullable();
                $table->string('scheme', 255)->nullable();
            });
        }
        // What a brand is used for, as the representative puts it: part of the catalogue, shown on its reference sheet.
        if (! Schema::hasColumn('rep_products', 'uses')) {
            Schema::table('rep_products', fn (Blueprint $table) => $table->string('uses', 255)->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rep_visit_drugs');
        if (Schema::hasColumn('rep_products', 'uses')) {
            Schema::table('rep_products', fn (Blueprint $table) => $table->dropColumn('uses'));
        }
    }
};
