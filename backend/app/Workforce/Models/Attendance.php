<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One spell of work. It belongs to the day the shift started, even when it ended after midnight. */
class Attendance extends Model
{
    use BranchScoped;

    protected $table = 'wf_attendance';

    protected $fillable = ['branch_id', 'employee_id', 'work_date', 'shift_id', 'check_in', 'check_out', 'state', 'day_status', 'worked_minutes', 'late_minutes', 'early_minutes', 'overtime_minutes', 'origin', 'note', 'created_by', 'updated_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['work_date' => 'date:Y-m-d', 'check_in' => 'datetime:Y-m-d H:i:s', 'check_out' => 'datetime:Y-m-d H:i:s'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
