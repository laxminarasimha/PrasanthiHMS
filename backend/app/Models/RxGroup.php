<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['doctor_id', 'name', 'medicines'])]
class RxGroup extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['medicines' => 'array'];
    }
}
