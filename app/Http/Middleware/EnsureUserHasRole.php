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

        if (! $user->hasRole(...$roles)) {
            $permissionKey = Permissions::routePermissionKey($request->route()?->getName());
            if (! $permissionKey || ! $user->hasPermission($permissionKey)) {
                abort(403);
            }
        }

        return $next($request);
    }
}
