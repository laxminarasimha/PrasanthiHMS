<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Bill;
use App\Models\Consultation;
use App\Models\LabOrder;
use App\Models\LabOrderItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PharmacySale;
use App\Models\User;
use App\Support\MoneyFigures;
use App\Support\PaidOut;
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

        $departments = MoneyFigures::departments($from, $to);
        // Beside the money, how many bills each department made in the period and what is still unpaid on them,
        // so each one's whole position can be read in one place.
        foreach (self::unpaid($from, $to) as $key => $more) {
            $departments[$key] += $more;
        }

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'modes' => Payment::MODES,
            'departments' => $departments,
            // What left the hospital in the same period: its expenses and its payments to medicine suppliers.
            'paid_out' => PaidOut::between($from, $to),
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
                // An order holds one or more tests; both are counted so neither is mistaken for the other.
                'lab_tests' => LabOrderItem::whereIn('lab_order_id', LabOrder::whereBetween('ordered_at', [$from, $to])->select('id'))->count(),
                'pharmacy_bills' => PharmacySale::whereBetween('sold_at', [$from, $to])->whereNull('voided_at')->count(),
                'admissions' => Admission::whereBetween('admitted_at', [$from, $to])->count(),
                'discharges' => Admission::whereBetween('discharged_at', [$from, $to])->count(),
                'inpatients_now' => Admission::where('status', 'admitted')->count(),
            ],
        ]);
    }

    /**
     * How many bills each department made in a period, and what is still owed on them today. For the IPD it is the
     * cases that were in the hospital during the period, and what they still owe.
     *
     * @return array<string, array{bills: int, due: float}>
     */
    private static function unpaid(Carbon $from, Carbon $to): array
    {
        $labBillIds = LabOrder::whereNotNull('bill_id')->pluck('bill_id')->flip();
        [$lab, $desk] = Bill::whereBetween('created_at', [$from, $to])->whereNull('cancelled_at')->get(['id', 'total', 'paid'])
            ->partition(fn (Bill $b) => $labBillIds->has($b->id));
        $sales = PharmacySale::whereBetween('sold_at', [$from, $to])->whereNull('voided_at')->get(['id', 'total', 'returned', 'paid', 'ipd_transfer', 'voided_at']);
        $stays = Admission::where('admitted_at', '<=', $to)->where(fn ($q) => $q->whereNull('discharged_at')->orWhere('discharged_at', '>=', $from))->get();
        $owed = fn ($rows) => round((float) $rows->sum(fn ($row) => max(0, (float) $row->due)), 2);

        return [
            'consultation' => ['bills' => $desk->count(), 'due' => $owed($desk)],
            'lab' => ['bills' => $lab->count(), 'due' => $owed($lab)],
            'pharmacy' => ['bills' => $sales->count(), 'due' => $owed($sales)],
            'ipd' => ['bills' => $stays->count(), 'due' => $owed($stays)],
        ];
    }

    /**
     * For the owner's phone: how each of the last days went, what patients still owe the hospital,
     * and each doctor's work in a period. Read-only; the figures are the same ones the web reports use.
     */
    public function owner(Request $request): JsonResponse
    {
        abort_unless($request->user()->allows('canViewReports'), 403, 'You are not allowed to view reports.');

        $input = $request->validate([
            'days' => ['nullable', 'integer', 'min:7', 'max:31'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = Carbon::parse($input['from'] ?? today()->startOfMonth())->startOfDay();
        $to = Carbon::parse($input['to'] ?? today())->endOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Choose a range of one year or less.');

        $trend = [];
        for ($back = (int) ($input['days'] ?? 14) - 1; $back >= 0; $back--) {
            $day = today()->subDays($back);
            $departments = collect(MoneyFigures::departments($day->copy()->startOfDay(), $day->copy()->endOfDay()));
            $trend[] = [
                'day' => $day->toDateString(),
                'billed' => round($departments->sum('billed'), 2),
                'collected' => round($departments->sum('collected_total'), 2),
                'paid_out' => PaidOut::between($day, $day)['total'],
            ];
        }

        // What is still to come in. A bill moved onto an IPD bill is counted there, not twice.
        $labBills = LabOrder::whereNotNull('bill_id')->select('bill_id');
        $bills = fn (bool $lab) => Bill::whereColumn('total', '>', 'paid')
            ->when($lab, fn ($q) => $q->whereIn('id', $labBills), fn ($q) => $q->whereNotIn('id', $labBills))
            ->selectRaw('coalesce(sum(total - paid), 0) as owed, count(*) as bills')->first();
        $pharmacy = PharmacySale::whereNull('voided_at')->whereRaw('total - returned - paid - ipd_transfer > 0.004')
            ->selectRaw('coalesce(sum(total - returned - paid - ipd_transfer), 0) as owed, count(*) as bills')->first();
        $cases = Admission::whereRaw('total - discount > paid')->get(['id', 'status', 'total', 'discount', 'paid'])->filter(fn (Admission $a) => $a->due > 0);
        $line = fn (string $key, string $label, float $owed, int $count) => ['key' => $key, 'label' => $label, 'owed' => round($owed, 2), 'count' => $count];
        $owed = [
            $line('front_desk', 'Front desk bills', (float) $bills(false)->owed, (int) $bills(false)->bills),
            $line('lab', 'Lab bills', (float) $bills(true)->owed, (int) $bills(true)->bills),
            $line('pharmacy', 'Pharmacy bills', (float) $pharmacy->owed, (int) $pharmacy->bills),
            $line('ipd_admitted', 'Inpatients still admitted', (float) $cases->where('status', 'admitted')->sum('due'), $cases->where('status', 'admitted')->count()),
            $line('ipd_closed', 'IPD cases already closed', (float) $cases->where('status', '!=', 'admitted')->sum('due'), $cases->where('status', '!=', 'admitted')->count()),
        ];

        // Each doctor's period: who was booked, who was seen, what their consultations were billed at, and who is in a bed under them now.
        $visits = Appointment::whereBetween('scheduled_at', [$from, $to])->where('status', '!=', 'cancelled')
            ->selectRaw("doctor_id, count(*) as booked, sum(case when status = 'reviewed' then 1 else 0 end) as seen")->groupBy('doctor_id')->get()->keyBy('doctor_id');
        $billed = Bill::join('appointments', 'appointments.id', '=', 'bills.appointment_id')->whereBetween('bills.created_at', [$from, $to])
            ->selectRaw('appointments.doctor_id as doctor_id, sum(bills.total) as billed')->groupBy('appointments.doctor_id')->pluck('billed', 'doctor_id');
        $admitted = Admission::where('status', 'admitted')->selectRaw('doctor_id, count(*) as inpatients')->groupBy('doctor_id')->pluck('inpatients', 'doctor_id');
        $ids = $visits->keys()->merge($billed->keys())->merge($admitted->keys())->filter()->unique();
        $doctors = User::whereIn('id', $ids)->orderBy('name')->get(['id', 'name'])->map(fn (User $doctor) => [
            'id' => $doctor->id, 'name' => $doctor->name,
            'booked' => (int) ($visits[$doctor->id]->booked ?? 0), 'seen' => (int) ($visits[$doctor->id]->seen ?? 0),
            'billed' => round((float) ($billed[$doctor->id] ?? 0), 2), 'inpatients' => (int) ($admitted[$doctor->id] ?? 0),
        ])->sortByDesc('seen')->values();

        return response()->json([
            'today' => today()->toDateString(),
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'trend' => $trend,
            'owed' => $owed, 'owed_total' => round(collect($owed)->sum('owed'), 2),
            'doctors' => $doctors,
        ]);
    }
}
