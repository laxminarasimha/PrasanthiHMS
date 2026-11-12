<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through only if the signed-in person has the named ability, from their own
 * permissions or else their role, e.g. `ability:canManageSetup`.
 */
class RequireAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        abort_unless($request->user()?->allows($ability), 403, 'You are not allowed to do this.');

        return $next($request);
    }
}
