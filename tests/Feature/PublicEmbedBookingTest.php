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

class PublicEmbedBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_embed_booking_form_renders(): void
    {
        $this->get(route('embed.booking'))
            ->assertOk()
            ->assertSee('Book Your Appointment', false)
            ->assertSee(route('embed.booking.store'), false);
    }

    public function test_embed_booking_success_redirects_to_thanks(): void
    {
        Role::create(['name' => 'staff', 'label' => 'Staff']);

        $staffUser = User::factory()->create();
        $staffProfile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'STF-EMB-1',
            'is_active' => true,
        ]);

        $start = now()->addDays(3)->setTime(11, 0);

        StaffSchedule::create([
            'staff_profile_id' => $staffProfile->id,
            'schedule_date' => $start->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'is_day_off' => false,
        ]);

        $service = SalonService::create([
            'name' => 'Embed Cut',
            'duration_minutes' => 60,
            'buffer_minutes' => 10,
            'price' => 100,
            'is_active' => true,
        ]);

        $this->followingRedirects()->post(route('embed.booking.store'), [
            'customer_name' => 'Embed User',
            'customer_phone' => '5558889999',
            'customer_email' => 'embed@example.com',
            'service_id' => $service->id,
            'scheduled_start' => $start->toDateTimeString(),
        ])
            ->assertOk()
            ->assertSee('Thank you', false);

        $appointment = Appointment::query()->where('customer_phone', '5558889999')->latest()->first();
        $this->assertNotNull($appointment);
        $this->assertSame($staffProfile->id, $appointment->staff_profile_id);
    }

    public function test_embed_booking_validation_errors_return_to_form(): void
    {
        $this->from(route('embed.booking'))
            ->post(route('embed.booking.store'), [
                'customer_name' => '',
                'customer_phone' => '',
                'service_id' => '',
                'scheduled_start' => '',
            ])
            ->assertRedirect(route('embed.booking'))
            ->assertSessionHasErrors();
    }
}
