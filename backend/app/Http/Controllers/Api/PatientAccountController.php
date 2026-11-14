<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PatientAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The front desk's side of a patient's mobile app account: confirm it really is this person
 * (which opens their records in the app), give a new password when they forget it, or switch
 * the account off.
 */
class PatientAccountController extends Controller
{
    public function show(Patient $patient): JsonResponse
    {
        return response()->json($this->present($patient));
    }

    /** Staff have seen the person and checked the phone is theirs, or are taking that back. */
    public function verify(Request $request, Patient $patient): JsonResponse
    {
        $data = $request->validate(['verified' => ['required', 'boolean']]);
        $account = $this->account($patient);
        $account->update(['verified_at' => $data['verified'] ? now() : null]);
        AuditLog::record($data['verified'] ? 'app.verified' : 'app.unverified', $patient->id, $account, 'Mobile '.$account->mobile);

        return response()->json($this->present($patient));
    }

    /**
     * Sets a one-time password for a patient who forgot theirs. It is shown to staff once, to be
     * told to the patient in person; every phone signed in to the account is signed out.
     */
    public function resetPassword(Request $request, Patient $patient): JsonResponse
    {
        $account = $this->account($patient);
        // Easy to read out and type: no look-alike letters, always with letters and numbers.
        $password = Str::of(Str::random(40))->replaceMatches('/[^a-hjkmnp-z]/', '')->substr(0, 5).random_int(100, 999).Str::of(Str::random(40))->replaceMatches('/[^a-hjkmnp-z]/', '')->substr(0, 2);
        $password = strlen($password) >= 8 ? $password : 'care'.random_int(1000, 9999).'hp';
        $account->update(['password' => $password]);
        $account->tokens()->delete();
        AuditLog::record('app.password', $patient->id, $account, 'Mobile '.$account->mobile);

        return response()->json($this->present($patient) + ['temporary_password' => $password]);
    }

    public function setActive(Request $request, Patient $patient): JsonResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $account = $this->account($patient);
        $account->update(['is_active' => $data['active']]);
        if (! $data['active']) {
            $account->tokens()->delete();
        }
        AuditLog::record('app.active', $patient->id, $account, $data['active'] ? 'Switched on' : 'Switched off');

        return response()->json($this->present($patient));
    }

    private function account(Patient $patient): PatientAccount
    {
        return PatientAccount::where('patient_id', $patient->id)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Patient $patient): array
    {
        $account = PatientAccount::where('patient_id', $patient->id)->first();

        return ['account' => $account ? [
            'id' => $account->id, 'name' => $account->name, 'mobile' => $account->mobile, 'is_active' => $account->is_active,
            'verified_at' => $account->verified_at?->format('Y-m-d H:i'), 'last_login_at' => $account->last_login_at?->format('Y-m-d H:i'),
            'registered_at' => $account->created_at->format('Y-m-d H:i'),
        ] : null];
    }
}
