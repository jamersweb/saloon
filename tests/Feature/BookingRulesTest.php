<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\StaffSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_booking_blocks_staff_on_approved_leave(): void
    {
        Role::create(['name' => 'staff', 'label' => 'Staff']);
        $staffUser = User::factory()->create();
        $staffProfile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'STF-100',
            'is_active' => true,
        ]);

        $start = now()->addDays(2)->setTime(10, 0);

        StaffSchedule::create([
            'staff_profile_id' => $staffProfile->id,
            'schedule_date' => $start->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'is_day_off' => false,
        ]);

        LeaveRequest::create([
            'staff_profile_id' => $staffProfile->id,
            'start_date' => $start->toDateString(),
            'end_date' => $start->toDateString(),
            'reason' => 'Annual leave',
            'status' => 'approved',
        ]);

        $service = SalonService::create([
            'name' => 'Hair Cut',
            'duration_minutes' => 60,
            'buffer_minutes' => 10,
            'price' => 100,
            'is_active' => true,
        ]);

        $this->post(route('public.booking.store'), [
            'customer_name' => 'Public User',
            'customer_phone' => '5551112222',
            'customer_email' => 'public@example.com',
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'scheduled_start' => $start->toDateTimeString(),
        ])->assertSessionHasErrors(['staff_profile_id']);
    }

    public function test_public_booking_auto_assigns_available_staff_when_not_selected(): void
    {
        Role::create(['name' => 'staff', 'label' => 'Staff']);

        $staffOneUser = User::factory()->create();
        $staffTwoUser = User::factory()->create();

        $staffOne = StaffProfile::create([
            'user_id' => $staffOneUser->id,
            'employee_code' => 'STF-201',
            'is_active' => true,
        ]);

        $staffTwo = StaffProfile::create([
            'user_id' => $staffTwoUser->id,
            'employee_code' => 'STF-202',
            'is_active' => true,
        ]);

        $start = now()->addDays(3)->setTime(11, 0);

        StaffSchedule::create([
            'staff_profile_id' => $staffOne->id,
            'schedule_date' => $start->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'is_day_off' => false,
        ]);

        StaffSchedule::create([
            'staff_profile_id' => $staffTwo->id,
            'schedule_date' => $start->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'is_day_off' => false,
        ]);

        $service = SalonService::create([
            'name' => 'Color',
            'duration_minutes' => 90,
            'buffer_minutes' => 10,
            'price' => 150,
            'is_active' => true,
        ]);

        Appointment::create([
            'service_id' => $service->id,
            'staff_profile_id' => $staffOne->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_start' => $start->copy(),
            'scheduled_end' => $start->copy()->addMinutes(100),
            'customer_name' => 'Busy Slot',
            'customer_phone' => '5550000000',
        ]);

        $this->post(route('public.booking.store'), [
            'customer_name' => 'Public User Two',
            'customer_phone' => '5553334444',
            'customer_email' => 'public2@example.com',
            'service_id' => $service->id,
            'scheduled_start' => $start->toDateTimeString(),
        ])->assertSessionHasNoErrors();

        $appointment = Appointment::query()->where('customer_phone', '5553334444')->latest()->first();

        $this->assertNotNull($appointment);
        $this->assertSame($staffTwo->id, $appointment->staff_profile_id);
    }
}

