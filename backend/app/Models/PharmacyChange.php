<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One edit to a saved pharmacy record, kept for the "modified" reports. */
#[Fillable(['kind', 'subject_id', 'label', 'summary', 'reason', 'created_by'])]
class PharmacyChange extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Notes each value that differs between two sets as "Label: old → new".
     *
     * @param  array<string, string>  $labels  field => label shown in the report
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<int, string>
     */
    public static function differences(array $labels, array $before, array $after, string $prefix = ''): array
    {
        $show = fn ($v) => match (true) {
            $v === null || $v === '' => '—',
            is_bool($v) => $v ? 'Yes' : 'No',
            is_float($v) => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'),
            default => (string) $v,
        };

        $lines = [];
        foreach ($labels as $field => $label) {
            $old = $show($before[$field] ?? null);
            $new = $show($after[$field] ?? null);
            if ($old !== $new) {
                $lines[] = "{$prefix}{$label}: {$old} → {$new}";
            }
        }

        return $lines;
    }
}
