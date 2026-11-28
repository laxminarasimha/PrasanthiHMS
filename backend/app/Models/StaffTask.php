<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** One thing one person has to do: written by them, or given to them. */
#[Fillable(['branch_id', 'title', 'notes', 'assigned_to', 'created_by', 'due_on', 'important', 'repeats', 'done_at', 'done_by', 'done_note', 'cancelled_at', 'cancelled_by'])]
class StaffTask extends Model
{
    public const REPEATS = ['none', 'daily', 'weekly', 'monthly'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['due_on' => 'date:Y-m-d', 'important' => 'boolean', 'done_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function giver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function finisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    /** Still to be done: not ticked off and not withdrawn. */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('done_at')->whereNull('cancelled_at');
    }

    /** Due today or already late. */
    public function scopePressing(Builder $query): void
    {
        $query->whereNull('done_at')->whereNull('cancelled_at')->whereNotNull('due_on')->whereDate('due_on', '<=', today());
    }

    /**
     * The day the next one of a repeating task falls on, counted from the later of its own day and today, so a
     * daily task that was missed for a week does not come back seven times.
     */
    public function nextDue(): ?Carbon
    {
        if ($this->repeats === 'none') {
            return null;
        }
        $from = ($this->due_on && $this->due_on->gt(today()) ? $this->due_on : today())->copy();

        return match ($this->repeats) {
            'daily' => $from->addDay(),
            'weekly' => $from->addWeek(),
            'monthly' => $from->addMonthNoOverflow(),
            default => null,
        };
    }
}
