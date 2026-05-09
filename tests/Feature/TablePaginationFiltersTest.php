<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\StaffSchedule;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TablePaginationFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_index_supports_filters_and_pagination(): void
    {
        $manager = $this->managerUser();
        $staffRole = Role::create([
            'name' => 'staff',
            'label' => 'Staff',
            'permissions' => Permissions::defaultsForRole('staff'),
        ]);

        $matchingUser = User::factory()->create([
            'role_id' => $staffRole->id,
            'name' => 'Mona Filter',
            'email' => 'mona.filter@example.com',
        ]);

        StaffProfile::create([
            'user_id' => $matchingUser->id,
            'employee_code' => 'EMP-201',
            'phone' => '971500000201',
            'skills' => ['Color'],
            'is_active' => true,
        ]);

        $inactiveUser = User::factory()->create([
            'role_id' => $staffRole->id,
            'name' => 'Inactive Person',
            'email' => 'inactive.person@example.com',
        ]);

        StaffProfile::create([
            'user_id' => $inactiveUser->id,
            'employee_code' => 'EMP-202',
            'phone' => '971500000202',
            'skills' => ['Nails'],
            'is_active' => false,
        ]);

        $this->actingAs($manager)
            ->get(route('staff.index', [
                'search' => 'Mona',
                'role_id' => $staffRole->id,
                'status' => 'active',
                'per_page' => 10,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Staff/Index')
                ->where('filters.search', 'Mona')
                ->where('filters.role_id', $staffRole->id)
                ->where('filters.status', 'active')
                ->where('staffProfiles.total', 1)
                ->has('staffProfiles.data', 1)
                ->where('staffProfiles.data.0.user.name', 'Mona Filter'));
    }

    public function test_services_index_supports_filters_and_pagination(): void
    {
        $manager = $this->managerUser();

        SalonService::create([
            'name' => 'Luxury Facial',
            'category' => 'Facial',
            'duration_minutes' => 90,
            'buffer_minutes' => 10,
            'price' => 300,
            'is_active' => true,
        ]);

        SalonService::create([
            'name' => 'Quick Trim',
            'category' => 'Hair',
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'price' => 50,
            'is_active' => false,
        ]);

        $this->actingAs($manager)
            ->get(route('services.index', [
                'search' => 'Facial',
                'category' => 'Facial',
                'status' => 'active',
                'min_price' => 200,
                'max_price' => 400,
                'min_duration' => 60,
                'max_duration' => 120,
                'per_page' => 10,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Services/Index')
                ->where('filters.search', 'Facial')
                ->where('filters.category', 'Facial')
                ->where('filters.status', 'active')
                ->where('services.total', 1)
                ->has('services.data', 1)
                ->where('services.data.0.name', 'Luxury Facial'));
    }

    public function test_leave_requests_index_supports_filters_and_pagination(): void
    {
        $manager = $this->managerUser();
        $staffRole = Role::firstOrCreate(
            ['name' => 'staff'],
            ['label' => 'Staff', 'permissions' => Permissions::defaultsForRole('staff')]
        );

        $staffUser = User::factory()->create([
            'role_id' => $staffRole->id,
            'name' => 'Layla Leave',
        ]);

        $profile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'EMP-301',
            'phone' => '971500000301',
            'skills' => [],
            'is_active' => true,
        ]);

        LeaveRequest::create([
            'staff_profile_id' => $profile->id,
            'start_date' => '2026-05-15',
            'end_date' => '2026-05-16',
            'reason' => 'Family trip',
            'status' => 'approved',
            'reviewed_by' => $manager->id,
            'reviewed_at' => now(),
        ]);

        LeaveRequest::create([
            'staff_profile_id' => $profile->id,
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-02',
            'reason' => 'Old request',
            'status' => 'rejected',
        ]);

        $this->actingAs($manager)
            ->get(route('leave-requests.index', [
                'staff_profile_id' => $profile->id,
                'status' => 'approved',
                'date_from' => '2026-05-01',
                'date_to' => '2026-05-31',
                'per_page' => 10,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('LeaveRequests/Index')
                ->where('filters.staff_profile_id', $profile->id)
                ->where('filters.status', 'approved')
                ->where('leaveRequests.total', 1)
                ->has('leaveRequests.data', 1)
                ->where('leaveRequests.data.0.reason', 'Family trip'));
    }

    public function test_inventory_index_supports_filters_and_pagination(): void
    {
        $manager = $this->managerUser();

        InventoryItem::create([
            'sku' => 'INV-001',
            'name' => 'Argan Shampoo',
            'category' => 'Haircare',
            'unit' => 'pcs',
            'cost_price' => 25,
            'selling_price' => 60,
            'stock_quantity' => 3,
            'reorder_level' => 5,
            'is_active' => true,
        ]);

        InventoryItem::create([
            'sku' => 'INV-002',
            'name' => 'Nail Polish',
            'category' => 'Nails',
            'unit' => 'pcs',
            'cost_price' => 10,
            'selling_price' => 20,
            'stock_quantity' => 20,
            'reorder_level' => 5,
            'is_active' => true,
        ]);

        $this->actingAs($manager)
            ->get(route('inventory.index', [
                'search' => 'Argan',
                'category' => 'Haircare',
                'stock_status' => 'low',
                'min_price' => 50,
                'max_price' => 100,
                'per_page' => 10,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inventory/Index')
                ->where('filters.search', 'Argan')
                ->where('filters.category', 'Haircare')
                ->where('filters.stock_status', 'low')
                ->where('items.total', 1)
                ->has('items.data', 1)
                ->where('items.data.0.sku', 'INV-001'));
    }

    public function test_schedules_index_supports_filters_and_pagination(): void
    {
        $manager = $this->managerUser();
        $staffRole = Role::firstOrCreate(
            ['name' => 'staff'],
            ['label' => 'Staff', 'permissions' => Permissions::defaultsForRole('staff')]
        );

        $staffUser = User::factory()->create([
            'role_id' => $staffRole->id,
            'name' => 'Sara Schedule',
        ]);

        $profile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'EMP-401',
            'phone' => '971500000401',
            'skills' => [],
            'is_active' => true,
        ]);

        StaffSchedule::create([
            'staff_profile_id' => $profile->id,
            'schedule_date' => '2026-05-20',
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'break_start' => '13:00:00',
            'break_end' => '14:00:00',
            'is_day_off' => false,
        ]);

        StaffSchedule::create([
            'staff_profile_id' => $profile->id,
            'schedule_date' => '2026-05-21',
            'is_day_off' => true,
            'notes' => 'Leave',
        ]);

        $this->actingAs($manager)
            ->get(route('schedules.index', [
                'search' => 'Sara',
                'staff_profile_id' => $profile->id,
                'date_from' => '2026-05-20',
                'date_to' => '2026-05-20',
                'day_off' => 'working',
                'per_page' => 10,
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Schedules/Index')
                ->where('filters.search', 'Sara')
                ->where('filters.staff_profile_id', $profile->id)
                ->where('filters.day_off', 'working')
                ->where('schedules.total', 1)
                ->has('schedules.data', 1)
                ->where('schedules.data.0.staff_name', 'Sara Schedule'));
    }

    private function managerUser(): User
    {
        $managerRole = Role::firstOrCreate(
            ['name' => 'manager'],
            ['label' => 'Manager', 'permissions' => Permissions::defaultsForRole('manager')]
        );

        return User::factory()->create(['role_id' => $managerRole->id]);
    }
}
