<?php

namespace App\Support;

use App\Models\Admission;
use App\Models\AdmissionPayment;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\Consultation;
use App\Models\LabOrder;
use App\Models\PatientDeposit;
use App\Models\Payment;
use App\Models\PharmacyPayment;
use App\Models\PharmacySale;
use App\Models\PharmacySaleReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything a manager needs to see before closing a day: what each department did, the money
 * by department and payment mode, and above all the work that is still unfinished. Each unfinished
 * item points at the record it belongs to.
 */
class DayBook
{
    /** The most lines listed under one heading; the count is always the full number. */
    private const LIST = 60;

    private const PATIENT = 'patient:id,uhid,title,name';

    /** Changes to bills that a manager will want to know about at the end of the day. */
    private const BILL_CHANGES = [
        'Changed a bill’s discount', 'Changed a pharmacy bill', 'Deleted a pharmacy bill', 'Changed an IPD charge',
        'Removed an IPD charge', 'Deleted an IPD payment', 'Cancelled a numbered IPD bill',
    ];

    public static function for(Carbon $day): array
    {
        $from = $day->copy()->startOfDay();
        $to = $day->copy()->endOfDay();
        $rupees = fn (float $amount) => '₹'.number_format($amount, 2);
        $who = fn ($patient, ?string $fallback = null) => $patient ? trim(($patient->title ? $patient->title.' ' : '').$patient->name) : ($fallback ?: 'Walk-in');
        $page = fn ($patientId) => $patientId ? "/patients/{$patientId}" : null;

        // ---- Front desk ----
        $appointments = Appointment::whereBetween('scheduled_at', [$from, $to])
            ->with([self::PATIENT, 'doctor:id,name', 'consultation:id,appointment_id,completed_at'])
            ->orderBy('scheduled_at')->get(['id', 'patient_id', 'doctor_id', 'scheduled_at', 'status', 'token_no']);
        $labBillIds = LabOrder::whereNotNull('bill_id')->pluck('bill_id')->flip();
        $bills = Bill::whereBetween('created_at', [$from, $to])->with(self::PATIENT)->get();
        [$labBills, $deskBills] = $bills->partition(fn (Bill $b) => $labBillIds->has($b->id));
        $billRow = fn (Bill $b) => [
            'text' => "{$b->bill_no} · ".$who($b->patient),
            'detail' => $rupees($b->due).' due of '.$rupees($b->total),
            'to' => $page($b->patient_id),
        ];
        $status = ['booked' => 'Booked, not arrived', 'arrived' => 'Waiting', 'ongoing' => 'With the doctor'];

        $frontDesk = [
            'key' => 'front_desk', 'label' => 'Front desk',
            'figures' => [
                self::figure('Booked, not arrived', $appointments->where('status', 'booked')->count()),
                self::figure('Waiting', $appointments->where('status', 'arrived')->count()),
                self::figure('With the doctor', $appointments->where('status', 'ongoing')->count()),
                self::figure('Reviewed', $appointments->where('status', 'reviewed')->count()),
                self::figure('Cancelled', $appointments->where('status', 'cancelled')->count()),
            ],
            'checks' => [
                self::check('appointments_open', 'Appointments not finished', true,
                    'Mark each one Reviewed, or cancel it if the patient did not come.',
                    $appointments->whereIn('status', array_keys($status))->map(fn (Appointment $a) => [
                        'text' => ($a->token_no ? "Token {$a->token_no} · " : '').$who($a->patient),
                        'detail' => $status[$a->status].' · '.($a->doctor?->name ?? 'No doctor').' · '.$a->scheduled_at->format('g:i A'),
                        'to' => $page($a->patient_id),
                    ])),
                self::check('desk_dues', 'Front desk bills with money due', true,
                    'Collect the money, or give a discount for what will not be collected.',
                    $deskBills->filter(fn (Bill $b) => $b->due > 0)->map($billRow)),
            ],
        ];

        // ---- Doctor ----
        $doctor = [
            'key' => 'doctor', 'label' => 'Doctor',
            'figures' => [
                self::figure('Visit pads finished', Consultation::whereBetween('completed_at', [$from, $to])->count()),
            ],
            'checks' => [
                self::check('no_visit_pad', 'Reviewed without a finished visit pad', false,
                    'The patient was marked as seen, but the doctor’s notes are not in the app.',
                    $appointments->where('status', 'reviewed')->filter(fn (Appointment $a) => ! $a->consultation?->completed_at)->map(fn (Appointment $a) => [
                        'text' => $who($a->patient),
                        'detail' => ($a->doctor?->name ?? 'No doctor').' · '.$a->scheduled_at->format('g:i A'),
                        'to' => $page($a->patient_id),
                    ])),
            ],
        ];

        // ---- Lab ----
        $orders = LabOrder::whereBetween('ordered_at', [$from, $to])->with(self::PATIENT)->withCount('items')->orderBy('ordered_at')->get();
        $orderRow = fn (LabOrder $o) => [
            'text' => "{$o->order_no} · ".$who($o->patient),
            'detail' => $o->items_count.($o->items_count === 1 ? ' test' : ' tests').' · ordered '.$o->ordered_at->format('j M, g:i A'),
            'to' => $page($o->patient_id),
        ];
        $older = LabOrder::where('ordered_at', '<', $from)->where('ordered_at', '>=', $from->copy()->subDays(30))->where('status', '!=', 'completed')
            ->with(self::PATIENT)->withCount('items')->orderBy('ordered_at')->get();

        $lab = [
            'key' => 'lab', 'label' => 'Laboratory',
            'figures' => [
                self::figure('Orders', $orders->count()),
                self::figure('Results pending', $orders->where('status', 'pending')->count()),
                self::figure('Partly reported', $orders->where('status', 'partial')->count()),
                self::figure('Completed', $orders->where('status', 'completed')->count()),
            ],
            'checks' => [
                self::check('lab_unbilled', 'Lab orders with no bill', true,
                    'Every lab order should carry a bill, even one fully discounted.',
                    $orders->whereNull('bill_id')->map($orderRow)),
                self::check('lab_dues', 'Lab bills with money due', true,
                    'Collect the money, or give a discount for what will not be collected.',
                    $labBills->filter(fn (Bill $b) => $b->due > 0)->map($billRow)),
                self::check('lab_pending', 'Results not finished for the day’s orders', false,
                    'Tests that take longer than a day will stay here until they are reported.',
                    $orders->where('status', '!=', 'completed')->map($orderRow)),
                self::check('lab_pending_older', 'Still pending from earlier days', false,
                    'Orders from the last 30 days whose results were never completed.',
                    $older->map($orderRow)),
            ],
        ];

        // ---- Pharmacy ----
        $sales = PharmacySale::whereBetween('sold_at', [$from, $to])->whereNull('voided_at')->with(self::PATIENT)->get();
        $voided = PharmacySale::whereBetween('voided_at', [$from, $to])->with(self::PATIENT)->get();
        $returns = PharmacySaleReturn::whereBetween('created_at', [$from, $to])->with('sale:id,bill_no,patient_id,customer_name')->get();
        $saleText = fn (PharmacySale $s) => "{$s->bill_no} · ".$who($s->patient, $s->customer_name);

        $pharmacy = [
            'key' => 'pharmacy', 'label' => 'Pharmacy',
            'figures' => [
                self::figure('Bills', $sales->count()),
                self::figure('Billed', round((float) $sales->sum('total'), 2), true),
                self::figure('Returns', round((float) $returns->sum('amount'), 2), true),
                self::figure('Deleted bills', $voided->count()),
            ],
            'checks' => [
                self::check('pharmacy_dues', 'Pharmacy bills with money due', true,
                    'Collect the money on the bill, or take the medicines back.',
                    $sales->filter(fn (PharmacySale $s) => $s->due > 0)->map(fn (PharmacySale $s) => [
                        'text' => $saleText($s), 'detail' => $rupees($s->due).' due of '.$rupees($s->total), 'to' => '/pharmacy/bills',
                    ])),
                self::check('pharmacy_voided', 'Pharmacy bills deleted', false, 'Each one should have a reason the pharmacist can explain.',
                    $voided->map(fn (PharmacySale $s) => ['text' => $saleText($s), 'detail' => $rupees($s->total), 'to' => '/pharmacy/bills'])),
                self::check('pharmacy_returns', 'Medicines taken back', false, 'Returned medicines and the money handed back for them.',
                    $returns->map(fn (PharmacySaleReturn $r) => [
                        'text' => ($r->sale?->bill_no ?? 'Bill').' · '.($r->sale?->customer_name ?: 'Patient'), 'detail' => $rupees((float) $r->amount), 'to' => '/pharmacy/bills',
                    ])),
            ],
        ];

        // ---- IPD ----
        $ipdWith = [self::PATIENT, 'doctor:id,name'];
        $inpatients = Admission::where('status', 'admitted')->with($ipdWith)->orderBy('admitted_at')->get();
        $discharged = Admission::whereBetween('discharged_at', [$from, $to])->with($ipdWith)->get();
        $stay = fn (Admission $a) => [
            'text' => "{$a->ipd_no} · ".$who($a->patient),
            'detail' => 'Admitted '.$a->admitted_at->format('j M Y').' · '.($a->doctor?->name ?? 'No doctor'),
            'to' => "/ipd/{$a->id}",
        ];

        $ipd = [
            'key' => 'ipd', 'label' => 'IPD',
            'figures' => [
                self::figure('Inpatients now', $inpatients->count()),
                self::figure('Admitted', Admission::whereBetween('admitted_at', [$from, $to])->count()),
                self::figure('Discharged', $discharged->count()),
                self::figure('Owed by inpatients', round((float) $inpatients->sum(fn (Admission $a) => max(0, $a->due)), 2), true),
            ],
            'checks' => [
                self::check('ipd_discharged_unpaid', 'Discharged with a balance unpaid', true,
                    'Collect the balance, or give a discount on the IPD bill.',
                    $discharged->filter(fn (Admission $a) => $a->due > 0)->map(fn (Admission $a) => ['detail' => $rupees($a->due).' due of '.$rupees($a->net)] + $stay($a))),
                self::check('ipd_bill_ready', 'Bill approved, patient still admitted', false, 'Ready to go home once the case is closed.',
                    $inpatients->filter(fn (Admission $a) => $a->bill_approved_at !== null)->map($stay)),
                self::check('ipd_long_stay', 'Admitted for more than 7 days', false, 'Long stays to review.',
                    $inpatients->filter(fn (Admission $a) => $a->admitted_at->lt($from->copy()->subDays(7)))->map($stay)),
            ],
        ];

        // ---- Money ----
        $departments = MoneyFigures::departments($from, $to);
        $refunds = self::refunds($from, $to, $labBillIds, $who, $rupees);
        $due = [
            'consultation' => $deskBills->sum(fn (Bill $b) => max(0, $b->due)),
            'lab' => $labBills->sum(fn (Bill $b) => max(0, $b->due)),
            'pharmacy' => $sales->sum(fn (PharmacySale $s) => max(0, $s->due)),
            'ipd' => $discharged->sum(fn (Admission $a) => max(0, $a->due)),
        ];
        foreach ($departments as $key => &$department) {
            $department['refunded'] = round((float) $refunds->where('department', $key)->sum('amount'), 2);
            $department['due'] = round((float) $due[$key], 2);
        }
        unset($department);

        $changes = AuditLog::whereBetween('created_at', [$from, $to])
            ->where(fn ($q) => $q->whereIn('action', ['bill.item_removed', 'bill.discount'])->orWhere(fn ($q) => $q->where('action', 'change')
                ->where(fn ($q) => collect(self::BILL_CHANGES)->each(fn ($label) => $q->orWhere('summary', 'like', $label.'%')))))
            ->oldest('id')->get();

        $money = [
            'key' => 'money', 'label' => 'Money',
            'figures' => [
                self::figure('Billed', round(array_sum(array_column($departments, 'billed')), 2), true),
                self::figure('Collected', round(array_sum(array_column($departments, 'collected_total')), 2), true),
                self::figure('Refunded', round((float) $refunds->sum('amount'), 2), true),
                self::figure('Due on the day’s bills', round(array_sum($due), 2), true),
            ],
            'checks' => [
                self::check('refunds', 'Refunds given', false, 'Money handed back to patients.', $refunds->map(fn ($r) => ['text' => $r['text'], 'detail' => $r['detail'], 'to' => $r['to']])),
                self::check('bill_changes', 'Bills changed or cancelled', false, 'Discounts changed, lines removed, bills deleted.',
                    // The same thing saved twice in the same minute by the same person is one event, shown once with its count.
                    $changes->groupBy(fn (AuditLog $row) => implode('|', [$row->user_id, $row->action, $row->summary, $row->patient_id, $row->subject_id, $row->created_at->format('Y-m-d H:i')]))
                        ->map(function (Collection $same) use ($page) {
                            $row = $same->first();
                            $words = AuditLog::words($row);

                            return [
                                'text' => ($row->user_name ?: 'Someone').' · '.$words['action'].($same->count() > 1 ? " ({$same->count()} times)" : ''),
                                'detail' => trim(($words['details'] ? $words['details'].' · ' : '').$row->created_at->format('g:i A')),
                                'to' => $page($row->patient_id),
                            ];
                    })),
            ],
        ];

        $sections = [$frontDesk, $doctor, $lab, $pharmacy, $ipd, $money];
        $checks = collect($sections)->flatMap(fn ($s) => $s['checks']);

        return [
            'day' => $from->toDateString(),
            'sections' => $sections,
            'modes' => Payment::MODES,
            'departments' => $departments,
            // Cash that should be in the drawer: every cash receipt of the day less every cash refund.
            'expected_cash' => round(array_sum(array_map(fn ($d) => $d['collected']['cash'], $departments)), 2),
            'blocking' => $checks->where('blocking', true)->sum('count'),
            'notes' => $checks->where('blocking', false)->sum('count'),
        ];
    }

