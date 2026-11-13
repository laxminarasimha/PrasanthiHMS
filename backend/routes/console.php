<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
 * Brings the remote dev database's tables up to date with the code.
 * It only adds or alters tables through migrations; it never copies or deletes rows.
 */
Artisan::command('db:sync-remote {--status : Only show what would be applied}', function () {
    if (! config('database.connections.remote.host')) {
        $this->error('REMOTE_DB_HOST is not set in .env.');

        return 1;
    }

    try {
        DB::connection('remote')->getPdo();
    } catch (Throwable $e) {
        $this->error('Could not reach the remote database: '.$e->getMessage());
        $this->line('If your internet address changed, add the new one under Remote MySQL in the hosting panel.');

        return 1;
    }

    $this->info('Connected to '.config('database.connections.remote.database').' on '.config('database.connections.remote.host'));

    return $this->call($this->option('status') ? 'migrate:status' : 'migrate', ['--database' => 'remote'] + ($this->option('status') ? [] : ['--force' => true]));
})->purpose('Apply new migrations to the remote dev database');

/*
 * Creates (or resets) an admin login, asking for the password in the terminal so it is
 * never written down. With --remote it acts on the hosting database; --disable-samples
 * also switches off the seeded @prasanthi.test logins, which have a publicly known password.
 */
Artisan::command('hms:admin {email} {--name=Administrator} {--remote : Act on the remote database} {--disable-samples : Deactivate the sample logins}', function () {
    $connection = $this->option('remote') ? 'remote' : config('database.default');
    $email = strtolower(trim($this->argument('email')));

    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('That is not a valid email address.');

        return 1;
    }

    $password = (string) $this->secret('Password for '.$email.' (at least 10 characters)');
    if (strlen($password) < 10 || $password !== (string) $this->secret('Type it again')) {
        $this->error('The passwords did not match or were shorter than 10 characters. Nothing was changed.');

        return 1;
    }

    $users = App\Models\User::on($connection);
    $admin = $users->firstOrNew(['email' => $email]);
    $admin->setConnection($connection);
    $admin->fill([
        'name' => $admin->exists ? $admin->name : $this->option('name'),
        'password' => $password,
        'role' => 'admin',
        'is_active' => true,
        'gender' => $admin->gender ?? 'other',
        'phone' => $admin->phone ?? '0000000000',
        'branch_id' => $admin->branch_id ?? App\Models\Branch::on($connection)->value('id'),
    ])->save();
    $this->info(($admin->wasRecentlyCreated ? 'Created' : 'Updated').' admin '.$email.' on the '.$connection.' database.');

    if ($this->option('disable-samples')) {
        $samples = App\Models\User::on($connection)->where('email', 'like', '%@prasanthi.test')->where('email', '!=', $email);
        $ids = $samples->pluck('id');
        $samples->update(['is_active' => false]);
        DB::connection($connection)->table('personal_access_tokens')->whereIn('tokenable_id', $ids)->delete();
        $this->info('Deactivated '.$ids->count().' sample login(s).');
    }

    return 0;
})->purpose('Create or reset an admin login');

/*
 * Loads lab tests (with their report templates) and medicines from a JSON file of master lists,
 * such as the hospital's lists from its previous system. Names already present are left alone,
 * so it is safe to run twice. With --remote it loads into the hosting database.
 *
 * The file: {"tests": [{name, department, sample_type, price, print_order, kind, default_comment, is_active,
 * parameters: [{name, unit, reference_range, method, is_heading}]}], "medicines": [{name, type,
 * manufacturer, category, is_scheduled, gst_percent, units_per_pack, reorder_level}]}
 */
