<?php

namespace App\Http\Middleware;

use App\Models\PatientAccount;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the two kinds of login apart: `actor:staff` lets only hospital staff through,
 * `actor:patient` only a patient signed in from the mobile app. Both use the same kind of
 * token, so without this a patient's token would be accepted on staff screens.
 */
class OnlyActor
{
    public function handle(Request $request, Closure $next, string $kind): Response
    {
        $user = $request->user();
        $ok = $kind === 'patient' ? $user instanceof PatientAccount && $user->is_active : $user instanceof User;
        abort_unless($ok, 403, $kind === 'patient' ? 'Sign in with your patient account.' : 'This is for hospital staff only.');

        return $next($request);
    }
}
