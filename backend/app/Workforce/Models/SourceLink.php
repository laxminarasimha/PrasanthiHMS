<?php

namespace App\Workforce\Models;

use App\Workforce\Support\BranchScoped;
use Illuminate\Database\Eloquent\Model;

/** Which record here stands for which record in the older system, so nothing is brought in twice. */
class SourceLink extends Model
{
    use BranchScoped;

    protected $table = 'wf_source_links';

    protected $fillable = ['branch_id', 'source', 'entity', 'source_id', 'target_type', 'target_id', 'payload_hash'];
}
