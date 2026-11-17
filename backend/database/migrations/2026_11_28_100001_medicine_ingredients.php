<?php

use App\Models\Medicine;
use App\Support\Composition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each drug by its own name, and which medicines contain it. A combination contains several.
        Schema::create('drug_ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->timestamps();
        });

        Schema::create('medicine_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('drug_ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('strength', 60)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->unique(['medicine_id', 'drug_ingredient_id']);
        });

        // Compositions already typed in are split into their drugs.
        Medicine::whereNotNull('composition')->where('composition', '!=', '')->get()->each(fn (Medicine $m) => Composition::apply($m, $m->composition));
    }

    public function down(): void
    {
        Schema::dropIfExists('medicine_ingredients');
        Schema::dropIfExists('drug_ingredients');
    }
};
