<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;

/** What an expense is filed under, and above what amount it needs a second approval. */
class ExpenseCategory extends Model
{
    use BranchScoped;

    protected $table = 'wf_expense_categories';

    protected $fillable = ['branch_id', 'name', 'approval_above', 'is_payroll', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['approval_above' => 'decimal:2', 'is_payroll' => 'boolean', 'is_active' => 'boolean'];
    }
}
