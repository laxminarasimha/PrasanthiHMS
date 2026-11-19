<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;

/** One of the module's choices for a branch, kept by name. */
class Setting extends Model
{
    use BranchScoped;

    protected $table = 'wf_settings';

    protected $fillable = ['branch_id', 'key', 'value', 'updated_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
