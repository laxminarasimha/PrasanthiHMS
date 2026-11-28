<?php

namespace App\Support;

use App\Models\AdmissionCharge;
use App\Models\AdmissionPayment;
use App\Models\Bill;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Money billed and collected, doctor by doctor.
 *
 * A front desk or lab bill belongs to the doctor of the visit it was made for; an inpatient bill to the doctor
 * the patient is admitted under. Bills made for no visit (a walk-in for a lab test, a dressing) go on one line of
 * their own. "Billed" is what was charged in the period and "collected" is money received in the period, net of
 * refunds. Pharmacy bills name no doctor, so they are not here.
 *
 * Two things differ from the organisation report, on purpose. A bill paid out of a patient's deposit counts here
 * on the day the deposit was used for it, because only then is it known whose bill the money paid; the
 * organisation report counts a deposit on the day it was taken. And a bill moved onto an inpatient bill is not
 * counted at its own counter: its money comes in with the inpatient bill.
 */
class DoctorMoney
{
    public const COUNTERS = ['front_desk' => 'Front desk', 'lab' => 'Lab', 'ipd' => 'IPD'];

    /** Whose a front desk or lab bill is: the doctor of the visit it was made for, or 0 for no doctor. */
    public const DOCTOR = 'coalesce(appointments.doctor_id, 0)';

    /** Whose an inpatient's money is: the doctor the patient is admitted under, or 0 for no doctor. */
    public const UNDER = 'coalesce(admissions.doctor_id, 0)';

    /**
     * Real money, and deposits put towards a bill; not the entry that moves a bill onto an inpatient bill.
     *
     * @return list<string>
     */
    public static function money(): array
    {
        return [...Payment::MODES, 'deposit'];
    }

    /** The same split as everywhere else: a lab bill is one a lab order points to. */
    private static function labBills(): Builder
    {
        return LabOrder::whereNotNull('bill_id')->select('bill_id');
    }

    /**
     * The payments and refunds behind the doctors' "collected" at the front desk or the lab, not yet added up. The
     * figures and the list behind a figure both start here, so a list always comes to its figure.
     */
    public static function counterPayments(Carbon $from, Carbon $to, bool $lab): Builder
    {
        return Payment::join('bills', 'bills.id', '=', 'payments.bill_id')
            ->leftJoin('appointments', 'appointments.id', '=', 'bills.appointment_id')
            ->whereBetween('payments.created_at', [$from, $to])->whereIn('payments.mode', self::money())
            ->when($lab, fn ($q) => $q->whereIn('bills.id', self::labBills()), fn ($q) => $q->whereNotIn('bills.id', self::labBills()));
    }

    /** The same for inpatients: advances, payments and refunds on the cases, in the period. */
    public static function ipdPayments(Carbon $from, Carbon $to): Builder
    {
        return AdmissionPayment::join('admissions', 'admissions.id', '=', 'admission_payments.admission_id')
            ->whereBetween('admission_payments.created_at', [$from, $to])->whereIn('admission_payments.mode', self::money());
    }

