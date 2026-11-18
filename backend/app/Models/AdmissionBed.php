<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One bed a patient occupied during a stay, from when they moved in until they moved out. */
#[Fillable(['admission_id', 'bed_id', 'ward', 'bed_no', 'from_at', 'to_at', 'moved_by'])]
class AdmissionBed extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['from_at' => 'datetime:Y-m-d H:i', 'to_at' => 'datetime:Y-m-d H:i'];
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(Bed::class);
    }

    public function mover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by');
    }
}
