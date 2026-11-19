<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;

/** A pattern of working hours. An end time earlier than the start means the shift ends the next morning. */
class Shift extends Model
{
    use BranchScoped;

    protected $table = 'wf_shifts';

    protected $fillable = ['branch_id', 'name', 'starts_at', 'ends_at', 'grace_minutes', 'early_leave_minutes', 'break_minutes', 'half_day_minutes', 'full_day_minutes', 'overtime_after_minutes', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** Whether the shift runs past midnight into the next day. */
    public function crossesMidnight(): bool
    {
        return substr((string) $this->ends_at, 0, 5) <= substr((string) $this->starts_at, 0, 5);
    }
}
