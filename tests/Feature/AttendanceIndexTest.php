<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AttendanceIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_index_supports_filters_and_pagination(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);

        $manager = User::factory()->create(['role_id' => $managerRole->id]);

        $staffRole = Role::create([
            'name' => 'staff',
            'label' => 'Staff',
            'permissions' => Permissions::defaultsForRole('staff'),
        ]);

        $userOne = User::factory()->create(['role_id' => $staffRole->id, 'name' => 'Staff One']);
        $userTwo = User::factory()->create(['role_id' => $staffRole->id, 'name' => 'Staff Two']);

        $profileOne = StaffProfile::create([
            'user_id' => $userOne->id,
            'employee_code' => 'EMP-ATT-01',
            'is_active' => true,
        ]);

        $profileTwo = StaffProfile::create([
            'user_id' => $userTwo->id,
            'employee_code' => 'EMP-ATT-02',
            'is_active' => true,
        ]);

        foreach (range(1, 12) as $index) {
            AttendanceLog::create([
                'staff_profile_id' => $profileOne->id,
                'attendance_date' => now()->subDays($index)->toDateString(),
                'clock_in' => '09:00:00',
                'clock_out' => '18:00:00',
                'late_minutes' => 0,
            ]);
        }

        AttendanceLog::create([
            'staff_profile_id' => $profileTwo->id,
            'attendance_date' => now()->toDateString(),
            'clock_in' => '09:15:00',
            'clock_out' => '18:00:00',
            'late_minutes' => 15,
        ]);

        $this->actingAs($manager)
            ->get(route('attendance.index', [
                'staff_profile_id' => $profileTwo->id,
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->toDateString(),
                'per_page' => 10,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Attendance/Index')
                ->where('filters.staff_profile_id', $profileTwo->id)
                ->where('filters.per_page', 10)
                ->where('logs.total', 1)
                ->has('logs.data', 1)
                ->where('logs.data.0.staff_name', 'Staff Two')
                ->where('logs.data.0.attendance_date', now()->toDateString()));
    }
}
