<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;

/** An expense that comes round every month. It offers a draft; it never posts by itself. */
class ExpenseTemplate extends Model
{
    use BranchScoped;

    protected $table = 'wf_expense_templates';

    protected $fillable = ['branch_id', 'name', 'category_id', 'department_id', 'paid_to', 'amount', 'mode', 'day_of_month', 'is_active', 'last_offered_period'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
