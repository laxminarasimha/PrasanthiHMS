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
