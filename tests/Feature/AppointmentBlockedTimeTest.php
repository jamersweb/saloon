<?php

namespace Tests\Feature;

use App\Models\AppointmentBlock;
use App\Models\BookingRule;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\StaffSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentBlockedTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_availability_endpoint_marks_blocked_time_unavailable(): void
    {
        [$user, $staff] = $this->createScheduledStaff();

        AppointmentBlock::create([
            'staff_profile_id' => $staff->id,
            'title' => 'Lunch',
            'starts_at' => '2026-06-12 13:00:00',
            'ends_at' => '2026-06-12 14:00:00',
        ]);

        $response = $this->actingAs($user)->get(route('appointments.staff-availability', [
            'scheduled_start' => '2026-06-12 13:15:00',
            'scheduled_end' => '2026-06-12 13:45:00',
        ]));

        $response->assertOk()
            ->assertJsonPath('staff.0.id', $staff->id)
            ->assertJsonPath('staff.0.available', false)
            ->assertJsonPath('staff.0.reason', 'Selected time overlaps blocked time.');
    }

    public function test_internal_appointment_creation_rejects_blocked_time(): void
    {
        [$user, $staff] = $this->createScheduledStaff();

        $service = SalonService::create([
            'name' => 'Hair Styling',
            'category' => 'Hair',
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'price' => 100,
            'is_active' => true,
        ]);

        AppointmentBlock::create([
            'staff_profile_id' => $staff->id,
            'title' => 'Training',
            'starts_at' => '2026-06-12 15:00:00',
            'ends_at' => '2026-06-12 16:00:00',
        ]);

        $response = $this->actingAs($user)->post(route('appointments.store'), [
            'customer_name' => 'Blocked Client',
            'customer_phone' => '971500000999',
            'service_id' => $service->id,
            'service_ids' => [$service->id],
            'staff_profile_id' => $staff->id,
            'scheduled_start' => '2026-06-12 15:15:00',
            'status' => 'confirmed',
        ]);

        $response->assertSessionHasErrors(['staff_profile_id']);
    }

    /**
     * @return array{0: User, 1: StaffProfile}
     */
    private function createScheduledStaff(): array
    {
        $role = Role::create(['name' => 'manager', 'label' => 'Manager']);
        $user = User::factory()->create(['role_id' => $role->id]);
        $staffUser = User::factory()->create(['role_id' => $role->id, 'name' => 'Mona Bassagh']);

        BookingRule::create([
            'opening_time' => '09:00',
            'closing_time' => '22:00',
            'slot_interval_minutes' => 30,
            'min_advance_minutes' => 0,
            'max_advance_days' => 60,
        ]);

        $staff = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'EMP-BLOCK',
            'is_active' => true,
        ]);

        StaffSchedule::create([
            'staff_profile_id' => $staff->id,
            'schedule_date' => '2026-06-12',
            'start_time' => '09:00',
            'end_time' => '22:00',
            'is_day_off' => false,
        ]);

        return [$user, $staff];
    }
}
