<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One row of a small hospital-wide list: a department, a vendor, or a payer. */
#[Fillable(['kind', 'name', 'details', 'is_active'])]
class AdminEntry extends Model
{
    /** The extra boxes each kind of entry has besides its name. */
    public const KINDS = [
        'department' => ['head', 'type', 'location'],
        'vendor' => ['phone', 'gstin', 'address', 'supplies'],
        'payer' => ['contact', 'billing_mode', 'notes'],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['details' => 'array', 'is_active' => 'boolean'];
    }
}
