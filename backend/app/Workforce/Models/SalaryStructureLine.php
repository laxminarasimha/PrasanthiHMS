<?php

namespace App\Workforce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One part of a person's pay: an amount or a percentage. */
class SalaryStructureLine extends Model
{
    protected $table = 'wf_salary_structure_lines';

    protected $fillable = ['structure_id', 'component_id', 'amount', 'percent'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'percent' => 'decimal:4'];
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(SalaryComponent::class, 'component_id');
    }
}
