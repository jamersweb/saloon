<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyRolePermissionFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_with_empty_legacy_permissions_can_access_staff_routes(): void
    {
        $staffRole = Role::create([
            'name' => 'staff',
            'label' => 'Staff',
            'permissions' => [],
        ]);

        $user = User::factory()->create([
            'role_id' => $staffRole->id,
        ]);

        StaffProfile::create([
            'user_id' => $user->id,
            'employee_code' => 'STF-LEGACY-01',
            'is_active' => true,
        ]);

        $this->actingAs($user)->get(route('attendance.index'))->assertOk();
        $this->actingAs($user)->get(route('leave-requests.index'))->assertOk();
    }
}
