<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two limits that hold for a login whatever screen or app is asking:
 *
 * - someone handed a starting PIN or password can do nothing until they have chosen their own;
 * - a login with the "own record only" role reaches its own attendance, leave and payslips and nothing else.
 *   Everything is refused unless it is named here, so a screen added later is closed to these logins by default.
 */
class LoginLimits
{
    /** What anyone may do while they still have to choose their own PIN or password. */
    private const WHILE_CHOOSING = ['api/me', 'api/logout', 'api/me/password'];

    /** What an own-record login may reach. `*` stands for anything after it. */
    private const OWN_RECORD = [
        'api/me', 'api/me/password', 'api/me/appearance', 'api/me/tabs', 'api/logout', 'api/account/google',
        'api/workforce/me', 'api/workforce/me/*', 'api/workforce/access',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User) {
            abort_if($user->must_change_secret && ! $request->is(...self::WHILE_CHOOSING), 403, 'Choose your own PIN or password first.');
            abort_if($user->role === Role::Staff && ! $request->is(...self::OWN_RECORD), 403, 'Your login opens only your own record.');
        }

        return $next($request);
    }
}
