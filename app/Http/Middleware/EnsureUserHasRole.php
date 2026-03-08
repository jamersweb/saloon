<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        $permissionKey = Permissions::routePermissionKey($request->route()?->getName());

        // Routes mapped to permissions are enforced by permissions first, regardless of role label.
        if ($permissionKey) {
            if (! $user->hasPermission($permissionKey)) {
                abort(403);
            }

            return $next($request);
        }

        if (! $user->hasRole(...$roles)) {
            abort(403);
        }

        return $next($request);
    }
}
