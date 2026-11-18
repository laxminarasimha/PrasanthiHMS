<?php

namespace App\Support;

use App\Models\Setting;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * Copies of the uploaded files: scanned old records, attachments, lab report files, signatures, seals and the logo.
 * They are not in the database, so a database backup alone would lose them with the server.
 *
 * The files are handed over as zip files, oldest first, each small enough for a slow line. The app remembers how far
 * the admin has got ("through"), so the next download holds only what was added since. Unzipped over
 * storage/app/private, a zip puts every file back where it was.
 */
class FileBackup
{
    /** About how much one download may hold. A single larger file still goes, on its own. */
    public const MAX_BYTES = 150 * 1024 * 1024;

    public const MARKS = 'backup_marks';

    public static function root(): string
    {
        return storage_path('app/private');
    }

    /**
     * Every uploaded file, oldest first. The backups folder is left out: those are downloaded on their own.
     *
     * @return list<array{path: string, size: int, at: int}>
     */
    public static function files(): array
    {
        $root = self::root();
        if (! is_dir($root)) {
            return [];
        }
        $found = [];
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $file) {
            $path = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
            if (! $file->isFile() || str_starts_with($path, 'backups/') || str_starts_with(basename($path), '.')) {
                continue;
            }
            $found[] = ['path' => $path, 'size' => (int) $file->getSize(), 'at' => (int) $file->getMTime()];
        }
        usort($found, fn ($a, $b) => [$a['at'], $a['path']] <=> [$b['at'], $b['path']]);

        return $found;
    }

    /**
     * @return array{db_downloaded_at: ?string, db_downloaded_by: ?string, files_through: int, files_downloaded_at: ?string, files_downloaded_by: ?string}
     */
    public static function marks(): array
    {
        return Setting::group(self::MARKS, ['db_downloaded_at' => null, 'db_downloaded_by' => null, 'files_through' => 0, 'files_downloaded_at' => null, 'files_downloaded_by' => null]);
    }

    /** @param  array<string, mixed>  $changes */
    public static function mark(array $changes): void
    {
        Setting::updateOrCreate(['key' => self::MARKS], ['value' => array_merge(self::marks(), $changes)]);
    }

    /**
     * How many files there are, and how many have not been copied off the server yet.
     *
     * @return array{count: int, size: int, waiting: int, waiting_size: int, downloaded_at: ?string, downloaded_by: ?string}
     */
    public static function summary(): array
    {
        $marks = self::marks();
        $files = self::files();
        $waiting = array_filter($files, fn ($f) => $f['at'] > $marks['files_through']);

        return [
            'count' => count($files), 'size' => array_sum(array_column($files, 'size')),
            'waiting' => count($waiting), 'waiting_size' => array_sum(array_column($waiting, 'size')),
            'downloaded_at' => $marks['files_downloaded_at'], 'downloaded_by' => $marks['files_downloaded_by'],
        ];
    }

    /**
     * Writes the next zip: the files added after $through, oldest first, up to about MAX_BYTES.
     *
     * @return array{path: string, name: string, count: int, through: int, more: int}|null null when nothing is waiting
     */
    public static function zip(int $through): ?array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('This server cannot make zip files.');
        }
        // A file still being written a moment ago is left for the next download.
        $settled = time() - 5;
        $waiting = array_values(array_filter(self::files(), fn ($f) => $f['at'] > $through && $f['at'] <= $settled));
        if (! $waiting) {
            return null;
        }

        $take = [];
        $bytes = 0;
        foreach ($waiting as $file) {
            // Files saved in the same second stay together, so the "through" mark never splits them.
            $sameSecond = $take && end($take)['at'] === $file['at'];
            if ($take && ! $sameSecond && $bytes + $file['size'] > self::MAX_BYTES) {
                break;
            }
            $take[] = $file;
            $bytes += $file['size'];
        }

        $folder = DatabaseBackup::folder();
        if (! is_dir($folder) && ! mkdir($folder, 0700, true) && ! is_dir($folder)) {
            throw new RuntimeException('Could not create the backup folder.');
        }
        // Anything left behind by a download that was cut short.
        foreach (glob($folder.'/files-*.zip.tmp') ?: [] as $old) {
            if (filemtime($old) < time() - 3600) {
                @unlink($old);
            }
        }

        $name = 'hms-files-'.now()->format('Y-m-d-His').'.zip';
        $path = $folder.'/files-'.bin2hex(random_bytes(8)).'.zip.tmp';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not write the zip file.');
        }
        foreach ($take as $file) {
            $zip->addFile(self::root().'/'.$file['path'], $file['path']);
            // Scans and pictures are already compressed; storing them as they are is much quicker.
            $zip->setCompressionName($file['path'], ZipArchive::CM_STORE);
        }
        $zip->setArchiveComment('Prasanthi HMS uploaded files. Unzip over storage/app/private to put them back.');
        if (! $zip->close()) {
            @unlink($path);
            throw new RuntimeException('Could not finish the zip file.');
        }

        return ['path' => $path, 'name' => $name, 'count' => count($take), 'through' => end($take)['at'], 'more' => count($waiting) - count($take)];
    }
}
