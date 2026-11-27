<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'lab_test_id', 'template_version', 'test_name', 'repeat_no', 'kind', 'package_name', 'department', 'sample_type', 'price', 'remarks', 'report_text',
    'collected_at', 'collected_by', 'reported_at', 'reported_by', 'approved_at', 'approved_by',
    'outside_lab', 'outside_cost', 'sent_at', 'sent_by',
])]
class LabOrderItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['price' => 'float', 'outside_cost' => 'float', 'sent_at' => 'datetime:Y-m-d H:i', 'collected_at' => 'datetime:Y-m-d H:i', 'reported_at' => 'datetime:Y-m-d H:i', 'approved_at' => 'datetime:Y-m-d H:i'];
    }

    public function results(): HasMany
    {
        return $this->hasMany(LabResult::class)->orderBy('position');
    }

    protected $appends = ['stage'];

    /** Where the test is in the lab: booked, collected, reported or approved. */
    public function getStageAttribute(): string
    {
        return match (true) {
            $this->approved_at !== null => 'approved',
            $this->reported_at !== null => 'reported',
            $this->collected_at !== null => 'collected',
            default => 'booked',
        };
    }

    /** The test's name, with which time it is when the same test is on the order more than once: "CBP (2nd)". */
    public function label(): string
    {
        $n = (int) $this->repeat_no;
        if ($n < 1) {
            return $this->test_name;
        }
        $th = ($n % 100 >= 11 && $n % 100 <= 13) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');

        return "{$this->test_name} ({$n}{$th})";
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
