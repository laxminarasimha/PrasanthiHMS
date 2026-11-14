<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The record of who opened or changed what. Rows are added and never edited or removed
 * by the app, so the hospital can always answer "who saw this, who changed that".
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** What each action is called on screen. */
    public const ACTIONS = [
        'patient.registered' => 'Registered the patient',
        'patient.updated' => 'Changed patient details',
        'patient.opened' => 'Opened the patient page',
        'patient.merged' => 'Merged patient records',
        'history.opened' => 'Opened the case history',
        'visit.opened' => 'Opened a visit',
        'visit.saved' => 'Saved visit notes',
        'visit.completed' => 'Finished a visit',
        'visit.amended' => 'Added an amendment to a visit',
        'health.added' => 'Added to the health summary',
        'health.updated' => 'Changed the health summary',
        'health.removed' => 'Removed from the health summary',
        'allergies.updated' => 'Changed allergies',
        'document.issued' => 'Issued a certificate or letter',
        'document.cancelled' => 'Cancelled a certificate or letter',
        'bill.created' => 'Made a bill',
        'bill.item_added' => 'Added a line to a bill',
        'bill.item_removed' => 'Removed a line from a bill',
        'bill.payment' => 'Recorded money on a bill',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime:Y-m-d H:i:s'];
    }

    /**
     * Notes one event. "Opened" events by the same person on the same record are noted once
     * every ten minutes, so moving back and forth does not flood the log. A failure to write
     * the log never stops the work it describes.
     */
    public static function record(string $action, ?int $patientId = null, ?Model $subject = null, ?string $summary = null): void
    {
        try {
            $user = request()->user();
            if (! $user instanceof User) {
                return;
            }
            $subjectType = $subject ? class_basename($subject) : null;

            if (str_ends_with($action, '.opened') && self::where('user_id', $user->id)->where('action', $action)->where('patient_id', $patientId)
                ->where('subject_type', $subjectType)->where('subject_id', $subject?->getKey())->where('created_at', '>=', now()->subMinutes(10))->exists()) {
                return;
            }

            self::create([
                'user_id' => $user->id, 'user_name' => $user->name, 'action' => $action, 'patient_id' => $patientId,
                'subject_type' => $subjectType, 'subject_id' => $subject?->getKey(),
                'summary' => $summary ? mb_substr($summary, 0, 1000) : null, 'ip' => request()->ip(), 'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Names the fields that differ between two versions of a record, for a readable summary.
     *
     * @param  array<string, string>  $labels  field => what it is called on screen
     */
    public static function changed(array $labels, array $before, array $after): ?string
    {
        $names = [];
        foreach ($labels as $field => $label) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            if ((is_scalar($old) || $old === null ? (string) $old : json_encode($old)) !== (is_scalar($new) || $new === null ? (string) $new : json_encode($new))) {
                $names[] = $label;
            }
        }

        return $names ? 'Changed: '.implode(', ', $names) : null;
    }
}
