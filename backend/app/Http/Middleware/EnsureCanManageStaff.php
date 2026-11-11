<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanManageStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->role->canManageStaff(), 403, 'You are not allowed to manage staff.');

        return $next($request);
    }
}
