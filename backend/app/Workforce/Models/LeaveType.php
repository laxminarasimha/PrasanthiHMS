<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;

/** A kind of leave and its yearly rules. */
class LeaveType extends Model
{
    use BranchScoped;

    protected $table = 'wf_leave_types';

    protected $fillable = ['branch_id', 'code', 'name', 'is_paid', 'annual_allowance', 'accrual', 'carry_forward_max', 'carry_forward_expires_months', 'allow_half_day', 'needs_approval', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_paid' => 'boolean', 'annual_allowance' => 'float', 'carry_forward_max' => 'float', 'allow_half_day' => 'boolean', 'needs_approval' => 'boolean', 'is_active' => 'boolean'];
    }
}
