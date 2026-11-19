<?php

namespace App\Workforce\Models;

use Illuminate\Database\Eloquent\Model;

/** One holiday on one calendar. */
class Holiday extends Model
{
    protected $table = 'wf_holidays';

    protected $fillable = ['calendar_id', 'on', 'name', 'is_optional'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['on' => 'date:Y-m-d', 'is_optional' => 'boolean'];
    }
}
