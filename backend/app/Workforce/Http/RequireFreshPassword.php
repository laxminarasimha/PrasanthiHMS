<?php

namespace App\Workforce\Http;

use App\Workforce\Support\Reauth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a sensitive step through only if the person typed their password again in the last few minutes. Otherwise
 * it answers 428, which the screens read as "ask for the password, then try again".
 */
class RequireFreshPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() && Reauth::fresh($request->user()), 428, 'Type your password again to do this.');

        return $next($request);
    }
}
