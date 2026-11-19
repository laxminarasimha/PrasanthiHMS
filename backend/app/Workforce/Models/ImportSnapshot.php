<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One copy taken from an older system at one moment, with its fingerprint and what became of it. */
class ImportSnapshot extends Model
{
    use BranchScoped;

    protected $table = 'wf_import_snapshots';

    protected $fillable = ['branch_id', 'source', 'label', 'taken_at', 'sha256', 'counts', 'status', 'report', 'created_by', 'approved_by', 'approved_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['counts' => 'array', 'report' => 'array', 'taken_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function records(): HasMany
    {
        return $this->hasMany(ImportRecord::class, 'snapshot_id');
    }
}
