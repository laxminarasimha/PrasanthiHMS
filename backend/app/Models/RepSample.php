<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One lot of samples a medical representative left. Not for sale: kept apart from the pharmacy's stock. */
#[Fillable(['branch_id', 'rep_company_id', 'medical_rep_id', 'rep_visit_id', 'rep_product_id', 'doctor_id', 'name', 'quantity', 'unit', 'expiry', 'received_on', 'notes', 'created_by'])]
class RepSample extends Model
{
    protected $appends = ['given', 'discarded', 'in_hand'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'expiry' => 'date:Y-m-d', 'received_on' => 'date:Y-m-d'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(RepCompany::class, 'rep_company_id');
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(MedicalRep::class, 'medical_rep_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    /** Each time some of it left the shelf: given out, or thrown away. */
    public function issues(): HasMany
    {
        return $this->hasMany(RepSampleIssue::class)->orderByDesc('given_on')->orderByDesc('id');
    }

    public function getGivenAttribute(): int
    {
        return (int) $this->issues->where('kind', 'given')->sum('quantity');
    }

    public function getDiscardedAttribute(): int
    {
        return (int) $this->issues->where('kind', 'discarded')->sum('quantity');
    }

    /** What is still on the shelf. */
    public function getInHandAttribute(): int
    {
        return max(0, $this->quantity - $this->given - $this->discarded);
    }
}
