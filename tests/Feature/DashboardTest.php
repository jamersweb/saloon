<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\Permissions;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_dashboard_shows_assigned_upcoming_appointments_beyond_today(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 10:00:00'));

        $staffRole = Role::create([
            'name' => 'staff',
            'label' => 'Staff',
            'permissions' => Permissions::defaultsForRole('staff'),
        ]);

        $staffUser = User::factory()->create([
            'role_id' => $staffRole->id,
            'name' => 'Assigned Staff',
        ]);

        $staffProfile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'STAFF-DASH-01',
            'is_active' => true,
        ]);

        $service = SalonService::create([
            'name' => 'Dashboard Service',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'price' => 100,
            'is_active' => true,
        ]);

        $appointment = Appointment::create([
            'customer_name' => 'Dashboard Client',
            'customer_phone' => '5551234567',
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'scheduled_start' => Carbon::parse('2026-07-02 14:00:00'),
            'scheduled_end' => Carbon::parse('2026-07-02 14:45:00'),
            'source' => 'admin',
            'status' => Appointment::STATUS_CONFIRMED,
        ]);

        $this->actingAs($staffUser)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('upcomingAppointments.0.id', $appointment->id)
                ->where('upcomingAppointments.0.customer_name', 'Dashboard Client')
                ->where('upcomingAppointments.0.staff_name', 'Assigned Staff')
            );
    }
}
