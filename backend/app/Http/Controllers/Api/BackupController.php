<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Support\DatabaseBackup;
use App\Support\FileBackup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Backups: see them, make one now, download one, and download the uploaded files that are not inside them.
 * A backup holds every patient's records, so only an admin may.
 */
class BackupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json($this->present());
    }

    public function store(Request $request): JsonResponse
    {
        $this->guard($request);
        @set_time_limit(600);
        DatabaseBackup::run();
        Cache::forget('backup_error');
        Cache::forget('backup_alert');

        return response()->json($this->present(), 201);
    }

    public function download(Request $request, string $name): BinaryFileResponse
    {
        $this->guard($request);
        // Only a name this app wrote itself, never a path.
        abort_unless(preg_match('/^hms-\d{4}-\d{2}-\d{2}-\d{6}\.sql\.gz$/', $name) === 1, 404);
        $path = DatabaseBackup::folder().'/'.$name;
        abort_unless(is_file($path), 404, 'That backup is no longer on the server.');

        // Remembered, so the app can say when no copy has left the server for a while.
        FileBackup::mark(['db_downloaded_at' => now()->format('Y-m-d H:i'), 'db_downloaded_by' => $request->user()->name]);
        Cache::forget('backup_alert');

        return response()->download($path, $name, ['Content-Type' => 'application/gzip']);
    }

    /**
     * The next zip of uploaded files: those added since the last download, or from the very first with ?from=start.
     * The app moves its mark only when the browser says the file arrived (filesDone), so a download that
     * breaks half-way is simply offered again.
     */
    public function files(Request $request): BinaryFileResponse|JsonResponse
    {
        $this->guard($request);
        @set_time_limit(600);
        $zip = FileBackup::zip($request->query('from') === 'start' ? 0 : (int) FileBackup::marks()['files_through']);
        if (! $zip) {
            return response()->json(['message' => 'Every uploaded file has already been downloaded.'], 422);
        }

        return response()->download($zip['path'], $zip['name'], [
            'Content-Type' => 'application/zip', 'X-Backup-Through' => (string) $zip['through'], 'X-Backup-Count' => (string) $zip['count'], 'X-Backup-More' => (string) $zip['more'],
        ])->deleteFileAfterSend();
    }

    public function filesDone(Request $request): JsonResponse
    {
        $this->guard($request);
        $data = $request->validate(['through' => ['required', 'integer', 'min:1', 'max:'.time()]]);
        FileBackup::mark(['files_through' => $data['through'], 'files_downloaded_at' => now()->format('Y-m-d H:i'), 'files_downloaded_by' => $request->user()->name]);
        Cache::forget('backup_alert');

        return response()->json($this->present());
    }

    /**
     * @return array<string, mixed>
     */
    private function present(): array
    {
        $marks = FileBackup::marks();

        return [
            'backups' => DatabaseBackup::all(), 'keep' => DatabaseBackup::KEEP, 'automatic' => (bool) config('app.auto_backup'),
            'error' => Cache::get('backup_error'),
            'copied_at' => $marks['db_downloaded_at'], 'copied_by' => $marks['db_downloaded_by'],
            'files' => FileBackup::summary(),
            'alert' => DatabaseBackup::alert(),
        ];
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()->role === Role::Admin, 403, 'Only an admin can see or download backups.');
    }
}