Artisan::command('hms:import-lists {file} {--remote : Load into the remote database} {--dry-run : Only count what would be added}', function () {
    $connection = $this->option('remote') ? 'remote' : config('database.default');
    $path = $this->argument('file');
    $lists = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (! is_array($lists)) {
        $this->error('Could not read a list file at '.$path);

        return 1;
    }

    $db = DB::connection($connection);
    $clip = fn ($value, int $length) => ($value = trim((string) ($value ?? ''))) === '' ? null : mb_substr($value, 0, $length);
    $key = fn (string $name) => mb_strtolower(preg_replace('/\s+/', ' ', trim($name)));
    $now = now();
    $added = ['tests' => 0, 'parameters' => 0, 'medicines' => 0];
    $skipped = ['tests' => 0, 'medicines' => 0];

    $db->transaction(function () use ($lists, $db, $clip, $key, $now, &$added, &$skipped) {
        $have = $db->table('lab_tests')->pluck('name')->map($key)->flip();
        foreach ($lists['tests'] ?? [] as $test) {
            $name = $clip($test['name'] ?? '', 255);
            if (! $name || $have->has($key($name))) {
                $skipped['tests']++;

                continue;
            }
            $have[$key($name)] = true;
            $added['tests']++;
            $parameters = array_values(array_filter($test['parameters'] ?? [], fn ($p) => filled($p['name'] ?? null)));
            if ($this->option('dry-run')) {
                $added['parameters'] += count($parameters);

                continue;
            }

            $id = $db->table('lab_tests')->insertGetId([
                'name' => $name, 'kind' => ($test['kind'] ?? 'values') === 'text' ? 'text' : 'values',
                'department' => $clip($test['department'] ?? null, 255), 'sample_type' => $clip($test['sample_type'] ?? null, 50),
                'price' => round((float) ($test['price'] ?? 0), 2), 'print_order' => min(65000, max(0, (int) ($test['print_order'] ?? 0))),
                'default_comment' => $clip($test['default_comment'] ?? null, 5000), 'is_active' => (bool) ($test['is_active'] ?? true), 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($parameters as $position => $parameter) {
                $db->table('lab_test_parameters')->insert([
                    'lab_test_id' => $id, 'name' => $clip($parameter['name'], 255), 'is_heading' => (bool) ($parameter['is_heading'] ?? false),
                    'unit' => $clip($parameter['unit'] ?? null, 30), 'reference_range' => $clip($parameter['reference_range'] ?? null, 255),
                    'method' => $clip($parameter['method'] ?? null, 255), 'position' => $position, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $added['parameters']++;
            }
        }

        $have = $db->table('medicines')->pluck('name')->map($key)->flip();
        foreach ($lists['medicines'] ?? [] as $medicine) {
            $name = $clip($medicine['name'] ?? '', 255);
            if (! $name || $have->has($key($name))) {
                $skipped['medicines']++;

                continue;
            }
            $have[$key($name)] = true;
            $added['medicines']++;
            if ($this->option('dry-run')) {
                continue;
            }

            $db->table('medicines')->insert([
                'name' => $name, 'type' => $clip($medicine['type'] ?? null, 30), 'manufacturer' => $clip($medicine['manufacturer'] ?? null, 255),
                'category' => $clip($medicine['category'] ?? null, 255), 'is_scheduled' => (bool) ($medicine['is_scheduled'] ?? false),
                'gst_percent' => (float) ($medicine['gst_percent'] ?? 0), 'units_per_pack' => max(1, (int) ($medicine['units_per_pack'] ?? 1)),
                'reorder_level' => max(0, (int) ($medicine['reorder_level'] ?? 0)), 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // Keep the manufacturer pick list in step with the medicines just loaded.
        if (! $this->option('dry-run')) {
            $known = $db->table('manufacturers')->pluck('name')->map($key)->flip();
            foreach ($db->table('medicines')->whereNotNull('manufacturer')->distinct()->pluck('manufacturer') as $manufacturer) {
                if (! $known->has($key($manufacturer))) {
                    $known[$key($manufacturer)] = true;
                    $db->table('manufacturers')->insert(['name' => trim($manufacturer), 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
    });

    $verb = $this->option('dry-run') ? 'Would add' : 'Added';
    $this->info("{$verb} on the {$connection} database: {$added['tests']} lab tests with {$added['parameters']} template rows, and {$added['medicines']} medicines.");
    $this->line("Left alone because the name is already there or blank: {$skipped['tests']} tests, {$skipped['medicines']} medicines.");

    return 0;
})->purpose('Load lab tests and medicines from a JSON file of master lists');

/*
 * Removes practice records so the hospital can start with real ones: every patient and everything
 * recorded against a patient (visits, bills, lab orders, pharmacy bills, admissions, deposits, forms).
 * Price lists, lab tests, medicines, beds, staff and settings are kept. Numbers start again from 1.
 *
 * Without --go it only counts. With --go it asks you to type CLEAR first. --stock also empties
 * pharmacy stock and purchases. --remote acts on the hosting database.
 */
Artisan::command('hms:clear-test-data {--remote : Act on the remote database} {--stock : Also clear pharmacy stock, purchases and purchase orders} {--go : Really delete, after asking}', function () {
    $connection = $this->option('remote') ? 'remote' : config('database.default');
    $db = DB::connection($connection);
    $schema = Schema::connection($connection);

    $records = [
        'patients', 'patient_merges', 'patient_deposits', 'attachments', 'doctor_blocks',
        'appointments', 'consultations', 'prescription_items', 'form_records',
        'bills', 'bill_items', 'payments',
        'lab_orders', 'lab_order_items', 'lab_results', 'lab_order_files',
        'pharmacy_sales', 'pharmacy_sale_items', 'pharmacy_sale_returns', 'pharmacy_payments', 'pharmacy_changes',
        'admissions', 'admission_charges', 'admission_bills', 'admission_notes', 'admission_payments', 'discharge_summaries',
    ];
    $stock = ['stock_batches', 'stock_adjustments', 'stock_returns', 'stock_return_items', 'purchase_invoices', 'purchase_orders', 'purchase_order_items'];
    $tables = array_values(array_filter($this->option('stock') ? array_merge($records, $stock) : $records, fn ($t) => $schema->hasTable($t)));

    $counts = collect($tables)->mapWithKeys(fn ($t) => [$t => $db->table($t)->count()]);
    $samples = $db->table('users')->where('email', 'like', '%@prasanthi.test')->count();
    $this->info('Database: '.$db->getDatabaseName().' ('.$connection.')');
    $this->table(['Records', 'Rows'], $counts->filter()->map(fn ($n, $t) => [str_replace('_', ' ', $t), $n])->values()->all());
    $this->line('Total rows to remove: '.$counts->sum().'. Sample logins to remove: '.$samples.'.');
    $this->line('Kept: staff, settings, services, lab tests, medicines, beds, IPD services, templates'.($this->option('stock') ? '.' : ', and pharmacy stock (add --stock to clear that too).'));

    if (! $this->option('go')) {
        $this->comment('Nothing was changed. Run again with --go to delete these.');

        return 0;
    }
    if ($this->ask('This cannot be undone. Type CLEAR to delete these records from '.$db->getDatabaseName()) !== 'CLEAR') {
        $this->error('Not confirmed. Nothing was changed.');

        return 1;
    }

    // Emptying whole tables (rather than deleting rows) makes patient, bill and case numbers start again from 1.
    $schema->disableForeignKeyConstraints();
    try {
        foreach ($tables as $table) {
            $db->table($table)->truncate();
        }
    } finally {
        $schema->enableForeignKeyConstraints();
    }

    // The practice logins go too, unless something that was kept still points at one.
    $removed = 0;
    foreach ($db->table('users')->where('email', 'like', '%@prasanthi.test')->pluck('id') as $id) {
        try {
            $db->table('personal_access_tokens')->where('tokenable_id', $id)->delete();
            $removed += $db->table('users')->where('id', $id)->delete();
        } catch (Throwable) {
            // Left in place, switched off.
            $db->table('users')->where('id', $id)->update(['is_active' => false]);
        }
    }

    $this->info('Removed '.$counts->sum().' rows and '.$removed.' sample login(s). The price lists, medicines, lab tests and staff are untouched.');

    return 0;
})->purpose('Remove practice patients and their records before real use');

/*
 * Writes the whole database to one compressed SQL file. On the server the files live in
 * storage/app/private/backups and the app makes one a day by itself. With --remote it copies the
 * hosting database down to this computer instead (into db-export/backups, which git ignores),
 * so there is a copy that does not sit on the same server as the hospital's data.
 */
Artisan::command('hms:backup {--remote : Back up the hosting database to this computer} {--keep=30 : How many backups to keep}', function () {
    $remote = (bool) $this->option('remote');
    $folder = $remote ? base_path('../db-export/backups') : App\Support\DatabaseBackup::folder();

    try {
        $made = App\Support\DatabaseBackup::run($remote ? 'remote' : null, $folder, max(1, (int) $this->option('keep')));
    } catch (Throwable $e) {
        $this->error('Backup failed: '.$e->getMessage());

        return 1;
    }

    $this->info('Backup written: '.realpath($made['path']));
    $this->line($made['tables'].' tables, '.$made['rows'].' rows, '.round($made['size'] / 1024).' KB.');

    return 0;
})->purpose('Back up the database to a compressed SQL file');

