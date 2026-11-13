<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'This account has been deactivated.']);
        }

        return response()->json([
            'token' => $user->createToken('web')->plainTextToken,
            'user' => $this->present($user),
        ]);
    }

    /** Lets a person change their own password, after proving they know the current one. */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'different:current_password', \Illuminate\Validation\Rules\Password::min(8)->letters()->numbers()],
        ], ['password.different' => 'Choose a password different from the current one.', 'password.confirmed' => 'The two new passwords do not match.']);

        $user = $request->user();
        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'That is not your current password.']);
        }
        $user->update(['password' => $data['password']]);
        // Sign out every other device; this one stays signed in.
        $user->tokens()->where('id', '!=', $user->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Password changed.']);
    }

    /**
     * Today at a glance, limited to what this person is allowed to work with.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = today();
        $out = ['date' => $today->toDateString()];

        if ($user->allows('canWorkFrontDesk') || $user->allows('canConsult')) {
            $visits = \App\Models\Appointment::whereDate('scheduled_at', $today)
                ->when($user->role === \App\Enums\Role::Doctor, fn ($q) => $q->where('doctor_id', $user->id))
                ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
            $out['appointments'] = ['mine' => $user->role === \App\Enums\Role::Doctor] + collect(\App\Models\Appointment::STATUSES)->mapWithKeys(fn ($s) => [$s => (int) ($visits[$s] ?? 0)])->all();
        }
        if ($user->allows('canWorkFrontDesk')) {
            $due = \App\Models\Consultation::whereNotNull('completed_at')->whereDate('follow_up_on', $today)->pluck('patient_id')->unique();
            $booked = \App\Models\Appointment::whereDate('scheduled_at', $today)->where('status', '!=', 'cancelled')->whereIn('patient_id', $due)->pluck('patient_id')->unique();
            $out['follow_ups'] = ['due' => $due->count(), 'unbooked' => $due->diff($booked)->count()];
            $out['unpaid_bills'] = \App\Models\Bill::whereDate('created_at', $today)->whereColumn('paid', '<', 'total')->count();
        }
        if ($user->allows('canRunLab')) {
            $out['lab'] = [
                'to_collect' => \App\Models\LabOrderItem::whereNull('collected_at')->whereNull('reported_at')->where('created_at', '>=', $today->copy()->subDays(7))->count(),
                'to_report' => \App\Models\LabOrderItem::whereNotNull('collected_at')->whereNull('reported_at')->count(),
                'to_approve' => \App\Models\LabOrderItem::whereNotNull('reported_at')->whereNull('approved_at')->where('reported_at', '>=', $today->copy()->subDays(7))->count(),
            ];
        }
        if ($user->allows('canRunPharmacy')) {
            $stock = \App\Models\StockBatch::where('units_in_stock', '>', 0);
            $out['pharmacy'] = [
                'bills_today' => \App\Models\PharmacySale::whereDate('sold_at', $today)->whereNull('voided_at')->count(),
                'expiring' => (clone $stock)->whereDate('expiry', '>=', $today)->whereDate('expiry', '<=', $today->copy()->addDays(90))->count(),
                'expired' => (clone $stock)->whereDate('expiry', '<', $today)->count(),
            ];
        }
        if ($user->allows('canWorkIpd')) {
            $beds = \App\Models\Bed::where('is_active', true)->count();
            $admitted = \App\Models\Admission::where('status', 'admitted')->count();
            $out['ipd'] = [
                'inpatients' => $admitted, 'beds' => $beds,
                'free_beds' => max(0, $beds - \App\Models\Admission::where('status', 'admitted')->whereNotNull('bed_id')->count()),
                'admitted_today' => \App\Models\Admission::whereDate('admitted_at', $today)->count(),
                'discharged_today' => \App\Models\Admission::whereDate('discharged_at', $today)->count(),
            ];
        }
        if ($user->allows('canViewReports')) {
            [$from, $to] = [$today->copy()->startOfDay(), $today->copy()->endOfDay()];
            $net = fn ($query, string $in = 'payment') => (float) (clone $query)->whereIn('type', (array) ($in === 'payment' ? ['payment', 'advance', 'deposit'] : ['refund']))->sum('amount');
            $sources = [
                \App\Models\Payment::whereBetween('created_at', [$from, $to])->whereNotIn('mode', ['ipd', 'deposit']),
                \App\Models\PatientDeposit::whereBetween('created_at', [$from, $to])->whereIn('type', ['deposit', 'refund']),
                \App\Models\PharmacyPayment::whereBetween('created_at', [$from, $to]),
                \App\Models\AdmissionPayment::whereBetween('created_at', [$from, $to]),
            ];
            $out['money'] = [
                'collected' => round(array_sum(array_map(fn ($q) => $net($q) - $net($q, 'refund'), $sources)), 2),
                'new_patients' => \App\Models\Patient::whereDate('created_at', $today)->whereNull('merged_into_id')->count(),
            ];
        }

        return response()->json($out);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->present($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        $user->loadMissing('branch');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'role_label' => $user->role->label(),
            'can_manage_staff' => $user->allows('canManageStaff'),
            'can_consult' => $user->allows('canConsult'),
            'can_run_lab' => $user->allows('canRunLab'),
            'can_run_pharmacy' => $user->allows('canRunPharmacy'),
            'can_manage_setup' => $user->allows('canManageSetup'),
            'can_front_desk' => $user->allows('canWorkFrontDesk'),
            'can_ipd' => $user->allows('canWorkIpd'),
            'can_approve_ipd' => $user->allows('canApproveIpd'),
            'can_view_reports' => $user->allows('canViewReports'),
            'can_delete_bills' => $user->allows('canDeleteBills'),
            'can_change_price' => $user->allows('canChangePrice'),
            'pad_hidden_sections' => $user->pad_hidden_sections ?? [],
            'branch' => $user->branch?->only(['id', 'name', 'city']),
        ];
    }
}