    /** Every refund of the day, whichever counter gave it. */
    private static function refunds(Carbon $from, Carbon $to, Collection $labBillIds, callable $who, callable $rupees): Collection
    {
        $line = fn (string $department, string $label, float $amount, string $mode, string $name, ?string $to) => [
            'department' => $department, 'amount' => $amount, 'to' => $to,
            'text' => "{$label} · {$name}", 'detail' => $rupees($amount).' by '.str_replace('_', ' ', $mode),
        ];

        return collect()
            ->concat(Payment::whereBetween('created_at', [$from, $to])->where('type', 'refund')->with('bill.'.self::PATIENT)->get()
                ->map(fn (Payment $p) => $line($labBillIds->has($p->bill_id) ? 'lab' : 'consultation', $p->bill?->bill_no ?? 'Bill', (float) $p->amount, $p->mode, $who($p->bill?->patient), $p->bill?->patient_id ? "/patients/{$p->bill->patient_id}" : null)))
            ->concat(PatientDeposit::whereBetween('created_at', [$from, $to])->where('type', 'refund')->with(self::PATIENT)->get()
                ->map(fn (PatientDeposit $d) => $line('consultation', 'Deposit', (float) $d->amount, (string) $d->mode, $who($d->patient), "/patients/{$d->patient_id}")))
            ->concat(PharmacyPayment::whereBetween('created_at', [$from, $to])->where('type', 'refund')->with('sale.'.self::PATIENT)->get()
                ->map(fn (PharmacyPayment $p) => $line('pharmacy', $p->sale?->bill_no ?? 'Pharmacy bill', (float) $p->amount, $p->mode, $who($p->sale?->patient, $p->sale?->customer_name), '/pharmacy/bills')))
            ->concat(AdmissionPayment::whereBetween('created_at', [$from, $to])->where('type', 'refund')->with('admission.'.self::PATIENT)->get()
                ->map(fn (AdmissionPayment $p) => $line('ipd', $p->admission?->ipd_no ?? 'IPD', (float) $p->amount, $p->mode, $who($p->admission?->patient), "/ipd/{$p->admission_id}")))
            ->values();
    }

    /** @return array{label: string, value: float|int, money: bool} */
    private static function figure(string $label, float|int $value, bool $money = false): array
    {
        return ['label' => $label, 'value' => $value, 'money' => $money];
    }

    /**
     * One kind of unfinished work. A blocking one has to be settled, or explained, before the day is closed.
     *
     * @param  iterable<array{text: string, detail: ?string, to: ?string}>  $rows
     */
    private static function check(string $key, string $label, bool $blocking, string $help, iterable $rows): array
    {
        $rows = collect($rows)->values();

        return ['key' => $key, 'label' => $label, 'blocking' => $blocking, 'help' => $help, 'count' => $rows->count(), 'rows' => $rows->take(self::LIST)->all()];
    }
}
