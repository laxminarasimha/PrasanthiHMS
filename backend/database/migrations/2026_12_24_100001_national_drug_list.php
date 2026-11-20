<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The national drug list: Common Drug Codes for India (CDCI) from NRCeS, C-DAC, the same codes the ABDM Drug Registry
 * uses. It is reference only (nothing is sold from it) and is loaded whole from the government's files with
 * `php artisan hms:import-cdci`. A medicine of ours is linked to one brand on it, and through that to its generic.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('drug_ref_substances')) {
            Schema::create('drug_ref_substances', function (Blueprint $table) {
                $table->string('code', 20)->primary();
                $table->string('name', 255);
            });
        }
        if (! Schema::hasTable('drug_ref_generics')) {
            Schema::create('drug_ref_generics', function (Blueprint $table) {
                $table->string('code', 20)->primary();
                $table->string('name', 400);
                // The substances in it, as their codes joined with "+", in the order the name gives them.
                $table->string('substance_codes', 255)->nullable();
                $table->string('form', 150)->nullable();
                $table->string('route', 150)->nullable();
                $table->text('therapeutic_role')->nullable();
                $table->text('indication')->nullable();
                $table->text('contra_indication')->nullable();
                $table->text('interactions')->nullable();
                $table->date('updated_on')->nullable();
            });
        }
        if (! Schema::hasTable('drug_ref_brands')) {
            Schema::create('drug_ref_brands', function (Blueprint $table) {
                $table->string('code', 20)->primary();
                $table->string('name', 255);
                // The name on the pack without strength or form ("Dolo"), which is what a pharmacy's own list is called.
                $table->string('product_name', 150)->nullable()->index();
                $table->string('generic_code', 20)->nullable()->index();
                $table->string('supplier', 255)->nullable();
                $table->date('updated_on')->nullable();
            });
        }
        Schema::table('medicines', function (Blueprint $table) {
            if (! Schema::hasColumn('medicines', 'ref_brand_code')) {
                $table->string('ref_brand_code', 20)->nullable()->index();
                $table->string('ref_generic_code', 20)->nullable()->index();
                $table->timestamp('ref_linked_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('medicines', fn (Blueprint $table) => $table->dropColumn(['ref_brand_code', 'ref_generic_code', 'ref_linked_at']));
        Schema::dropIfExists('drug_ref_brands');
        Schema::dropIfExists('drug_ref_generics');
        Schema::dropIfExists('drug_ref_substances');
    }
};
