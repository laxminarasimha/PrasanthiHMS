<?php

namespace App\Support;

use App\Models\AdmissionCharge;
use App\Models\AdmissionPayment;
use App\Models\Bill;
use App\Models\LabOrder;
use App\Models\Payment;
use App\Models\User;
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

    /**
     * One row for each doctor with any money in the period, busiest first, then the bills with no doctor.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function between(Carbon $from, Carbon $to): Collection
    {
        $doctor = 'coalesce(appointments.doctor_id, 0)';
        $net = "sum(case when payments.type = 'refund' then -payments.amount else payments.amount end)";
        // Real money, and deposits put towards a bill; not the entry that moves a bill onto an inpatient bill.
        $money = [...Payment::MODES, 'deposit'];
        // The same split as everywhere else: a lab bill is one a lab order points to.
        $labBills = fn () => LabOrder::whereNotNull('bill_id')->select('bill_id');

        $billed = fn (bool $lab) => Bill::leftJoin('appointments', 'appointments.id', '=', 'bills.appointment_id')
            ->whereBetween('bills.created_at', [$from, $to])
            ->when($lab, fn ($q) => $q->whereIn('bills.id', $labBills()), fn ($q) => $q->whereNotIn('bills.id', $labBills()))
            ->selectRaw("{$doctor} as doctor, sum(bills.total) as amount")->groupByRaw($doctor)->pluck('amount', 'doctor');
        $collected = fn (bool $lab) => Payment::join('bills', 'bills.id', '=', 'payments.bill_id')
            ->leftJoin('appointments', 'appointments.id', '=', 'bills.appointment_id')
            ->whereBetween('payments.created_at', [$from, $to])->whereIn('payments.mode', $money)
            ->when($lab, fn ($q) => $q->whereIn('bills.id', $labBills()), fn ($q) => $q->whereNotIn('bills.id', $labBills()))
            ->selectRaw("{$doctor} as doctor, {$net} as amount")->groupByRaw($doctor)->pluck('amount', 'doctor');

        $under = 'coalesce(admissions.doctor_id, 0)';
        $ipdBilled = AdmissionCharge::join('admissions', 'admissions.id', '=', 'admission_charges.admission_id')
            ->whereBetween('admission_charges.charged_on', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("{$under} as doctor, sum(admission_charges.amount) as amount")->groupByRaw($under)->pluck('amount', 'doctor');
        $ipdCollected = AdmissionPayment::join('admissions', 'admissions.id', '=', 'admission_payments.admission_id')
            ->whereBetween('admission_payments.created_at', [$from, $to])->whereIn('admission_payments.mode', $money)
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
}
