<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\AdmissionCharge;
use App\Models\AdmissionPayment;
use App\Models\Appointment;
use App\Models\Bill;
use App\Models\Consultation;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PharmacyPayment;
use App\Models\PharmacySale;
use App\Models\PharmacySaleReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ReportController extends Controller
{
    /**
     * Money billed and collected per department and payment mode, plus activity counts,
     * for a date range. "Billed" is what was charged in the period; "collected" is the
     * money actually received in the period, whenever the bill was made.
     */
    public function summary(Request $request): JsonResponse
    {
        abort_unless($request->user()->role->canManageStaff(), 403, 'You are not allowed to view reports.');

        $range = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = Carbon::parse($range['from'] ?? today())->startOfDay();
        $to = Carbon::parse($range['to'] ?? $from)->endOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Choose a range of one year or less.');

        $labBillIds = LabOrder::whereNotNull('bill_id')->pluck('bill_id');

        // Front desk and lab share the bills and payments tables; lab bills are the ones a lab order points to.
        $billPayments = fn (bool $lab) => $this->byMode(
            Payment::whereBetween('created_at', [$from, $to])
                ->when($lab, fn ($q) => $q->whereIn('bill_id', $labBillIds), fn ($q) => $q->whereNotIn('bill_id', $labBillIds))
                ->selectRaw("mode, sum(case when type = 'refund' then -amount else amount end) as amount")
                ->groupBy('mode')->pluck('amount', 'mode')
        );
        $billed = fn (bool $lab) => (float) Bill::whereBetween('created_at', [$from, $to])
            ->when($lab, fn ($q) => $q->whereIn('id', $labBillIds), fn ($q) => $q->whereNotIn('id', $labBillIds))
            ->sum('total');

        $departments = [
            'consultation' => ['label' => 'Front desk', 'billed' => $billed(false), 'collected' => $billPayments(false)],
            'lab' => ['label' => 'Lab', 'billed' => $billed(true), 'collected' => $billPayments(true)],
            'pharmacy' => [
                'label' => 'Pharmacy',
                'billed' => (float) PharmacySale::whereBetween('sold_at', [$from, $to])->whereNull('voided_at')->sum('total')
                    - (float) PharmacySaleReturn::whereBetween('created_at', [$from, $to])->sum('amount'),
                'collected' => $this->byMode(
                    PharmacyPayment::whereBetween('created_at', [$from, $to])
                        ->selectRaw("mode, sum(case when type = 'refund' then -amount else amount end) as amount")
                        ->groupBy('mode')->pluck('amount', 'mode')
                ),
            ],
            'ipd' => [
                'label' => 'IPD',
                'billed' => (float) AdmissionCharge::whereBetween('charged_on', [$from->toDateString(), $to->toDateString()])->sum('amount'),
                'collected' => $this->byMode(
                    AdmissionPayment::whereBetween('created_at', [$from, $to])
                        ->selectRaw("mode, sum(case when type = 'refund' then -amount else amount end) as amount")
                        ->groupBy('mode')->pluck('amount', 'mode')
                ),
            ],
        ];

        foreach ($departments as &$department) {
            $department['billed'] = round($department['billed'], 2);
            $department['collected_total'] = round(array_sum($department['collected']), 2);
        }

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'modes' => Payment::MODES,
            'departments' => $departments,
            'counts' => [
                'new_patients' => Patient::whereBetween('created_at', [$from, $to])->count(),
                'appointments' => Appointment::whereBetween('scheduled_at', [$from, $to])->where('status', '!=', 'cancelled')->count(),
                'consultations' => Consultation::whereBetween('completed_at', [$from, $to])->count(),
                'lab_orders' => LabOrder::whereBetween('ordered_at', [$from, $to])->count(),
                'pharmacy_bills' => PharmacySale::whereBetween('sold_at', [$from, $to])->whereNull('voided_at')->count(),
                'admissions' => Admission::whereBetween('admitted_at', [$from, $to])->count(),
                'discharges' => Admission::whereBetween('discharged_at', [$from, $to])->count(),
                'inpatients_now' => Admission::where('status', 'admitted')->count(),
            ],
        ]);
    }

    /**
     * Every payment mode with its amount, zero when nothing was received that way.
     *
     * @return array<string, float>
     */
    private function byMode(Collection $amounts): array
    {
        return collect(Payment::MODES)->mapWithKeys(fn ($mode) => [$mode => round((float) ($amounts[$mode] ?? 0), 2)])->all();
    }
}
