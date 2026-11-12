<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['parameter', 'is_heading', 'unit', 'reference_range', 'method', 'value', 'flag', 'position'])]
class LabResult extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_heading' => 'boolean'];
    }
}
