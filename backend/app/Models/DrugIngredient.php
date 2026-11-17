<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** One drug by its own name, such as Paracetamol. Medicines contain one of these, or several in a combination. */
#[Fillable(['name'])]
class DrugIngredient extends Model
{
    public function medicines(): BelongsToMany
    {
        return $this->belongsToMany(Medicine::class, 'medicine_ingredients')->withPivot(['strength', 'position']);
    }
}
