<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;

/** One line of an attendance sheet, with what was made of it. */
class AttendanceImportRow extends Model
{
    use BranchScoped;

    protected $table = 'wf_attendance_import_rows';

    protected $fillable = ['import_id', 'branch_id', 'source_key', 'payload_hash', 'employee_ref', 'staff_name', 'employee_id', 'work_date', 'check_in', 'check_out', 'state', 'attendance_id', 'note'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['work_date' => 'date:Y-m-d', 'check_in' => 'datetime:Y-m-d H:i:s', 'check_out' => 'datetime:Y-m-d H:i:s'];
    }
}
