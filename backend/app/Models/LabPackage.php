<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'price', 'is_active'])]
class LabPackage extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['price' => 'float', 'is_active' => 'boolean'];
    }

    public function tests(): BelongsToMany
    {
        return $this->belongsToMany(LabTest::class, 'lab_package_test')->orderBy('print_order')->orderBy('name');
    }
}
