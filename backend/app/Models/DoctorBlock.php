<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** A day, run of days, or part of each of those days when a doctor is not seeing patients. */
#[Fillable(['doctor_id', 'from_date', 'to_date', 'start_time', 'end_time', 'reason', 'created_by'])]
class DoctorBlock extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['from_date' => 'date:Y-m-d', 'to_date' => 'date:Y-m-d'];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    /** Blocks that touch any day from $from to $to. */
    public function scopeBetween(Builder $query, string $from, string $to): void
    {
        $query->whereDate('from_date', '<=', $to)->whereDate('to_date', '>=', $from);
    }

    /** Whether a moment falls inside this block. */
    public function covers(Carbon $at): bool
    {
        $day = $at->toDateString();
        if ($day < $this->from_date->toDateString() || $day > $this->to_date->toDateString()) {
            return false;
        }

        return ! $this->start_time || ($at->format('H:i') >= $this->start_time && $at->format('H:i') < $this->end_time);
    }

    /** "on leave all day" or "busy 10:30 AM – 12:00 PM (Surgery)", for messages to the desk. */
    public function describe(): string
    {
        $when = $this->start_time
            ? 'from '.Carbon::parse($this->start_time)->format('g:i A').' to '.Carbon::parse($this->end_time)->format('g:i A')
            : 'all day';

        return 'not available '.$when.($this->reason ? " ({$this->reason})" : '');
    }
}
