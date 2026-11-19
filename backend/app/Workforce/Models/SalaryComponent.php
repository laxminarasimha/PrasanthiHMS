<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * One part a salary is made of. A statutory part starts switched off, with no rate, until the accountant enters its rule.
 */
class SalaryComponent extends Model
{
    use BranchScoped;

    protected $table = 'wf_salary_components';

    protected $fillable = ['branch_id', 'code', 'name', 'kind', 'basis', 'is_statutory', 'is_enabled', 'reduces_with_absence', 'rule', 'sort'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_statutory' => 'boolean', 'is_enabled' => 'boolean', 'reduces_with_absence' => 'boolean', 'rule' => 'array'];
    }
}
