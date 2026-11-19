<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One day of one person's duty roster, where it differs from their usual shift and weekly off. */
class RosterDay extends Model
{
    use BranchScoped;

    protected $table = 'wf_roster';

    protected $fillable = ['branch_id', 'employee_id', 'work_date', 'shift_id', 'is_off', 'updated_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['work_date' => 'date:Y-m-d', 'is_off' => 'boolean'];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
