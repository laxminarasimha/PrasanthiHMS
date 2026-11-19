<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * What an expense is filed under, and the approval it needs. `approval_above`: an expense above this amount must be
 * approved before it is paid (null: never; 0: always). `approver_limit`: above this, approving it needs "Approve large
 * expenses" (null: no upper limit). A payroll heading is the system's own: payroll and advances post into it, and
 * nobody types an expense into it by hand, so salaries are never counted twice.
 */
class ExpenseCategory extends Model
{
    use BranchScoped;

    protected $table = 'wf_expense_categories';

    protected $fillable = ['branch_id', 'name', 'approval_above', 'approver_limit', 'is_payroll', 'is_active', 'sort'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['approval_above' => 'decimal:2', 'approver_limit' => 'decimal:2', 'is_payroll' => 'boolean', 'is_active' => 'boolean'];
    }
}
