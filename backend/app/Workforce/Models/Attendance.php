<?php

namespace App\Workforce\Models;

use App\Workforce\Support\AttendanceRules;
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

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** Works out the state, hours, lateness and overtime from the times and the shift, ready to save. */
    public function measured(): static
    {
        return $this->fill(AttendanceRules::measure(
            $this->work_date->format('Y-m-d'), $this->check_in?->format('Y-m-d H:i:s'), $this->check_out?->format('Y-m-d H:i:s'), $this->shift_id ? $this->shift : null,
        ));
    }

    /** The parts of a spell that a correction can change, as they stand, for the record of what it was before. */
    public function asRecorded(): array
    {
        return [
            'work_date' => $this->work_date?->format('Y-m-d'), 'check_in' => $this->check_in?->format('Y-m-d H:i:s'), 'check_out' => $this->check_out?->format('Y-m-d H:i:s'),
            'day_status' => $this->day_status, 'note' => $this->note,
        ];
    }
}
