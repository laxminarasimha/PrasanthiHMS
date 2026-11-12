<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'kind', 'department', 'sample_type', 'price', 'print_order', 'default_comment', 'is_active'])]
class LabTest extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['price' => 'float', 'is_active' => 'boolean'];
    }

    public function parameters(): HasMany
    {
        return $this->hasMany(LabTestParameter::class)->orderBy('position');
    }
}
