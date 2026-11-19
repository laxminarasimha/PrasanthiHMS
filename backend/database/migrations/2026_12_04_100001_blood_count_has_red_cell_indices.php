<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The blood count report had no place to type MCV, MCH and MCHC, which the analyser prints with every count.
 * They are added to the blood count template after the red cell count, and a haemoglobin unit entered as
 * "mg/dl" is put right. Orders already made keep the fields they were made with.
 */
return new class extends Migration
{
    /** The three fields, in the order they are read off the analyser slip. */
    private const INDICES = [
        ['name' => 'MCV', 'unit' => 'fL', 'reference_range' => '83 - 101', 'has' => '/\bMCV\b|mean (corpuscular|cell) volume/i'],
        ['name' => 'MCH', 'unit' => 'pg', 'reference_range' => '27 - 32', 'has' => '/\bMCH\b|mean (corpuscular|cell) ha?emoglobin(?! conc)/i'],
        ['name' => 'MCHC', 'unit' => 'g/dL', 'reference_range' => '31.5 - 34.5', 'has' => '/\bMCHC\b|ha?emoglobin conc/i'],
    ];

    public function up(): void
    {
        $tests = DB::table('lab_tests')->where('kind', 'values')
            ->where(fn ($q) => $q->where('name', 'like', '%Complete Blood Picture%')->orWhere('name', 'like', '%Complete Blood Count%')
                ->orWhere('name', 'like', '%Hemogram%')->orWhere('name', 'like', '%Haemogram%')->orWhereIn('name', ['CBC', 'CBP']))
            ->orderBy('id')->get(['id', 'name']);

        foreach ($tests as $test) {
            $fields = DB::table('lab_test_parameters')->where('lab_test_id', $test->id)->orderBy('position')->orderBy('id')->get();
            if ($fields->isEmpty()) {
                continue;
            }

            $unit = DB::table('lab_test_parameters')->where('lab_test_id', $test->id)->where('is_heading', false)
                ->where(fn ($q) => $q->where('name', 'like', 'Haemoglobin%')->orWhere('name', 'like', 'Hemoglobin%'))
                ->whereRaw('LOWER(TRIM(unit)) = ?', ['mg/dl'])->update(['unit' => 'g/dL', 'updated_at' => now()]);

            $missing = array_values(array_filter(self::INDICES, fn (array $index) => ! $fields->contains(fn ($f) => preg_match($index['has'], $f->name) === 1)));
            if ($missing !== []) {
                $after = $this->placeAfter($fields);
                DB::table('lab_test_parameters')->where('lab_test_id', $test->id)->where('position', '>', $after)->increment('position', count($missing));
                foreach ($missing as $i => $index) {
                    DB::table('lab_test_parameters')->insert([
                        'lab_test_id' => $test->id, 'name' => $index['name'], 'is_heading' => false, 'unit' => $index['unit'],
                        'reference_range' => $index['reference_range'], 'method' => null,'position' => $after + 1 + $i,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            if ($missing !== [] || $unit > 0) {
                // Run by hand against the hospital's database, so say what was done to which template.
                echo "  {$test->name}: ".($missing === [] ? 'already had MCV, MCH and MCHC' : 'added '.implode(', ', array_column($missing, 'name')))
                    .($unit > 0 ? '; haemoglobin unit set to g/dL' : '').PHP_EOL;
            }
        }
    }

    /**
     * The position the new fields follow: the red cell count they are worked out from; failing that the packed
     * cell volume; failing that the last field above the first heading.
     */
    private function placeAfter($fields): int
    {
        $heading = $fields->first(fn ($f) => (bool) $f->is_heading);
        $top = $heading ? $fields->filter(fn ($f) => $f->position < $heading->position)->values() : $fields;

        foreach (['/red (blood )?cell count|\bRBC\b/i', '/\bPCV\b|ha?ematocrit/i'] as $pattern) {
            if ($found = $top->first(fn ($f) => ! $f->is_heading && preg_match($pattern, $f->name) === 1)) {
                return (int) $found->position;
            }
        }

        return $top->isEmpty() ? (int) $heading->position - 1 : (int) $top->max('position');
    }

    public function down(): void
    {
        // The fields are the lab's to keep or remove under Report templates; nothing is taken back here.
    }
};
