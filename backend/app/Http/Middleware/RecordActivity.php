<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Writes down every change a staff member makes that was not already written down by the
 * screen itself. It sits on all staff routes, so a new feature is covered the day it is added
 * and nothing depends on someone remembering to log it.
 */
class RecordActivity
{
    /** What each change is called in the log. Anything not listed is described from its address. */
    private const LABELS = [
        'POST appointments' => 'Booked an appointment',
        'PUT appointments/{appointment}' => 'Changed an appointment',
        'PUT appointments/{appointment}/vitals' => 'Recorded vitals',
        'POST doctor/visits' => 'Started a visit without a booking',
        'POST doctor-blocks' => 'Marked a doctor as away',
        'DELETE doctor-blocks/{block}' => 'Removed a doctor’s time away',
        'PUT bills/{bill}' => 'Changed a bill’s discount',
        'POST bills/{bill}/use-deposit' => 'Used a deposit on a bill',
        'POST patients/{patient}/deposits' => 'Took or refunded a deposit',
        'POST patients/{patient}/attachments' => 'Added an attachment',
        'DELETE attachments/{attachment}' => 'Removed an attachment',
        'POST patients/temporary' => 'Registered a temporary patient',
        'POST lab/orders' => 'Made a lab order',
        'PUT lab/orders/{order}/items/{item}/collect' => 'Marked a lab sample collected',
        'PUT lab/orders/{order}/items/{item}' => 'Entered lab results',
        'PUT lab/orders/{order}/items/{item}/approve' => 'Approved or unapproved a lab result',
        'POST lab/orders/{order}/files' => 'Added a file to a lab order',
        'DELETE lab/files/{file}' => 'Removed a file from a lab order',
        'POST pharmacy/sales' => 'Made a pharmacy bill',
        'PUT pharmacy/sales/{sale}' => 'Changed a pharmacy bill',
        'DELETE pharmacy/sales/{sale}' => 'Deleted a pharmacy bill',
        'POST pharmacy/sales/{sale}/payments' => 'Collected money on a pharmacy bill',
        'POST pharmacy/sales/{sale}/returns' => 'Took back medicines on a pharmacy bill',
        'POST pharmacy/purchases' => 'Entered a purchase invoice',
        'PUT pharmacy/purchases/{purchase}' => 'Changed a purchase invoice',
        'POST pharmacy/purchases/{purchase}/payments' => 'Paid a stockist',
        'POST pharmacy/opening-stock' => 'Entered opening stock',
        'POST pharmacy/stock/{batch}/adjust' => 'Adjusted stock',
        'PUT pharmacy/stock/{batch}' => 'Changed a stock batch’s details',
        'POST pharmacy/stock-returns' => 'Returned stock to a stockist',
        'POST pharmacy/purchase-orders' => 'Made a purchase order',
        'POST pharmacy/medicines' => 'Added a medicine',
        'PUT pharmacy/medicines/{medicine}' => 'Changed a medicine',
        'POST pharmacy/subscriptions/{subscription}/prepared' => 'Marked a monthly medicine pack as made',
        'POST ipd/admissions' => 'Admitted a patient',
        'PUT ipd/admissions/{admission}' => 'Changed an IPD case',
        'POST ipd/admissions/{admission}/charges' => 'Added an IPD charge',
        'PUT ipd/admissions/{admission}/charges/{charge}' => 'Changed an IPD charge',
        'DELETE ipd/admissions/{admission}/charges/{charge}' => 'Removed an IPD charge',
        'POST ipd/admissions/{admission}/payments' => 'Recorded IPD money',
        'DELETE ipd/admissions/{admission}/payments/{payment}' => 'Deleted an IPD payment',
        'POST ipd/admissions/{admission}/transfer' => 'Moved another bill onto an IPD bill',
        'POST ipd/admissions/{admission}/bills' => 'Made a numbered IPD bill',
        'DELETE ipd/admissions/{admission}/bills/{bill}' => 'Cancelled a numbered IPD bill',
        'POST ipd/admissions/{admission}/approve-bill' => 'Approved or unapproved an IPD bill',
        'POST ipd/admissions/{admission}/discharge' => 'Closed an IPD case',
        'POST ipd/admissions/{admission}/notes' => 'Added an IPD note',
        'PUT ipd/admissions/{admission}/notes/{note}' => 'Changed an IPD note',
        'DELETE ipd/admissions/{admission}/notes/{note}' => 'Deleted an IPD note',
        'PUT ipd/admissions/{admission}/summary' => 'Saved a discharge summary',
        'POST ipd/admissions/{admission}/summary/approve' => 'Approved or unapproved a discharge summary',
        'POST ipd/admissions/{admission}/chart/vitals' => 'Charted observations',
        'POST ipd/admissions/{admission}/chart/orders' => 'Wrote an inpatient order',
        'POST ipd/admissions/{admission}/chart/orders/{order}/stop' => 'Stopped an inpatient order',
        'POST ipd/admissions/{admission}/chart/orders/{order}/doses' => 'Recorded a medicine dose',
        'POST ipd/admissions/{admission}/chart/fluids' => 'Charted intake or output',
        'POST staff' => 'Added a staff login',
        'PUT staff/{staff}' => 'Changed a staff login',
        'POST me/password' => 'Changed their own password',
        'PUT clinic' => 'Changed the hospital details',
        'PUT billing-preferences' => 'Changed billing preferences',
        'PUT lab/settings' => 'Changed lab preferences',
        'POST services' => 'Added a service',
        'PUT services/{service}' => 'Changed a service or its price',
        'POST ipd/services' => 'Added an IPD service',
        'PUT ipd/services/{service}' => 'Changed an IPD service or its price',
        'POST backups' => 'Made a database backup',
        'GET backups/{name}/download' => 'Downloaded a database backup',
    ];

