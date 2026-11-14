<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Runs every request that records or changes money as one step in the database: it is saved
 * whole or not at all. This is also what makes the day close airtight. A bill being saved while
 * the day is being closed either finishes first, and is counted in the closing, or is refused;
 * it can never land in the day after the closing has counted it (see DayClose::refuseIfClosed).
 */
class MoneyInOneStep
{
    /** Addresses under /api whose POST, PUT and DELETE requests carry money. */
    private const MONEY = '#^api/(appointments$|bills(/|$)|patients/[^/]+/deposits$|lab/orders$|pharmacy/sales(/|$)|ipd/admissions/[^/]+/(charges|payments|bills|transfer|templates)(/|$))#';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || ! preg_match(self::MONEY, $request->path())) {
            return $next($request);
        }

        DB::beginTransaction();
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        // A refusal or an error reaches here as an answer, not as an exception, so it is checked by its status.
        $response->getStatusCode() >= 400 ? DB::rollBack() : DB::commit();

        return $response;
    }
}
