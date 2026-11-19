<?php

namespace App\Workforce\Models;

use App\Workforce\Support\AppendOnly;
use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of the record of who did what. Written through App\Workforce\Support\Audit; never changed, never removed.
 */
class AuditEvent extends Model
{
    use AppendOnly, BranchScoped;

    protected $table = 'wf_audit_events';

    public $timestamps = false;

    protected $fillable = ['branch_id', 'actor_id', 'actor_name', 'action', 'subject_type', 'subject_id', 'employee_id', 'reason', 'before', 'after', 'ip', 'previous_hash', 'hash', 'created_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime:Y-m-d H:i:s'];
    }
}
