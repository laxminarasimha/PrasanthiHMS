<?php

namespace App\Support;

use App\Models\Admission;
use App\Models\Bill;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\PharmacySale;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Lets a report row open what it shows: a bill or lab order number its record, an IPD number the case, a patient
 * their page, a doctor their card. The rows keep saying it in words, as they print and export; beside them goes
 * `_links`, by column, naming the kind of record and its id. The screen decides whether the reader may open it.
 * One look-up for each kind, whatever the number of rows.
 */
class ReportLinks
{
    /**
     * @param  Collection<int, mixed>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public static function add(Collection $rows): Collection
    {
        $rows = $rows->map(fn ($row) => (array) $row)->values();
        $values = fn (string $key, ?string $prefix = null) => $rows->pluck($key)->filter(fn ($v) => is_string($v) && $v !== '' && ($prefix === null || str_starts_with($v, $prefix)))->unique()->values();

        $bills = ($b = $values('bill_no', 'B'))->isEmpty() ? collect() : Bill::whereIn('bill_no', $b)->pluck('id', 'bill_no');
        $sales = ($p = $values('bill_no', 'P'))->isEmpty() ? collect() : PharmacySale::whereIn('bill_no', $p)->pluck('id', 'bill_no');
        $orders = ($o = $values('order_no', 'L'))->isEmpty() ? collect() : LabOrder::whereIn('order_no', $o)->pluck('id', 'order_no');
        $stays = ($i = $values('ipd_no'))->isEmpty() ? collect() : Admission::whereIn('ipd_no', $i)->pluck('id', 'ipd_no');
        $patients = ($u = $values('uhid'))->isEmpty() ? collect() : Patient::whereIn('uhid', $u)->pluck('id', 'uhid');
        $doctorIds = $rows->pluck('doctor_id')->filter()->map(fn ($id) => (int) $id)->unique();
        $doctors = $doctorIds->isEmpty() ? collect() : User::whereIn('id', $doctorIds)->where('role', 'doctor')->pluck('id', 'id');

        return $rows->map(function (array $row) use ($bills, $sales, $orders, $stays, $patients, $doctors) {
            $links = [];
            $bill = $row['bill_no'] ?? null;
            if (is_string($bill) && isset($bills[$bill])) {
                $links['bill_no'] = ['kind' => 'bill', 'id' => $bills[$bill]];
            } elseif (is_string($bill) && isset($sales[$bill])) {
                $links['bill_no'] = ['kind' => 'pharmacy_bill', 'id' => $sales[$bill]];
            }
            if (is_string($row['order_no'] ?? null) && isset($orders[$row['order_no']])) {
                $links['order_no'] = ['kind' => 'lab_order', 'id' => $orders[$row['order_no']]];
            }
            if (is_string($row['ipd_no'] ?? null) && isset($stays[$row['ipd_no']])) {
                $links['ipd_no'] = ['kind' => 'ipd', 'id' => $stays[$row['ipd_no']]];
            }
            $patient = is_string($row['uhid'] ?? null) ? ($patients[$row['uhid']] ?? null) : null;
            $patient ??= isset($row['patient_id']) && is_numeric($row['patient_id']) ? (int) $row['patient_id'] : null;
            if ($patient) {
                $links['patient'] = $links['uhid'] = ['kind' => 'patient', 'id' => $patient];
            }
            if (isset($row['doctor_id'], $doctors[(int) $row['doctor_id']])) {
                $links['doctor'] = ['kind' => 'doctor', 'id' => (int) $row['doctor_id']];
            }

            return $links ? $row + ['_links' => $links] : $row;
        });
    }
}
