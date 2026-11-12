<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'category', 'price', 'is_active'])]
class IpdService extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['price' => 'float', 'is_active' => 'boolean'];
    }
}
