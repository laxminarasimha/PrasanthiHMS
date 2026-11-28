<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Admission;
use App\Models\Bill;
use App\Models\LabOrder;
use App\Models\MedicalRep;
use App\Models\Medicine;
use App\Models\PharmacySale;
use App\Models\Setting;
use App\Models\User;
use App\Workforce\Models\Employee;
use App\Workforce\Support\Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The top search, beyond patients: a bill, lab order or IPD number typed as printed (B35 or B00035), or a medicine,
 * doctor, member of staff or medical representative by name. Each kind is looked for only for someone whose work
 * opens it, and each result says where it opens.
 */
class SearchController extends Controller
{
    private const EACH = 5;

    public function everything(Request $request): JsonResponse
    {
        $user = $request->user();
        $term = trim((string) ($request->validate(['q' => ['required', 'string', 'max:100']])['q']));
        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }
        $found = collect();

        // A number as printed: letters then digits, the digits filled out to five places.
        if (preg_match('/^([A-Za-z]{1,4})\s*-?\s*0*(\d{1,9})$/', $term, $m)) {
            $prefix = strtoupper($m[1]);
            $code = fn (string $p) => $p.str_pad($m[2], 5, '0', STR_PAD_LEFT);
            $ipd = strtoupper(Setting::group('billing', ['ipd_prefix' => 'IP'])['ipd_prefix'] ?: 'IP');

            if ($prefix === 'B' && $user->allows('canWorkFrontDesk')) {
                $found = $found->concat(Bill::where('bill_no', $code('B'))->with('patient:id,title,name')->get()->map(fn (Bill $b) => [
                    'kind' => 'bill', 'label' => "Bill {$b->bill_no}", 'detail' => trim(($b->patient?->name ?? 'Walk-in').' · ₹'.(float) $b->total.($b->cancelled_at ? ' · cancelled' : '')), 'to' => "/front-desk/bills?open={$b->id}",
                ]));
            }
            if ($prefix === 'P' && $user->mayInPharmacy($request)) {
                $found = $found->concat(PharmacySale::where('bill_no', $code('P'))->get()->map(fn (PharmacySale $s) => [
                    'kind' => 'pharmacy_bill', 'label' => "Pharmacy bill {$s->bill_no}", 'detail' => trim(($s->customer_name ?? 'Walk-in').' · ₹'.(float) $s->total.($s->voided_at ? ' · cancelled' : '')), 'to' => "/pharmacy/bills?open={$s->id}",
                ]));
            }
            if ($prefix === 'L' && ($user->allows('canRunLab') || $user->seesClinical())) {
                $found = $found->concat(LabOrder::where('order_no', $code('L'))->with('patient:id,title,name')->get()->map(fn (LabOrder $o) => [
                    'kind' => 'lab_order', 'label' => "Lab order {$o->order_no}", 'detail' => ($o->patient?->name ?? 'A patient').' · '.$o->ordered_at->format('j M Y'),
                    'to' => $user->allows('canRunLab') ? "/lab?open={$o->id}" : "/lab/orders/{$o->id}/print", 'new_tab' => ! $user->allows('canRunLab'),
                ]));
            }
            if ($prefix === $ipd && $user->allows('canWorkIpd')) {
                $found = $found->concat(Admission::where('ipd_no', $code($ipd))->with('patient:id,title,name')->get()->map(fn (Admission $a) => [
                    'kind' => 'ipd', 'label' => "IPD {$a->ipd_no}", 'detail' => ($a->patient?->name ?? 'A patient').' · admitted '.$a->admitted_at->format('j M Y'), 'to' => "/ipd/{$a->id}",
                ]));
            }

            return response()->json($found->values());
        }

        if (! preg_match('/\p{L}/u', $term)) {
            return response()->json([]);
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';

        if ($user->mayInPharmacy($request)) {
            $found = $found->concat(Medicine::where('name', 'like', $like)->orderByDesc('is_active')->orderBy('name')->withSum('batches as on_shelf', 'units_in_stock')->limit(self::EACH)->get(['id', 'name', 'composition', 'is_active'])
                ->map(fn (Medicine $m) => ['kind' => 'medicine', 'label' => $m->name, 'detail' => trim(($m->composition ? $m->composition.' · ' : '').(int) $m->on_shelf.' on the shelf'), 'to' => "/pharmacy/medicines/{$m->id}"]));
        }
        if (DoctorCardController::opens($user)) {
            $found = $found->concat(User::where('role', Role::Doctor)->where('is_active', true)->where('name', 'like', $like)->orderBy('name')->limit(self::EACH)->get(['id', 'name', 'department'])
                ->map(fn (User $d) => ['kind' => 'doctor', 'label' => $d->name, 'detail' => $d->department ?: 'Doctor', 'doctor_id' => $d->id]));
        }
        if ($user->allows('wf:staff:view')) {
            $found = $found->concat(Employee::query()->where('name', 'like', $like)->where('status', '!=', 'inactive')->orderBy('name')->limit(self::EACH * 2)->get()
                ->filter(fn (Employee $e) => Access::reaches($user, 'wf:staff:view', $e))->take(self::EACH)
                ->map(fn (Employee $e) => ['kind' => 'staff', 'label' => $e->name, 'detail' => $e->designation ?: 'Staff', 'to' => "/workforce/staff?person={$e->id}"]));
        }
        if ($user->allows('canConsult') || $user->allows('canWorkFrontDesk')) {
            $found = $found->concat(MedicalRep::where('name', 'like', $like)->with('company:id,name')->orderBy('name')->limit(self::EACH)->get()
                ->map(fn (MedicalRep $r) => ['kind' => 'rep', 'label' => $r->name, 'detail' => 'Medical rep'.($r->company ? ' · '.$r->company->name : ''), 'to' => '/reps?tab=reps&q='.rawurlencode($r->name)]));
        }

        return response()->json($found->values());
    }
}
