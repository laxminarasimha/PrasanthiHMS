<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;

/** A change asked for, or made, to a spell of work: with the reason, and what it was and what it became. */
class AttendanceCorrection extends Model
{
    use BranchScoped;

    protected $table = 'wf_attendance_corrections';

    protected $fillable = ['branch_id', 'employee_id', 'attendance_id', 'work_date', 'reason', 'before', 'after', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['work_date' => 'date:Y-m-d', 'before' => 'array', 'after' => 'array', 'decided_at' => 'datetime'];
    }
}
