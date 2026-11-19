<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use App\Models\AdminEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(AdminEntry::class, 'department_id');
    }
}
