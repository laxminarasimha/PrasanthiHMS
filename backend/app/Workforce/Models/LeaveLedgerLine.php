<?php

namespace App\Workforce\Models;

use App\Workforce\Support\AppendOnly;
use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a leave account: days earned, taken, carried, lapsed or corrected. The balance is the sum of the lines.
 */
class LeaveLedgerLine extends Model
{
    use AppendOnly, BranchScoped;

    protected $table = 'wf_leave_ledger';

    public $timestamps = false;

    protected $fillable = ['branch_id', 'employee_id', 'leave_type_id', 'entry_date', 'leave_year', 'kind', 'days', 'leave_request_id', 'note', 'once_key', 'created_by', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['entry_date' => 'date:Y-m-d', 'days' => 'float', 'created_at' => 'datetime'];
    }
}
