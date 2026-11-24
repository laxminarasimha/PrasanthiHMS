<?php

namespace App\Support;

use App\Models\LabTest;
use App\Models\LabTestVersion;
use App\Models\User;

/**
 * The versions of a lab report template.
 *
 * What a report reads as is decided by the template's name, its kind, its usual comment and its fields (their
 * names, order, units, normal ranges and methods). Whenever any of those changes, the next version is written
 * down; the earlier ones stay as they were, and each test on an order says which version it was made with.
 * Price, department, sample type and the like are filing, not content, and make no new version.
 */
class LabTemplate
{
    /**
     * The template as it stands, in the shape a version is kept in.
     *
     * @return array{name: string, kind: string, default_comment: ?string, parameters: list<array<string, mixed>>}
     */
    public static function content(LabTest $test): array
    {
        return [
            'name' => trim((string) $test->name),
            'kind' => $test->kind ?: 'values',
            'default_comment' => self::text($test->default_comment),
            'parameters' => $test->parameters->map(fn ($p) => self::field($p->name, $p->is_heading, $p->unit, $p->reference_range, $p->method, $p->ranges))->values()->all(),
        ];
    }

    /**
     * The version that matches the template as it stands. If the template has changed since the last version was
     * written down, or none ever was, the next one is written down first.
     *
     * `$source` says how a change got here: "saved" from the template screen, otherwise "found", for a template
     * changed some other way (loaded from a sheet, or corrected in the database).
     */
    public static function current(LabTest $test, ?User $by = null, string $source = 'found'): LabTestVersion
    {
        $now = self::content($test);
        $latest = LabTestVersion::where('lab_test_id', $test->id)->orderByDesc('version')->first();

        if ($latest && self::of($latest) === $now) {
            $version = $latest;
        } else {
            $version = LabTestVersion::create([
                'lab_test_id' => $test->id, 'version' => ($latest?->version ?? 0) + 1, ...$now,
                'source' => $latest ? $source : ($source === 'saved' ? 'saved' : 'start'),
                'change' => $latest ? self::differences(self::of($latest), $now) : ($source === 'saved' ? 'The first version of this template.' : 'The template as it stood when it was first used.'),
                'created_by' => $by?->id, 'created_at' => now(),
            ]);
        }
        if ((int) $test->version !== (int) $version->version) {
            $test->forceFill(['version' => $version->version])->saveQuietly();
        }

        return $version;
    }

    /** The content of a version, in the same shape as `content()`, so the two can be compared. */
    private static function of(LabTestVersion $version): array
    {
        return [
            'name' => trim((string) $version->name),
            'kind' => $version->kind ?: 'values',
            'default_comment' => self::text($version->default_comment),
            'parameters' => array_map(
                fn (array $p) => self::field($p['name'] ?? '', $p['is_heading'] ?? false, $p['unit'] ?? null, $p['reference_range'] ?? null, $p['method'] ?? null, $p['ranges'] ?? null),
                array_values($version->parameters ?? []),
            ),
        ];
    }

    /**
     * One field of a template, in the shape a version keeps it in. The ranges by age and sex are written down only
     * when the field has some, so templates from before they existed read exactly as they did.
     */
    private static function field(mixed $name, mixed $heading, mixed $unit, mixed $range, mixed $method, mixed $ranges): array
    {
        $ranges = LabRanges::clean($ranges);

        return [
            'name' => trim((string) $name), 'is_heading' => (bool) $heading, 'unit' => self::text($unit),
            'reference_range' => self::text($range), 'method' => self::text($method),
        ] + ($ranges ? ['ranges' => $ranges] : []);
    }

    /** What differs between two versions, in words a person in the lab would use. */
    public static function differences(array $was, array $now): string
    {
        $said = [];
        if ($was['name'] !== $now['name']) {
            $said[] = "Renamed from “{$was['name']}”";
        }
        if ($was['kind'] !== $now['kind']) {
            $said[] = $now['kind'] === 'text' ? 'Made a written report' : 'Made a report of values';
        }

        $before = collect($was['parameters'])->keyBy('name');
        $after = collect($now['parameters'])->keyBy('name');
        $list = fn ($names) => $names->implode(', ');
        if (($added = $after->keys()->diff($before->keys()))->isNotEmpty()) {
            $said[] = 'Added: '.$list($added);
        }
        if (($removed = $before->keys()->diff($after->keys()))->isNotEmpty()) {
            $said[] = 'Removed: '.$list($removed);
        }
        $changed = $after->keys()->intersect($before->keys())->map(function (string $name) use ($before, $after) {
            $parts = collect(['unit' => 'unit', 'reference_range' => 'normal range', 'ranges' => 'ranges by age and sex', 'method' => 'method', 'is_heading' => 'heading'])
                ->filter(fn ($label, $key) => ($before[$name][$key] ?? null) !== ($after[$name][$key] ?? null));

            return $parts->isEmpty() ? null : $name.' ('.$parts->implode(', ').')';
        })->filter();
        if ($changed->isNotEmpty()) {
            $said[] = 'Changed: '.$list($changed);
        }
        $kept = $after->keys()->intersect($before->keys())->values()->all();
        if ($kept !== $before->keys()->intersect($after->keys())->values()->all()) {
            $said[] = 'Fields put in a different order';
        }
        if ($was['default_comment'] !== $now['default_comment']) {
            $said[] = 'Usual comment changed';
        }

        return mb_substr(($said ? implode('. ', $said) : 'Changed').'.', 0, 1000);
    }

    private static function text(mixed $value): ?string
    {
        return filled($value) ? trim((string) $value) : null;
    }
}
