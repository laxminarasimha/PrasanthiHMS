<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'access', 'fields', 'is_active'])]
class FormTemplate extends Model
{
    /** The kinds of box a form can have. A heading only divides the form; a signature leaves room to sign on paper. */
    public const TYPES = ['heading', 'text', 'textarea', 'number', 'date', 'choice', 'checkbox', 'signature'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['fields' => 'array', 'is_active' => 'boolean'];
    }
}
