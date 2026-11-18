<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Writes the whole database to one compressed SQL file, using only PHP, because shared
 * hosting gives neither a shell nor mysqldump. The file can be loaded back with phpMyAdmin
 * or the mysql command to rebuild every table and row.
 */
class DatabaseBackup
{
    /** Tables whose rows are not worth keeping: they are rebuilt as the app runs. */
    private const STRUCTURE_ONLY = ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'];

    public const KEEP = 30;

    public static function folder(): string
    {
        return storage_path('app/private/backups');
    }

    /**
     * Makes a backup of the given connection and returns what was written.
     *
     * @return array{name: string, path: string, size: int, tables: int, rows: int}
     */
    public static function run(?string $connection = null, ?string $folder = null, int $keep = self::KEEP): array
    {
        $connection ??= config('database.default');
        $folder ??= self::folder();
        if (! is_dir($folder) && ! mkdir($folder, 0700, true) && ! is_dir($folder)) {
            throw new RuntimeException('Could not create the backup folder.');
        }
        // Belt and braces on Apache, should this folder ever end up inside the web root.
        if (! is_file($folder.'/.htaccess')) {
            @file_put_contents($folder.'/.htaccess', "Require all denied\n");
        }

        $db = DB::connection($connection);
        $pdo = $db->getPdo();
        $driver = $db->getDriverName();
        $name = 'hms-'.now()->format('Y-m-d-His').'.sql.gz';
        $path = $folder.'/'.$name;
        $partial = $path.'.part';

        $out = gzopen($partial, 'wb6');
        if (! $out) {
            throw new RuntimeException('Could not write the backup file.');
        }

        $tables = 0;
        $rows = 0;
        try {
            gzwrite($out, '-- Prasanthi HMS backup of '.$db->getDatabaseName().' made '.now()->format('Y-m-d H:i:s')."\n");
            gzwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

            // Only this database's own tables, even if the login can see others on the same server.
            foreach (Schema::connection($connection)->getTables($driver === 'sqlite' ? null : $db->getDatabaseName()) as $table) {
                $t = $table['name'];
                if ($driver === 'sqlite' && str_starts_with($t, 'sqlite_')) {
                    continue;
                }
                $quoted = '`'.str_replace('`', '``', $t).'`';
                $create = $driver === 'mysql' || $driver === 'mariadb'
                    ? array_values((array) $db->selectOne("SHOW CREATE TABLE {$quoted}"))[1]
                    : $db->selectOne('select sql from sqlite_master where type = ? and name = ?', ['table', $t])->sql;
                gzwrite($out, "DROP TABLE IF EXISTS {$quoted};\n{$create};\n\n");
                $tables++;

                if (in_array($t, self::STRUCTURE_ONLY, true)) {
                    continue;
                }

                $batch = [];
                $columns = null;
                $flush = function () use (&$batch, &$columns, $out, $quoted) {
                    if ($batch) {
                        gzwrite($out, "INSERT INTO {$quoted} ({$columns}) VALUES\n".implode(",\n", $batch).";\n");
                        $batch = [];
                    }
                };
                foreach ($db->table($t)->cursor() as $row) {
                    $row = (array) $row;
                    $columns ??= implode(', ', array_map(fn ($c) => '`'.str_replace('`', '``', $c).'`', array_keys($row)));
                    $batch[] = '('.implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v)), $row)).')';
                    $rows++;
                    if (count($batch) >= 200) {
                        $flush();
                    }
                }
                $flush();
                gzwrite($out, "\n");
            }

            gzwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n-- End of backup: {$tables} tables, {$rows} rows\n");
        } catch (Throwable $e) {
            gzclose($out);
            @unlink($partial);
            throw $e;
        }
        gzclose($out);
        rename($partial, $path);
        @chmod($path, 0600);

        self::prune($folder, $keep);

        return ['name' => $name, 'path' => $path, 'size' => (int) filesize($path), 'tables' => $tables, 'rows' => $rows];
    }

    /**
     * The backups on disk, newest first.
     *
     * @return list<array{name: string, size: int, made_at: string}>
     */
    public static function all(?string $folder = null): array
    {
        $files = glob(($folder ?? self::folder()).'/hms-*.sql.gz') ?: [];
        rsort($files);

        return array_map(fn ($f) => ['name' => basename($f), 'size' => (int) filesize($f), 'made_at' => date('Y-m-d H:i', (int) filemtime($f))], $files);
    }

    /** Keeps the newest backups and removes the rest, so the disk does not fill up. */
    public static function prune(string $folder, int $keep): void
    {
        foreach (array_slice(self::all($folder), max(1, $keep)) as $old) {
            @unlink($folder.'/'.$old['name']);
        }
    }

    /**
     * Makes today's backup if there is not one yet. Called after a page has been served,
     * so the hospital gets a daily backup without anyone having to set up a timer on the host.
     */
    public static function runIfDue(): void
    {
        $today = now()->toDateString();
        try {
            if (Cache::get('backup_day') === $today) {
                return;
            }
            Cache::lock('backup_running', 600)->get(function () use ($today) {
                $newest = self::all()[0]['made_at'] ?? '';
                if (! str_starts_with($newest, $today)) {
                    // A run that the host cut short dies without a word. It leaves a half-written file behind, and that
                    // is how the next visit knows. After three tries the day is given up, and the admin is told.
                    $tries = (int) Cache::get("backup_tries_{$today}", 0);
                    $cutShort = self::clearUnfinished();
                    if ($tries >= 3) {
                        Cache::put('backup_error', 'The daily backup did not finish on '.now()->format('j M Y').($cutShort ? ': the server stopped it part-way, three times.' : '.'), now()->addDays(14));
                        Cache::put('backup_day', $today, now()->addDay());
                        Cache::forget('backup_alert');

                        return;
                    }
                    Cache::put("backup_tries_{$today}", $tries + 1, now()->addDay());
                    // The page has already gone out, so this may take the time it needs.
                    @set_time_limit(600);
                    @ignore_user_abort(true);
                    self::run();
                    Cache::forget('backup_error');
                    Cache::forget('backup_alert');
                }
                Cache::put('backup_day', $today, now()->addDay());
            });
        } catch (Throwable $e) {
            // A failed backup must never break the page that happened to trigger it.
            Cache::put('backup_error', 'The daily backup failed on '.now()->format('j M Y').': '.mb_substr($e->getMessage(), 0, 200), now()->addDays(14));
            Cache::forget('backup_alert');
            report($e);
        }
    }

    /** Removes half-written backups left by a run that was cut short, and says whether there were any. */
    public static function clearUnfinished(?string $folder = null): bool
    {
        $found = false;
        foreach (glob(($folder ?? self::folder()).'/hms-*.sql.gz.part') ?: [] as $part) {
            // One being written this very minute belongs to a run still going.
            if (filemtime($part) < time() - 120) {
                @unlink($part);
                $found = true;
            }
        }

        return $found;
    }

    /**
     * What an admin needs to be told about the backups, if anything: the daily one not being made, or no copy
     * having left the server for a while. A backup that sits beside the data it protects is lost with it.
     */
    public static function alert(): ?string
    {
        return Cache::remember('backup_alert', 600, function () {
            $newest = self::all()[0]['made_at'] ?? null;
            if (config('app.auto_backup') && (! $newest || $newest < now()->subHours(36)->format('Y-m-d H:i'))) {
                return (Cache::get('backup_error') ?: ($newest ? 'No backup has been made since '.date('j M', strtotime($newest)).'.' : 'No backup has been made yet.')).' Make one under Setup → Backups.';
            }
            $marks = FileBackup::marks();
            $copied = $marks['db_downloaded_at'];
            if (! $copied) {
                return 'No backup has been copied off the server yet. Download one under Setup → Backups.';
            }
            $days = (int) floor(Carbon::parse($copied)->diffInDays(now()));
            if ($days >= 7) {
                return "The last backup copied off the server is {$days} days old. Download a new one under Setup → Backups.";
            }
            $files = FileBackup::summary();
            $filesCopied = $marks['files_downloaded_at'];
            if ($files['waiting'] > 0 && (! $filesCopied || Carbon::parse($filesCopied)->lt(now()->subDays(7)))) {
                return ($files['waiting'] === 1 ? '1 uploaded file (a scan, attachment or signature) has' : "{$files['waiting']} uploaded files (scans, attachments, signatures) have")
                    .' not been copied off the server. Download under Setup → Backups.';
            }

            return null;
        }) ?: null;
    }
}
