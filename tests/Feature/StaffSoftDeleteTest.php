<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StaffSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivate_sets_inactive_without_soft_delete(): void
    {
        $managerRole = Role::create(['name' => 'manager', 'label' => 'Manager']);
        $staffRole = Role::create(['name' => 'staff', 'label' => 'Staff']);
        $manager = User::factory()->create(['role_id' => $managerRole->id]);
        $staffUser = User::factory()->create(['role_id' => $staffRole->id, 'email' => 'staff-soft@example.com']);
        $profile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'EMP-201',
            'phone' => null,
            'skills' => [],
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->post(route('staff.deactivate', $profile))
            ->assertRedirect();

        $profile->refresh();
        $this->assertFalse($profile->is_active);
        $this->assertNull($profile->deleted_at);
    }

    public function test_destroy_soft_deletes_profile(): void
    {
        $managerRole = Role::create(['name' => 'manager', 'label' => 'Manager']);
        $staffRole = Role::create(['name' => 'staff', 'label' => 'Staff']);
        $manager = User::factory()->create(['role_id' => $managerRole->id]);
        $staffUser = User::factory()->create(['role_id' => $staffRole->id, 'email' => 'staff-del@example.com']);
        $profile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'EMP-202',
            'phone' => null,
            'skills' => [],
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->delete(route('staff.destroy', $profile))
            ->assertRedirect();

        $this->assertSoftDeleted('staff_profiles', ['id' => $profile->id]);
        $this->assertNull(StaffProfile::query()->find($profile->id));
        $this->assertNotNull(StaffProfile::withTrashed()->find($profile->id));
    }

    public function test_restore_brings_profile_back(): void
    {
        $managerRole = Role::create(['name' => 'manager', 'label' => 'Manager']);
        $staffRole = Role::create(['name' => 'staff', 'label' => 'Staff']);
        $manager = User::factory()->create(['role_id' => $managerRole->id]);
        $staffUser = User::factory()->create(['role_id' => $staffRole->id, 'email' => 'staff-restore@example.com']);
        $profile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'EMP-203',
            'phone' => null,
            'skills' => [],
            'is_active' => true,
        ]);
        $profile->delete();

        $this->actingAs($manager)
            ->post(route('staff.restore', $profile->id))
            ->assertRedirect();

        $profile->refresh();
        $this->assertNull($profile->deleted_at);
        $this->assertNotNull(StaffProfile::query()->find($profile->id));
    }

    public function test_index_lists_only_trashed_when_show_deleted(): void
    {
        $managerRole = Role::create(['name' => 'manager', 'label' => 'Manager']);
        $staffRole = Role::create(['name' => 'staff', 'label' => 'Staff']);
        $manager = User::factory()->create(['role_id' => $managerRole->id]);

        $activeUser = User::factory()->create(['role_id' => $staffRole->id, 'email' => 'active-list@example.com']);
        $removedUser = User::factory()->create(['role_id' => $staffRole->id, 'email' => 'removed-list@example.com']);

        StaffProfile::create([
            'user_id' => $activeUser->id,
            'employee_code' => 'EMP-301',
            'phone' => null,
            'skills' => [],
            'is_active' => true,
        ]);
        $removed = StaffProfile::create([
            'user_id' => $removedUser->id,
            'employee_code' => 'EMP-302',
            'phone' => null,
            'skills' => [],
            'is_active' => true,
        ]);
        $removed->delete();

        $this->actingAs($manager)
            ->get(route('staff.index', ['show_deleted' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Staff/Index')
                ->where('showDeleted', true)
                ->has('staffProfiles', 1)
                ->where('staffProfiles.0.id', $removed->id));
    }
}
