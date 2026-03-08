<?php

namespace App\Http\Controllers;

use App\Support\Permissions;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function authorizeRoles(Request $request, string ...$roles): void
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        if ($user->hasRole(...$roles)) {
            return;
        }

        $permissionKey = Permissions::routePermissionKey($request->route()?->getName());
        if ($permissionKey && $user->hasPermission($permissionKey)) {
            return;
        }

        abort(403);
    }
}
