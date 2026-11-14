<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Something the doctor ordered for an inpatient: a medicine, or an instruction such as diet or nursing care. */
#[Fillable(['admission_id', 'kind', 'title', 'dose', 'route', 'frequency', 'instructions', 'started_at', 'stopped_at', 'ordered_by', 'stopped_by'])]
class AdmissionOrder extends Model
{
    public const KINDS = ['medicine', 'instruction'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['started_at' => 'datetime:Y-m-d H:i', 'stopped_at' => 'datetime:Y-m-d H:i'];
    }

    public function doses(): HasMany
    {
        return $this->hasMany(AdmissionDose::class)->orderByDesc('given_at')->orderByDesc('id');
    }

    public function orderer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ordered_by');
    }

    public function stopper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stopped_by');
    }
}
