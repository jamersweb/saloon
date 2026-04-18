<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\StaffScheduleGeneratorService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class StaffProfileController extends Controller
{
    public function __construct(private readonly StaffScheduleGeneratorService $staffScheduleGenerator) {}

    public function index(Request $request): Response
    {
        $showDeleted = $request->boolean('show_deleted');

        $staffQuery = $showDeleted
            ? StaffProfile::query()->onlyTrashed()->with('user.role')->orderByDesc('deleted_at')
            : StaffProfile::query()->with('user.role')->orderByDesc('id');

        return Inertia::render('Staff/Index', [
            'roles' => Role::query()
                ->where('name', '!=', 'customer')
                ->orderBy('label')
                ->get(['id', 'name', 'label']),
            'staffProfiles' => $staffQuery->get()->map(function (StaffProfile $staff) {
                return [
                    'id' => $staff->id,
                    'employee_code' => $staff->employee_code,
                    'phone' => $staff->phone,
                    'skills' => $staff->skills ?? [],
                    'hourly_rate' => $staff->hourly_rate !== null ? (float) $staff->hourly_rate : null,
                    'is_active' => $staff->is_active,
                    'deleted_at' => $staff->deleted_at?->toIso8601String(),
                    'user' => [
                        'id' => $staff->user?->id,
                        'name' => $staff->user?->name,
                        'email' => $staff->user?->email,
                        'role_id' => $staff->user?->role_id,
                        'role_label' => $staff->user?->role?->label,
                    ],
                ];
            }),
            'showDeleted' => $showDeleted,
            'trashedCount' => StaffProfile::onlyTrashed()->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:30'],
            'skills' => ['nullable', 'string'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'is_active' => ['nullable', 'boolean'],
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password'] ?? 'Password@123'),
            'role_id' => (int) $data['role_id'],
        ]);

        $profile = StaffProfile::create([
            'user_id' => $user->id,
            'employee_code' => $this->generateEmployeeCode(),
            'phone' => $data['phone'] ?? null,
            'skills' => $this->parseSkills($data['skills'] ?? ''),
            'hourly_rate' => isset($data['hourly_rate']) && $data['hourly_rate'] !== '' && $data['hourly_rate'] !== null
                ? $data['hourly_rate']
                : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        $this->staffScheduleGenerator->seedMonthForNewStaffProfile($profile);

        Audit::log($request->user()->id, 'staff.created', 'StaffProfile', $profile->id, [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        return back()->with('status', 'Staff created. Default password: Password@123 (unless provided).');
    }

    public function update(Request $request, StaffProfile $staff): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$staff->user_id],
            'phone' => ['nullable', 'string', 'max:30'],
            'skills' => ['nullable', 'string'],
            'hourly_rate' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'is_active' => ['nullable', 'boolean'],
            'role_id' => ['required', 'exists:roles,id'],
        ]);

        $staff->user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'role_id' => (int) $data['role_id'],
        ]);

        $staff->update([
            'phone' => $data['phone'] ?? null,
            'skills' => $this->parseSkills($data['skills'] ?? ''),
            'hourly_rate' => array_key_exists('hourly_rate', $data)
                ? ($data['hourly_rate'] === '' || $data['hourly_rate'] === null ? null : $data['hourly_rate'])
                : $staff->hourly_rate,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        Audit::log($request->user()->id, 'staff.updated', 'StaffProfile', $staff->id);

        return back()->with('status', 'Staff updated.');
    }

    public function deactivate(Request $request, StaffProfile $staff): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $staff->update(['is_active' => false]);

        Audit::log($request->user()->id, 'staff.deactivated', 'StaffProfile', $staff->id);

        return back()->with('status', 'Staff deactivated.');
    }

    public function destroy(Request $request, StaffProfile $staff): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $staff->delete();

        Audit::log($request->user()->id, 'staff.deleted', 'StaffProfile', $staff->id);

        return back()->with('status', 'Staff member removed from the team. Restore from Removed staff if this was a mistake.');
    }

    public function restore(Request $request, int $staff): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $profile = StaffProfile::onlyTrashed()->findOrFail($staff);
        $profile->restore();

        Audit::log($request->user()->id, 'staff.restored', 'StaffProfile', $profile->id);

        return back()->with('status', 'Staff member restored to the team list.');
    }

    /** @return list<string> */
    private function parseSkills(string $skills): array
    {
        return collect(explode(',', $skills))
            ->map(fn (string $skill) => trim($skill))
            ->filter()
            ->values()
            ->all();
    }

    private function generateEmployeeCode(): string
    {
        $connection = StaffProfile::query()->getConnection();
        $numericSuffixOrder = match ($connection->getDriverName()) {
            'mysql' => 'CAST(SUBSTRING(employee_code, 5) AS UNSIGNED) DESC',
            default => 'CAST(SUBSTR(employee_code, 5) AS INTEGER) DESC',
        };

        $latestCode = StaffProfile::withTrashed()
            ->where('employee_code', 'like', 'EMP-%')
            ->orderByRaw($numericSuffixOrder)
            ->value('employee_code');

        $nextNumber = 101;

        if (is_string($latestCode) && preg_match('/^EMP-(\d+)$/', $latestCode, $matches)) {
            $nextNumber = ((int) $matches[1]) + 1;
        }

        do {
            $code = sprintf('EMP-%03d', $nextNumber);
            $exists = StaffProfile::withTrashed()->where('employee_code', $code)->exists();
            $nextNumber++;
        } while ($exists);

        return $code;
    }
}
