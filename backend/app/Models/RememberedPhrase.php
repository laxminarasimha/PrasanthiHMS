<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['doctor_id', 'kind', 'text', 'uses', 'last_used_at'])]
class RememberedPhrase extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime:Y-m-d'];
    }
}
