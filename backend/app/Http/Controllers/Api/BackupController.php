<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Support\DatabaseBackup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Database backups: see them, make one now, download one. A backup holds every patient's records, so only an admin may. */
class BackupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json(['backups' => DatabaseBackup::all(), 'keep' => DatabaseBackup::KEEP, 'automatic' => (bool) config('app.auto_backup')]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->guard($request);
        DatabaseBackup::run();

        return response()->json(['backups' => DatabaseBackup::all(), 'keep' => DatabaseBackup::KEEP, 'automatic' => (bool) config('app.auto_backup')], 201);
    }

    public function download(Request $request, string $name): BinaryFileResponse
    {
        $this->guard($request);
        // Only a name this app wrote itself, never a path.
        abort_unless(preg_match('/^hms-\d{4}-\d{2}-\d{2}-\d{6}\.sql\.gz$/', $name) === 1, 404);
        $path = DatabaseBackup::folder().'/'.$name;
        abort_unless(is_file($path), 404, 'That backup is no longer on the server.');

        return response()->download($path, $name, ['Content-Type' => 'application/gzip']);
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user()->role === Role::Admin, 403, 'Only an admin can see or download backups.');
    }
}
