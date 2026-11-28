<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** One brand in a medicine company's catalogue, with what it contains and what is on offer. */
#[Fillable(['rep_company_id', 'name', 'composition', 'form', 'pack', 'mrp', 'rate', 'scheme', 'notes', 'medicine_id', 'created_by'])]
class RepProduct extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['mrp' => 'float', 'rate' => 'float'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(RepCompany::class, 'rep_company_id');
    }

    /** The individual drugs inside, linked as the pharmacy's own medicines are. */
    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(DrugIngredient::class, 'rep_product_ingredients')->withPivot(['strength', 'position'])->orderBy('rep_product_ingredients.position');
    }

    /** The pharmacy's own medicine of the same brand name, when it keeps one. */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }
}
