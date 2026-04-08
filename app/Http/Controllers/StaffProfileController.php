<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class StaffProfileController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Staff/Index', [
            'roles' => Role::query()
                ->where('name', '!=', 'customer')
                ->orderBy('label')
                ->get(['id', 'name', 'label']),
            'staffProfiles' => StaffProfile::query()->with('user.role')->latest()->get()->map(function (StaffProfile $staff) {
                return [
                    'id' => $staff->id,
                    'employee_code' => $staff->employee_code,
                    'phone' => $staff->phone,
                    'skills' => $staff->skills ?? [],
                    'hourly_rate' => $staff->hourly_rate !== null ? (float) $staff->hourly_rate : null,
                    'is_active' => $staff->is_active,
                    'user' => [
                        'id' => $staff->user?->id,
                        'name' => $staff->user?->name,
                        'email' => $staff->user?->email,
                        'role_id' => $staff->user?->role_id,
                        'role_label' => $staff->user?->role?->label,
                    ],
                ];
            }),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['nullable', 'string', 'min:8'],
            'employee_code' => ['required', 'string', 'max:50', 'unique:staff_profiles,employee_code'],
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
            'employee_code' => $data['employee_code'],
            'phone' => $data['phone'] ?? null,
            'skills' => $this->parseSkills($data['skills'] ?? ''),
            'hourly_rate' => isset($data['hourly_rate']) && $data['hourly_rate'] !== '' && $data['hourly_rate'] !== null
                ? $data['hourly_rate']
                : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        $monthStart = CarbonImmutable::now()->startOfMonth();
        $monthEnd = CarbonImmutable::now()->endOfMonth();

        for ($date = $monthStart; $date->lessThanOrEqualTo($monthEnd); $date = $date->addDay()) {
            StaffSchedule::updateOrCreate(
                [
                    'staff_profile_id' => $profile->id,
                    'schedule_date' => $date->toDateString(),
                ],
                [
                    'start_time' => '10:00',
                    'end_time' => '20:00',
                    'break_start' => null,
                    'break_end' => null,
                    'is_day_off' => false,
                    'notes' => 'Auto-assigned monthly default shift',
                ],
            );
        }

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

    public function destroy(Request $request, StaffProfile $staff): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $staff->update(['is_active' => false]);

        Audit::log($request->user()->id, 'staff.deactivated', 'StaffProfile', $staff->id);

        return back()->with('status', 'Staff deactivated.');
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
}
