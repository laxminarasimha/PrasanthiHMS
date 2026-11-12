<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['doctor_id', 'name', 'content'])]
class PrescriptionTemplate extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['content' => 'array'];
    }
}
