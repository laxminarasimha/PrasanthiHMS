<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PharmacySale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ReportController extends Controller
{
    /**
     * Money billed and collected per department and payment mode, plus activity counts,
     * for a date range. "Billed" is what was charged in the period; "collected" is the
     * money actually received in the period, whenever the bill was made.
     */
    public function summary(Request $request): JsonResponse
    {
        abort_unless($request->user()->allows('canViewReports'), 403, 'You are not allowed to view reports.');

        $range = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = Carbon::parse($range['from'] ?? today())->startOfDay();
        $to = Carbon::parse($range['to'] ?? $from)->endOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Choose a range of one year or less.');

        $departments = \App\Support\MoneyFigures::departments($from, $to);

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'modes' => Payment::MODES,
            'departments' => $departments,
            'counts' => [
                'new_patients' => Patient::whereBetween('created_at', [$from, $to])->count(),
                'appointments' => Appointment::whereBetween('scheduled_at', [$from, $to])->where('status', '!=', 'cancelled')->count(),
                // A visit counts once the patient has been seen: the doctor finished the visit pad, or the
                // desk marked the appointment Reviewed for a doctor who wrote the notes elsewhere.
                'consultations' => Appointment::where(fn ($q) => $q
                    ->where(fn ($q) => $q->whereBetween('scheduled_at', [$from, $to])->where('status', 'reviewed'))
                    ->orWhereHas('consultation', fn ($q) => $q->whereBetween('completed_at', [$from, $to])))->count(),
                'visit_pads' => Consultation::whereBetween('completed_at', [$from, $to])->count(),
                'lab_orders' => LabOrder::whereBetween('ordered_at', [$from, $to])->count(),
                'pharmacy_bills' => PharmacySale::whereBetween('sold_at', [$from, $to])->whereNull('voided_at')->count(),
                'admissions' => Admission::whereBetween('admitted_at', [$from, $to])->count(),
                'discharges' => Admission::whereBetween('discharged_at', [$from, $to])->count(),
                'inpatients_now' => Admission::where('status', 'admitted')->count(),
            ],
        ]);
    }
}