    /**
     * One row for each doctor with any money in the period, busiest first, then the bills with no doctor.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function between(Carbon $from, Carbon $to): Collection
    {
        $doctor = self::DOCTOR;
        $net = "sum(case when payments.type = 'refund' then -payments.amount else payments.amount end)";
        $labBills = fn () => self::labBills();

        $billed = fn (bool $lab) => Bill::leftJoin('appointments', 'appointments.id', '=', 'bills.appointment_id')
            ->whereBetween('bills.created_at', [$from, $to])
            ->when($lab, fn ($q) => $q->whereIn('bills.id', $labBills()), fn ($q) => $q->whereNotIn('bills.id', $labBills()))
            ->selectRaw("{$doctor} as doctor, sum(bills.total) as amount")->groupByRaw($doctor)->pluck('amount', 'doctor');
        $collected = fn (bool $lab) => self::counterPayments($from, $to, $lab)
            ->selectRaw("{$doctor} as doctor, {$net} as amount")->groupByRaw($doctor)->pluck('amount', 'doctor');

        $under = self::UNDER;
        $ipdBilled = AdmissionCharge::join('admissions', 'admissions.id', '=', 'admission_charges.admission_id')
            ->whereBetween('admission_charges.charged_on', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("{$under} as doctor, sum(admission_charges.amount) as amount")->groupByRaw($under)->pluck('amount', 'doctor');
        $ipdCollected = self::ipdPayments($from, $to)
            ->selectRaw("{$under} as doctor, sum(case when admission_payments.type = 'refund' then -admission_payments.amount else admission_payments.amount end) as amount")
            ->groupByRaw($under)->pluck('amount', 'doctor');

        $figures = [
            'front_desk_billed' => $billed(false), 'front_desk_collected' => $collected(false),
            'lab_billed' => $billed(true), 'lab_collected' => $collected(true),
            'ipd_billed' => $ipdBilled, 'ipd_collected' => $ipdCollected,
        ];
        $ids = collect($figures)->flatMap(fn (Collection $amounts) => $amounts->keys())->map(fn ($id) => (int) $id)->unique();
        $doctors = User::whereIn('id', $ids)->get(['id', 'name', 'share_front_desk', 'share_lab', 'share_ipd'])->keyBy('id');

        return $ids->map(function (int $id) use ($figures, $doctors) {
            $row = ['doctor_id' => $id, 'doctor' => $id === 0 ? 'No doctor on the bill' : ($doctors[$id]->name ?? 'A doctor no longer on the staff list')];
            foreach ($figures as $key => $amounts) {
                $row[$key] = round((float) ($amounts[$id] ?? 0), 2);
            }
            $row['billed'] = round($row['front_desk_billed'] + $row['lab_billed'] + $row['ipd_billed'], 2);
            $row['collected'] = round($row['front_desk_collected'] + $row['lab_collected'] + $row['ipd_collected'], 2);
            foreach (array_keys(self::COUNTERS) as $counter) {
                $row["share_{$counter}"] = $id && isset($doctors[$id]) && $doctors[$id]->{"share_{$counter}"} !== null ? (float) $doctors[$id]->{"share_{$counter}"} : null;
            }

            return $row;
        })->sortBy([fn ($a, $b) => ($a['doctor_id'] === 0) <=> ($b['doctor_id'] === 0), ['collected', 'desc'], ['doctor', 'asc']])->values();
    }

    /**
     * The entries behind one doctor's "collected" at one counter ('front_desk', 'lab' or 'ipd'), in the order the money
     * came in: each payment, and each refund as a minus line. They are the very entries between() adds up, so the
     * lines always come to the doctor's figure.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function collectedLines(int $doctorId, string $counter, Carbon $from, Carbon $to): Collection
    {
        if ($counter === 'ipd') {
            return self::ipdPayments($from, $to)->whereRaw(self::UNDER.' = ?', [$doctorId])
                // Only the payment's own columns: the case's id, dates and amounts would otherwise be read in their place.
                ->select('admission_payments.*')
                ->with(['admission:id,ipd_no,patient_id', 'admission.patient:id,uhid,name'])
                ->orderBy('admission_payments.created_at')->orderBy('admission_payments.id')->get()
                ->map(fn (AdmissionPayment $p) => self::line($p) + [
                    'bill' => null, 'order' => null, 'cancelled_lab_bill' => false,
                    'admission' => $p->admission ? ['id' => $p->admission->id, 'ipd_no' => $p->admission->ipd_no] : null,
                    'patient' => self::patient($p->admission?->patient),
                ]);
        }

        return self::counterPayments($from, $to, $counter === 'lab')->whereRaw(self::DOCTOR.' = ?', [$doctorId])
            ->select('payments.*')
            ->with(['bill:id,bill_no,patient_id,kind,created_at,cancelled_at', 'bill.patient:id,uhid,name', 'bill.labOrder:id,bill_id,order_no'])
            ->orderBy('payments.created_at')->orderBy('payments.id')->get()
            ->map(fn (Payment $p) => self::line($p) + [
                'bill' => ['id' => $p->bill->id, 'bill_no' => $p->bill->bill_no, 'on' => $p->bill->created_at?->format('Y-m-d'), 'kind' => $p->bill->kind, 'cancelled' => $p->bill->cancelled_at !== null],
                'order' => $p->bill->labOrder ? ['id' => $p->bill->labOrder->id, 'order_no' => $p->bill->labOrder->order_no] : null,
                // Cancelling a lab bill takes its lab order away with it, so from then on its money counts at the front desk.
                'cancelled_lab_bill' => $p->bill->kind === 'lab' && $p->bill->cancelled_at !== null,
                'admission' => null,
                'patient' => self::patient($p->bill->patient),
            ]);
    }

    /** @return array<string, mixed> */
    private static function line(Payment|AdmissionPayment $p): array
    {
        return [
            'id' => $p->id, 'on' => $p->created_at->format('Y-m-d H:i'),
            // Set when the money was typed in on a later day than it came in.
            'entered_on' => $p->entered_at?->format('Y-m-d'),
            'type' => $p->type, 'mode' => $p->mode, 'reference' => $p->reference,
            'amount' => round($p->type === 'refund' ? -$p->amount : $p->amount, 2),
        ];
    }

    /** @return array{id: int, name: string, uhid: string|null}|null */
    private static function patient(?Patient $patient): ?array
    {
        return $patient ? ['id' => $patient->id, 'name' => $patient->name, 'uhid' => $patient->uhid] : null;
    }
}
