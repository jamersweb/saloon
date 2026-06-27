<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\BookingRule;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\StaffSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentAvailabilityEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_availability_endpoint_allows_overlapping_client_assignments(): void
    {
        $role = Role::create(['name' => 'manager', 'label' => 'Manager']);
        $user = User::factory()->create(['role_id' => $role->id]);

        BookingRule::create([
            'opening_time' => '09:00',
            'closing_time' => '22:00',
            'slot_interval_minutes' => 30,
            'min_advance_minutes' => 0,
            'max_advance_days' => 60,
        ]);

        $busyStaffUser = User::factory()->create(['role_id' => $role->id, 'name' => 'Busy Staff']);
        $freeStaffUser = User::factory()->create(['role_id' => $role->id, 'name' => 'Free Staff']);

        $busyStaff = StaffProfile::create(['user_id' => $busyStaffUser->id, 'employee_code' => 'EMP-1', 'is_active' => true]);
        $freeStaff = StaffProfile::create(['user_id' => $freeStaffUser->id, 'employee_code' => 'EMP-2', 'is_active' => true]);
        $service = SalonService::create([
            'name' => 'Test Service',
            'category' => 'Hair',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'price' => 100,
            'is_active' => true,
        ]);

        StaffSchedule::create([
            'staff_profile_id' => $busyStaff->id,
            'schedule_date' => '2026-04-28',
            'start_time' => '09:00',
            'end_time' => '22:00',
            'is_day_off' => false,
        ]);

        StaffSchedule::create([
            'staff_profile_id' => $freeStaff->id,
            'schedule_date' => '2026-04-28',
            'start_time' => '09:00',
            'end_time' => '22:00',
            'is_day_off' => false,
        ]);

        Appointment::create([
            'customer_name' => 'Existing Client',
            'customer_phone' => '971500000001',
            'service_id' => $service->id,
            'staff_profile_id' => $busyStaff->id,
            'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_start' => '2026-04-28 17:30:00',
            'scheduled_end' => '2026-04-28 18:30:00',
            'source' => 'admin',
        ]);

        $response = $this->actingAs($user)->get(route('appointments.staff-availability', [
            'scheduled_start' => '2026-04-28 17:40:00',
            'scheduled_end' => '2026-04-28 18:10:00',
        ]));

        $response->assertOk()
            ->assertJsonPath('staff.0.id', $busyStaff->id)
            ->assertJsonPath('staff.0.available', true)
            ->assertJsonPath('staff.1.id', $freeStaff->id)
            ->assertJsonPath('staff.1.available', true);
    }

    public function test_staff_availability_endpoint_auto_fills_missing_schedule_rows(): void
    {
        $role = Role::create(['name' => 'manager', 'label' => 'Manager']);
        $user = User::factory()->create(['role_id' => $role->id]);

        BookingRule::create([
            'opening_time' => '10:00',
            'closing_time' => '22:00',
            'slot_interval_minutes' => 30,
            'min_advance_minutes' => 0,
            'max_advance_days' => 60,
        ]);

        $staffUser = User::factory()->create(['role_id' => $role->id, 'name' => 'Majd Alabaza']);
        $staff = StaffProfile::create(['user_id' => $staffUser->id, 'employee_code' => 'VINA-03', 'is_active' => true]);

        $this->assertSame(0, StaffSchedule::query()->count());

        $response = $this->actingAs($user)->get(route('appointments.staff-availability', [
            'scheduled_start' => '2026-06-08 17:00:00',
            'scheduled_end' => '2026-06-08 18:00:00',
        ]));

        $response->assertOk()
            ->assertJsonPath('staff.0.id', $staff->id)
            ->assertJsonPath('staff.0.available', true);

        $this->assertTrue(
            StaffSchedule::query()
                ->where('staff_profile_id', $staff->id)
                ->whereDate('schedule_date', '2026-06-08')
                ->where('is_day_off', false)
                ->exists(),
        );
    }

    public function test_staff_availability_endpoint_excludes_removed_service_staff(): void
    {
        $role = Role::create(['name' => 'manager', 'label' => 'Manager']);
        $user = User::factory()->create(['role_id' => $role->id]);

        BookingRule::create([
            'opening_time' => '10:00',
            'closing_time' => '22:00',
            'slot_interval_minutes' => 30,
            'min_advance_minutes' => 0,
            'max_advance_days' => 60,
        ]);

        $removedUser = User::factory()->create(['role_id' => $role->id, 'name' => 'Analisa Rabanal Domenden']);
        StaffProfile::create(['user_id' => $removedUser->id, 'employee_code' => 'VINA-07', 'is_active' => true]);

        $activeUser = User::factory()->create(['role_id' => $role->id, 'name' => 'Majd Alabaza']);
        $activeStaff = StaffProfile::create(['user_id' => $activeUser->id, 'employee_code' => 'VINA-03', 'is_active' => true]);

        $response = $this->actingAs($user)->get(route('appointments.staff-availability', [
            'scheduled_start' => '2026-06-08 17:00:00',
            'scheduled_end' => '2026-06-08 18:00:00',
        ]));

        $response->assertOk()
            ->assertJsonCount(1, 'staff')
            ->assertJsonPath('staff.0.id', $activeStaff->id)
            ->assertJsonPath('staff.0.name', 'Majd Alabaza');
    }
}
