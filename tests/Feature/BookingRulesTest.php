<?php

namespace Tests\Feature;

use App\Models\Appointment;
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

    // Public checkout policies are covered by PublicReservationTest.
    public function test_appointment_update_uses_service_duration_instead_of_manual_end_time(): void
    {
        $ownerRole = Role::create(['name' => 'owner', 'label' => 'Owner']);
        $user = User::factory()->create(['role_id' => $ownerRole->id]);

        $staffUser = User::factory()->create();
        $staffProfile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'STF-301',
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

        $service = SalonService::create([
            'name' => 'Blow Dry',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'price' => 80,
            'is_active' => true,
        ]);

        $appointment = Appointment::create([
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_start' => $start->copy(),
            'scheduled_end' => $start->copy()->addHour(),
            'customer_name' => 'Existing Customer',
            'customer_phone' => '5557771111',
        ]);

        $response = $this->actingAs($user)->put(route('appointments.update', $appointment), [
            'customer_name' => 'Existing Customer',
            'customer_phone' => '5557771111',
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'scheduled_start' => $start->toDateTimeString(),
            'scheduled_end' => $start->copy()->subMinutes(15)->toDateTimeString(),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $response->assertSessionHasNoErrors();

        $appointment->refresh();
        $this->assertTrue($appointment->scheduled_end->equalTo($start->copy()->addHour()));
    }
}