    /** Changes too small or too private to be worth a line: a person's own menu and colours, and checks that change nothing. */
    private const SKIP = ['POST logout', 'PUT me/appearance', 'PUT me/tabs', 'POST patients/{patient}/prescription-check', 'PUT doctor/pad-sections'];

    public function handle(Request $request, Closure $next): Response
    {
        AuditLog::$written = false;
        $response = $next($request);

        $route = $request->route();
        $key = $request->method().' '.preg_replace('#^api/#', '', (string) $route?->uri());
        $changes = in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true) || isset(self::LABELS[$key]);

        if ($changes && $response->getStatusCode() < 400 && ! AuditLog::$written && ! in_array($key, self::SKIP, true) && $request->user() instanceof User) {
            [$patientId, $subject, $names] = $this->about($request);
            $extra = collect(['amount' => '₹', 'type' => '', 'mode' => 'by ', 'status' => '', 'reason' => 'reason: ', 'void_reason' => 'reason: '])
                ->map(fn ($prefix, $field) => is_scalar($request->input($field)) && $request->input($field) !== '' ? $prefix.$request->input($field) : null)->filter();
            AuditLog::record('change', $patientId, $subject, trim((self::LABELS[$key] ?? $this->describe($request->method(), $key)).($names ? ' · '.implode(', ', $names) : '').($extra->isNotEmpty() ? ' · '.$extra->implode(', ') : '')));
        }

        return $response;
    }

    /**
     * Who and what a request was about, worked out from the records named in its address.
     *
     * @return array{0: int|null, 1: Model|null, 2: list<string>}
     */
    private function about(Request $request): array
    {
        $patientId = null;
        $subject = null;
        $names = [];
        foreach ($request->route()?->parameters() ?? [] as $name => $value) {
            if (! $value instanceof Model) {
                continue;
            }
            $subject ??= $value;
            $patientId ??= $name === 'patient' ? $value->getKey() : ($value->getAttribute('patient_id') ?: null);
            foreach (['bill_no', 'ipd_no', 'order_no', 'invoice_no', 'doc_no', 'batch_no'] as $number) {
                if (filled($value->getAttribute($number))) {
                    $names[] = (string) $value->getAttribute($number);
                }
            }
            if ($value instanceof User || in_array($name, ['medicine', 'service', 'stockist', 'test', 'package', 'bed'], true)) {
                $names[] = (string) ($value->getAttribute('name') ?? $value->getAttribute('bed_no'));
            }
        }
        // Something new for a patient named in what was sent rather than in the address.
        $patientId ??= is_numeric($request->input('patient_id')) ? (int) $request->input('patient_id') : null;

        return [$patientId, $subject, array_values(array_unique(array_filter($names)))];
    }

    /** A plain description for a change with no label of its own, e.g. "Added: doctor / templates". */
    private function describe(string $method, string $key): string
    {
        $path = trim(preg_replace('/\{[^}]+\}/', '', substr($key, strlen($method) + 1)), '/');
        $what = str_replace(['-', '/'], [' ', ' / '], preg_replace('#/+#', '/', $path));

        return ['POST' => 'Added', 'PUT' => 'Changed', 'PATCH' => 'Changed', 'DELETE' => 'Removed'][$method].': '.$what;
    }
}
