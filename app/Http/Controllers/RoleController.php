<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Support\Audit;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        return Inertia::render('Roles/Index', [
            'roles' => Role::query()
                ->orderBy('label')
                ->withCount('users')
                ->get()
                ->map(fn (Role $role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'label' => $role->label,
                    'permissions' => $role->permissions ?? [],
                    'users_count' => $role->users_count,
                ]),
            'permissionCatalog' => Permissions::all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('roles', 'name')],
            'label' => ['required', 'string', 'max:100'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

        $role = Role::create([
            'name' => strtolower($data['name']),
            'label' => $data['label'],
            'permissions' => Permissions::normalize($data['permissions'] ?? []),
        ]);

        Audit::log($request->user()?->id, 'role.created', 'Role', $role->id, [
            'name' => $role->name,
        ]);

        return back()->with('status', 'Role created.');
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

        $role->update([
            'label' => $data['label'],
            'permissions' => Permissions::normalize($data['permissions'] ?? []),
        ]);

        Audit::log($request->user()?->id, 'role.updated', 'Role', $role->id, [
            'name' => $role->name,
        ]);

        return back()->with('status', 'Role updated.');
    }

    public function destroy(Request $request, Role $role): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        if (in_array($role->name, ['owner', 'manager', 'staff', 'customer'], true)) {
            return back()->withErrors(['role' => 'System roles cannot be deleted.']);
        }

        if ($role->users()->exists()) {
            return back()->withErrors(['role' => 'Cannot delete a role that is assigned to users.']);
        }

        $roleId = $role->id;
        $role->delete();

        Audit::log($request->user()?->id, 'role.deleted', 'Role', $roleId);

        return back()->with('status', 'Role deleted.');
    }
}
