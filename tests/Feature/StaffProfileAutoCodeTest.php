<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffProfileAutoCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_creation_auto_generates_first_employee_code(): void
    {
        $managerRole = Role::create(['name' => 'manager', 'label' => 'Manager']);
        $staffRole = Role::create(['name' => 'staff', 'label' => 'Staff']);

        $manager = User::factory()->create(['role_id' => $managerRole->id]);

        $this->actingAs($manager)
            ->post(route('staff.store'), [
                'name' => 'Aisha Khan',
                'email' => 'aisha@example.com',
                'phone' => '03001234567',
                'skills' => 'Styling, Color',
                'hourly_rate' => '25.00',
                'password' => 'Password@123',
                'role_id' => $staffRole->id,
            ])
            ->assertSessionHasNoErrors();

        $profile = StaffProfile::query()->with('user')->first();

        $this->assertNotNull($profile);
        $this->assertSame('EMP-101', $profile->employee_code);
        $this->assertSame('Aisha Khan', $profile->user?->name);
    }

    public function test_staff_creation_increments_from_latest_existing_employee_code(): void
    {
        $managerRole = Role::create(['name' => 'manager', 'label' => 'Manager']);
        $staffRole = Role::create(['name' => 'staff', 'label' => 'Staff']);

        $manager = User::factory()->create(['role_id' => $managerRole->id]);
        $existingStaffUser = User::factory()->create([
            'role_id' => $staffRole->id,
            'email' => 'existing-staff@example.com',
        ]);

        StaffProfile::create([
            'user_id' => $existingStaffUser->id,
            'employee_code' => 'EMP-105',
            'phone' => null,
            'skills' => [],
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->post(route('staff.store'), [
                'name' => 'Bilal Ahmed',
                'email' => 'bilal@example.com',
                'phone' => '03007654321',
                'skills' => 'Massage',
                'hourly_rate' => '30.00',
                'password' => 'Password@123',
                'role_id' => $staffRole->id,
            ])
            ->assertSessionHasNoErrors();

        $profile = StaffProfile::query()
            ->whereHas('user', fn ($query) => $query->where('email', 'bilal@example.com'))
            ->first();

        $this->assertNotNull($profile);
        $this->assertSame('EMP-106', $profile->employee_code);
    }
}
