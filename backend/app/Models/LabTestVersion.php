<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One version of a lab report template: its name, its kind and its fields, as they stood when it was written
 * down. A version is never changed afterwards; editing a template writes the next one.
 */
#[Fillable(['lab_test_id', 'version', 'name', 'kind', 'default_comment', 'parameters', 'source', 'change', 'created_by', 'created_at'])]
class LabTestVersion extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        // Reports already made point at their version to say what they were made with, so it must stay as it was.
        static::updating(fn () => throw new LogicException('A version of a lab template is never changed. Save the template to make a new version.'));
        static::deleting(fn () => throw new LogicException('A version of a lab template is never removed.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['parameters' => 'array', 'created_at' => 'datetime:Y-m-d H:i'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
