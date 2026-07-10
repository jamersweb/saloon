<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\CommunicationLog;
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

    public function test_public_privacy_policy_page_renders(): void
    {
        $this->get(route('public.privacy-policy'))
            ->assertOk()
            ->assertSee('Privacy Policy', false)
            ->assertSee('WhatsApp Business API', false);
    }

    public function test_public_terms_of_service_page_renders(): void
    {
        $this->get(route('public.terms-of-service'))
            ->assertOk()
            ->assertSee('Terms of Service', false)
            ->assertSee('WhatsApp Business', false);
    }

    public function test_embed_booking_form_renders(): void
    {
        $this->get(route('embed.booking'))
            ->assertOk()
            ->assertSee('Book Your Appointment', false)
            ->assertSee('+971111111111', false)
            ->assertDontSee('Staff Profile', false)
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

    public function test_embed_booking_still_succeeds_when_team_whatsapp_phone_is_invalid(): void
    {
        $staffRole = Role::create(['name' => 'staff', 'label' => 'Staff']);
        $managerRole = Role::create(['name' => 'manager', 'label' => 'Manager']);

        $staffUser = User::factory()->create(['role_id' => $staffRole->id]);
        $managerUser = User::factory()->create(['role_id' => $managerRole->id]);

        $staffProfile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'STF-EMB-2',
            'is_active' => true,
        ]);

        StaffProfile::create([
            'user_id' => $managerUser->id,
            'employee_code' => 'MGR-EMB-1',
            'phone' => '123',
            'is_active' => true,
        ]);

        $start = now()->addDays(3)->setTime(12, 0);

        StaffSchedule::create([
            'staff_profile_id' => $staffProfile->id,
            'schedule_date' => $start->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'is_day_off' => false,
        ]);

        $service = SalonService::create([
            'name' => 'Embed Color',
            'duration_minutes' => 60,
            'buffer_minutes' => 10,
            'price' => 140,
            'is_active' => true,
        ]);

        $this->followingRedirects()->post(route('embed.booking.store'), [
            'customer_name' => 'Embed Invalid Alert',
            'customer_phone' => '5558811111',
            'customer_email' => 'invalid-alert@example.com',
            'service_id' => $service->id,
            'scheduled_start' => $start->toDateTimeString(),
        ])
            ->assertOk()
            ->assertSee('Thank you', false);

        $appointment = Appointment::query()->where('customer_phone', '5558811111')->latest()->first();
        $this->assertNotNull($appointment);

        $this->assertDatabaseHas('communication_logs', [
            'channel' => 'whatsapp',
            'context' => 'public_booking_team_alert',
            'status' => 'failed',
            'provider_status' => 'invalid-recipient',
        ]);

        $this->assertSame(1, CommunicationLog::query()->where('context', 'public_booking_team_alert')->count());
    }

    public function test_embed_booking_does_not_assign_non_assignable_roles(): void
    {
        $staffRole = Role::firstOrCreate(['name' => 'staff'], ['label' => 'Staff']);
        $receptionRole = Role::firstOrCreate(['name' => 'reception'], ['label' => 'Reception']);

        $removedUser = User::factory()->create(['role_id' => $receptionRole->id, 'name' => 'Jenifer Palisoc Jazmin']);
        $removedStaff = StaffProfile::create([
            'user_id' => $removedUser->id,
            'employee_code' => 'VINA-08',
            'is_active' => true,
        ]);

        $activeUser = User::factory()->create(['role_id' => $staffRole->id, 'name' => 'Majd Alabaza']);
        $activeStaff = StaffProfile::create([
            'user_id' => $activeUser->id,
            'employee_code' => 'VINA-03',
            'is_active' => true,
        ]);

        $start = now()->addDays(3)->setTime(11, 0);

        foreach ([$removedStaff, $activeStaff] as $staff) {
            StaffSchedule::create([
                'staff_profile_id' => $staff->id,
                'schedule_date' => $start->toDateString(),
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'is_day_off' => false,
            ]);
        }

        $service = SalonService::create([
            'name' => 'Embed Cut',
            'duration_minutes' => 60,
            'buffer_minutes' => 10,
            'price' => 100,
            'is_active' => true,
        ]);

        $this->followingRedirects()->post(route('embed.booking.store'), [
            'customer_name' => 'Embed User',
            'customer_phone' => '5558889998',
            'customer_email' => 'embed-skip@example.com',
            'service_id' => $service->id,
            'scheduled_start' => $start->toDateTimeString(),
        ])->assertOk();

        $appointment = Appointment::query()->where('customer_phone', '5558889998')->latest()->first();
        $this->assertNotNull($appointment);
        $this->assertSame($activeStaff->id, $appointment->staff_profile_id);
    }

    public function test_embed_booking_rejects_non_assignable_staff_id(): void
    {
        $staffRole = Role::firstOrCreate(['name' => 'staff'], ['label' => 'Staff']);
        $receptionRole = Role::firstOrCreate(['name' => 'reception'], ['label' => 'Reception']);

        $removedUser = User::factory()->create(['role_id' => $receptionRole->id, 'name' => 'Analisa Rabanal Domenden']);
        $removedStaff = StaffProfile::create([
            'user_id' => $removedUser->id,
            'employee_code' => 'VINA-07',
            'is_active' => true,
        ]);

        $start = now()->addDays(3)->setTime(11, 0);

        StaffSchedule::create([
            'staff_profile_id' => $removedStaff->id,
            'schedule_date' => $start->toDateString(),
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'is_day_off' => false,
        ]);

        $service = SalonService::create([
            'name' => 'Embed Color',
            'duration_minutes' => 60,
            'buffer_minutes' => 10,
            'price' => 140,
            'is_active' => true,
        ]);

        $this->from(route('embed.booking'))
            ->post(route('embed.booking.store'), [
                'customer_name' => 'Embed Rejected',
                'customer_phone' => '5558877777',
                'customer_email' => 'embed-rejected@example.com',
                'service_id' => $service->id,
                'staff_profile_id' => $removedStaff->id,
                'scheduled_start' => $start->toDateTimeString(),
            ])
            ->assertRedirect(route('embed.booking'))
            ->assertSessionHasErrors('staff_profile_id');

        $this->assertDatabaseMissing('appointments', [
            'customer_phone' => '5558877777',
        ]);
    }
}
