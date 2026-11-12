<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public const LAB_DEFAULTS = [
        'lab_name' => '',
        'lab_address' => '',
        'technician_1' => '',
        'technician_2' => '',
        'letterhead_top_mm' => 40,
        'letterhead_bottom_mm' => 20,
        'each_test_on_new_page' => false,
        'allow_print_without_payment' => true,
        'print_sample_date' => true,
        'print_approved_date' => true,
        'approval_required' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /**
     * A settings group with any missing values filled from its defaults.
     *
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public static function group(string $key, array $defaults): array
    {
        return array_merge($defaults, static::find($key)?->value ?? []);
    }
}
