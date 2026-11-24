<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'kind', 'department', 'sample_type', 'price', 'print_order', 'default_comment', 'is_active', 'outside_lab', 'outside_cost'])]
class LabTest extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['price' => 'float', 'is_active' => 'boolean', 'outside_cost' => 'float'];
    }

    public function parameters(): HasMany
    {
        return $this->hasMany(LabTestParameter::class)->orderBy('position');
    }

    /** Every version of this test's report template, newest first. See App\Support\LabTemplate. */
    public function versions(): HasMany
    {
        return $this->hasMany(LabTestVersion::class)->orderByDesc('version');
    }
}
