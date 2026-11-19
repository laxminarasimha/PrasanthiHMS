<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;

/** One row of a snapshot, held as found (encrypted) until it is brought in, set aside or skipped. */
class ImportRecord extends Model
{
    use BranchScoped;

    protected $table = 'wf_import_records';

    protected $fillable = ['snapshot_id', 'branch_id', 'entity', 'source_id', 'payload_hash', 'payload', 'state', 'problem', 'target_type', 'target_id'];

    protected $hidden = ['payload'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'encrypted:array'];
    }
}
