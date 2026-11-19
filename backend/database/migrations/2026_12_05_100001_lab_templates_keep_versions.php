<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lab report templates keep their versions. Each time a template is edited a new version is written down and the
 * old ones are left as they were; each test on an order records the version it was made with.
 *
 * Nothing in a report already made is touched: no result line is added, changed or removed. The reports made
 * before today already hold the fields they were made with, so their versions are worked out from those: reports
 * of a test that hold a different set of fields from the template as it stands are an earlier version of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_test_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lab_test_id')->constrained();
            $table->unsignedInteger('version');
            $table->string('name');
            $table->string('kind', 20)->default('values');
            $table->text('default_comment')->nullable();
            $table->json('parameters'); // [{name, is_heading, unit, reference_range, method}] in report order
            // How the version came to be written down: saved (edited on the template screen), start (the template as
            // it stood when versions began), reports (rebuilt from earlier reports), found (changed some other way).
            $table->string('source', 20)->default('saved');
            $table->string('change', 1000)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->unique(['lab_test_id', 'version']);
        });
        Schema::table('lab_tests', fn (Blueprint $table) => $table->unsignedInteger('version')->default(1));
        Schema::table('lab_order_items', fn (Blueprint $table) => $table->unsignedInteger('template_version')->nullable());

        $rebuilt = [];
        foreach (DB::table('lab_tests')->orderBy('id')->get(['id', 'name', 'kind', 'default_comment']) as $test) {
            $now = $this->fields(DB::table('lab_test_parameters')->where('lab_test_id', $test->id)->orderBy('position')->orderBy('id')->get(), 'name');
            $nowKind = $test->kind ?: 'values';

            // Reports of this test, oldest first, sorted by the set of fields each one holds.
            $same = [];
            $earlier = [];
            foreach (DB::table('lab_order_items')->where('lab_test_id', $test->id)->orderBy('id')->get(['id', 'test_name', 'kind', 'created_at'])->chunk(300) as $items) {
                $results = DB::table('lab_results')->whereIn('lab_order_item_id', $items->pluck('id'))->orderBy('position')->orderBy('id')
                    ->get(['lab_order_item_id', 'parameter', 'is_heading', 'unit', 'reference_range', 'method'])->groupBy('lab_order_item_id');
                foreach ($items as $item) {
                    $held = $this->fields($results->get($item->id, collect()), 'parameter');
                    $kind = $item->kind ?: 'values';
                    if ($kind === $nowKind && $held === $now) {
                        $same[] = $item->id;

                        continue;
                    }
                    $key = md5(json_encode([$kind, $held]));
                    $earlier[$key] ??= ['name' => $item->test_name, 'kind' => $kind, 'parameters' => $held, 'at' => $item->created_at, 'items' => []];
                    $earlier[$key]['items'][] = $item->id;
                }
            }

            $number = 0;
            foreach ($earlier as $old) {
                $number++;
                $reports = count($old['items']);
                DB::table('lab_test_versions')->insert([
                    'lab_test_id' => $test->id, 'version' => $number, 'name' => $old['name'], 'kind' => $old['kind'], 'default_comment' => null,
                    'parameters' => json_encode($old['parameters']), 'source' => 'reports',
                    'change' => 'Rebuilt from the '.($reports === 1 ? 'one report' : "{$reports} reports").' made with it before versions were kept.',
                    'created_at' => $old['at'],
                ]);
                foreach (array_chunk($old['items'], 500) as $ids) {
                    DB::table('lab_order_items')->whereIn('id', $ids)->update(['template_version' => $number]);
                }
                $rebuilt[] = "  {$test->name}: version {$number} rebuilt from {$reports} earlier ".($reports === 1 ? 'report' : 'reports').' ('.$this->count($old['parameters']).')';
            }

            $number++;
            DB::table('lab_test_versions')->insert([
                'lab_test_id' => $test->id, 'version' => $number, 'name' => $test->name, 'kind' => $nowKind, 'default_comment' => filled($test->default_comment) ? $test->default_comment : null,
                'parameters' => json_encode($now), 'source' => 'start', 'change' => 'The template as it stood when versions began to be kept.', 'created_at' => now(),
            ]);
            foreach (array_chunk($same, 500) as $ids) {
                DB::table('lab_order_items')->whereIn('id', $ids)->update(['template_version' => $number]);
            }
            DB::table('lab_tests')->where('id', $test->id)->update(['version' => $number]);
            if ($number > 1) {
                $rebuilt[] = "  {$test->name}: version {$number} is the template now (".$this->count($now).')';
            }
        }

        // Run by hand against the hospital's database, so say what was found. Counts only, nothing about any patient.
        if ($rebuilt !== []) {
            echo PHP_EOL.implode(PHP_EOL, $rebuilt).PHP_EOL;
        }
    }

    /**
     * A set of fields in the one shape versions are kept in, whether read from a template or from a report.
     *
     * @return list<array{name: string, is_heading: bool, unit: ?string, reference_range: ?string, method: ?string}>
     */
    private function fields(Collection $rows, string $name): array
    {
        $text = fn ($value) => filled($value) ? trim((string) $value) : null;

        return $rows->map(fn ($row) => [
            'name' => trim((string) $row->{$name}), 'is_heading' => (bool) $row->is_heading, 'unit' => $text($row->unit),
            'reference_range' => $text($row->reference_range), 'method' => $text($row->method),
        ])->values()->all();
    }

    private function count(array $parameters): string
    {
        $fields = count(array_filter($parameters, fn ($p) => ! $p['is_heading']));

        return $fields === 1 ? '1 field' : "{$fields} fields";
    }

    public function down(): void
    {
        Schema::table('lab_order_items', fn (Blueprint $table) => $table->dropColumn('template_version'));
        Schema::table('lab_tests', fn (Blueprint $table) => $table->dropColumn('version'));
        Schema::dropIfExists('lab_test_versions');
    }
};
