<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Patient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Reading the record of who opened or changed a patient's records. */
class AuditController extends Controller
{
    public function forPatient(Request $request, Patient $patient): JsonResponse
    {
        abort_unless($request->user()->allows('canViewReports'), 403, 'You are not allowed to see the record log.');

        return response()->json(
            AuditLog::where('patient_id', $patient->id)->latest('id')->limit(500)->get()
                ->map(fn (AuditLog $r) => ['id' => $r->id, 'when' => $r->created_at->format('Y-m-d H:i'), 'who' => $r->user_name, 'action' => AuditLog::ACTIONS[$r->action] ?? $r->action, 'details' => $r->summary])
        );
    }
}
