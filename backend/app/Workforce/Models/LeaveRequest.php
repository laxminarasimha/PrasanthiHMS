<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;

/** A request for leave, and what was decided. */
class LeaveRequest extends Model
{
    use BranchScoped;

    protected $table = 'wf_leave_requests';

    protected $fillable = ['branch_id', 'employee_id', 'leave_type_id', 'from_date', 'to_date', 'from_part', 'to_part', 'days', 'reason', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['from_date' => 'date:Y-m-d', 'to_date' => 'date:Y-m-d', 'days' => 'float', 'decided_at' => 'datetime'];
    }
}
