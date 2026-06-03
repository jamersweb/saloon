<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\StaffSchedule;
use App\Models\BookingRule;
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
        $this->assertSame($staffOne->id, $appointment->staff_profile_id);
    }

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

    public function test_public_booking_updates_existing_customer_contact_details(): void
    {
        Role::create(['name' => 'staff', 'label' => 'Staff']);

        $staffUser = User::factory()->create();
        $staffProfile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'STF-401',
            'is_active' => true,
        ]);

        $customer = Customer::create([
            'customer_code' => 'CUST-OLD-1001',
            'name' => 'Old Name',
            'phone' => '5559998888',
            'email' => null,
            'is_active' => true,
        ]);

        $start = now()->addDays(4)->setTime(12, 0);

        StaffSchedule::create([
            'staff_profile_id' => $staffProfile->id,
            'schedule_date' => $start->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'is_day_off' => false,
        ]);

        $service = SalonService::create([
            'name' => 'Facial',
            'duration_minutes' => 45,
            'buffer_minutes' => 15,
            'price' => 120,
            'is_active' => true,
        ]);

        $this->post(route('public.booking.store'), [
            'customer_name' => 'Updated Name',
            'customer_phone' => $customer->phone,
            'customer_email' => 'updated@example.com',
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'scheduled_start' => $start->toDateTimeString(),
        ])->assertSessionHasNoErrors();

        $customer->refresh();

        $this->assertSame('Updated Name', $customer->name);
        $this->assertSame('updated@example.com', $customer->email);
    }

    public function test_booking_allows_times_within_overnight_shift(): void
    {
        Role::create(['name' => 'staff', 'label' => 'Staff']);

        $staffUser = User::factory()->create();
        $staffProfile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'STF-OVER-01',
            'is_active' => true,
        ]);

        $shiftDate = now()->addDays(2)->startOfDay();

        StaffSchedule::create([
            'staff_profile_id' => $staffProfile->id,
            'schedule_date' => $shiftDate->toDateString(),
            'start_time' => '13:00:00',
            'end_time' => '01:00:00',
            'is_day_off' => false,
        ]);

        $service = SalonService::create([
            'name' => 'Night Service',
            'duration_minutes' => 60,
            'buffer_minutes' => 10,
            'price' => 100,
            'is_active' => true,
        ]);

        $this->post(route('public.booking.store'), [
            'customer_name' => 'Overnight Customer',
            'customer_phone' => '5551122334',
            'customer_email' => 'overnight@example.com',
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'scheduled_start' => $shiftDate->copy()->setTime(15, 0)->toDateTimeString(),
        ])->assertSessionHasNoErrors();
    }

    public function test_completed_appointment_does_not_block_staff_availability(): void
    {
        Role::create(['name' => 'staff', 'label' => 'Staff']);

        $staffUser = User::factory()->create();
        $staffProfile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'STF-FREE-01',
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
            'name' => 'Root Color',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'price' => 150,
            'is_active' => true,
        ]);

        Appointment::create([
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_COMPLETED,
            'scheduled_start' => $start->copy(),
            'scheduled_end' => $start->copy()->addHour(),
            'customer_name' => 'Finished Customer',
            'customer_phone' => '5551212121',
        ]);

        $this->post(route('public.booking.store'), [
            'customer_name' => 'New Customer',
            'customer_phone' => '5553434343',
            'customer_email' => 'new@example.com',
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'scheduled_start' => $start->toDateTimeString(),
        ])->assertSessionHasNoErrors();
    }

    public function test_public_booking_is_saved_as_pending_even_when_auto_confirm_is_disabled(): void
    {
        Role::create(['name' => 'staff', 'label' => 'Staff']);
        $staffUser = User::factory()->create();
        $staffProfile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'STF-PEND-01',
            'is_active' => true,
        ]);

        BookingRule::current()->update(['public_requires_approval' => false]);

        $start = now()->addDays(2)->setTime(10, 0);

        StaffSchedule::create([
            'staff_profile_id' => $staffProfile->id,
            'schedule_date' => $start->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'is_day_off' => false,
        ]);

        $service = SalonService::create([
            'name' => 'Pending Service',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'price' => 100,
            'is_active' => true,
        ]);

        $this->post(route('public.booking.store'), [
            'customer_name' => 'Pending Customer',
            'customer_phone' => '5559988776',
            'customer_email' => 'pending@example.com',
            'service_id' => $service->id,
            'scheduled_start' => $start->toDateTimeString(),
        ])->assertSessionHasNoErrors();

        $appointment = Appointment::query()->where('customer_phone', '5559988776')->latest()->first();

        $this->assertNotNull($appointment);
        $this->assertSame(Appointment::STATUS_PENDING, $appointment->status);
    }

    public function test_public_booking_future_date_auto_fills_missing_staff_schedule(): void
    {
        Role::create(['name' => 'staff', 'label' => 'Staff']);

        $staffUser = User::factory()->create();
        $staffProfile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'STF-FUTURE-01',
            'is_active' => true,
        ]);

        $start = now()->addDays(40)->setTime(10, 0);

        $service = SalonService::create([
            'name' => 'Future Booking Service',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'price' => 90,
            'is_active' => true,
        ]);

        $this->post(route('public.booking.store'), [
            'customer_name' => 'Future Customer',
            'customer_phone' => '5558844221',
            'customer_email' => 'future@example.com',
            'service_id' => $service->id,
            'scheduled_start' => $start->toDateTimeString(),
        ])->assertSessionHasNoErrors();

        $this->assertTrue(
            StaffSchedule::query()
                ->where('staff_profile_id', $staffProfile->id)
                ->whereDate('schedule_date', $start->toDateString())
                ->where('is_day_off', false)
                ->exists()
        );
    }
}
