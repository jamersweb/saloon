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

class AppointmentConflictMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_returns_detailed_staff_conflict_message(): void
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
            'employee_code' => 'EMP-100',
            'is_active' => true,
        ]);

        StaffSchedule::create([
            'staff_profile_id' => $staff->id,
            'schedule_date' => '2026-04-28',
            'start_time' => '09:00',
            'end_time' => '22:00',
            'is_day_off' => false,
        ]);

        $service = SalonService::create([
            'name' => 'Eyelash Refill',
            'category' => 'Lashes',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'price' => 100,
            'is_active' => true,
        ]);

        Appointment::create([
            'customer_name' => 'Sara Ali',
            'customer_phone' => '971500000111',
            'service_id' => $service->id,
            'staff_profile_id' => $staff->id,
            'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_start' => '2026-04-28 17:30:00',
            'scheduled_end' => '2026-04-28 18:30:00',
            'source' => 'admin',
        ]);

        $response = $this->actingAs($user)->post(route('appointments.store'), [
            'customer_name' => 'Walk-in Client',
            'customer_phone' => '971500000222',
            'service_id' => $service->id,
            'service_ids' => [$service->id],
            'staff_profile_id' => $staff->id,
            'scheduled_start' => '2026-04-28 17:40:00',
            'scheduled_end' => '2026-04-28 18:10:00',
            'status' => 'confirmed',
        ]);

        $response->assertSessionHasErrors([
            'staff_profile_id' => 'Mona Bassagh is busy with Sara Ali (Apr 28, 5:30 PM - 6:30 PM).',
        ]);
    }
}
