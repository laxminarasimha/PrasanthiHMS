<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'is_heading', 'unit', 'reference_range', 'method', 'position'])]
class LabTestParameter extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_heading' => 'boolean'];
    }
}
