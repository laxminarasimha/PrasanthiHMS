<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;

/** Says that a person looks after a department's staff. */
class DepartmentManager extends Model
{
    use BranchScoped;

    protected $table = 'wf_department_managers';

    protected $fillable = ['branch_id', 'department_id', 'user_id'];
}
