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
