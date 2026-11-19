<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** What one person is paid, from a date. A rise is a new row; the old one is kept. */
class SalaryStructure extends Model
{
    use BranchScoped;

    protected $table = 'wf_salary_structures';

    protected $fillable = ['branch_id', 'employee_id', 'effective_from', 'effective_to', 'pay_basis', 'overtime_rate', 'note', 'created_by', 'approved_by', 'approved_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d', 'overtime_rate' => 'decimal:2', 'approved_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalaryStructureLine::class, 'structure_id');
    }
}
