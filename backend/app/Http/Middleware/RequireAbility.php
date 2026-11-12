<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through only if the signed-in person's role has the named ability,
 * e.g. `ability:canManageSetup` calls Role::canManageSetup().
 */
class RequireAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $role = $request->user()?->role;

        abort_unless($role instanceof Role && method_exists($role, $ability) && $role->{$ability}(), 403, 'Your role is not allowed to do this.');

        return $next($request);
    }
}
