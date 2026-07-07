<?php

namespace Tests\Feature\Auth;

use App\Models\AttendanceLog;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_staff_logout_records_clock_out_when_missing(): void
    {
        $staffRole = Role::create([
            'name' => 'staff',
            'label' => 'Staff',
            'permissions' => [],
        ]);

        $user = User::factory()->create([
            'role_id' => $staffRole->id,
        ]);

        $profile = StaffProfile::create([
            'user_id' => $user->id,
            'employee_code' => 'EMP-LOG-01',
            'is_active' => true,
        ]);

        $log = AttendanceLog::create([
            'staff_profile_id' => $profile->id,
            'attendance_date' => now()->toDateString(),
            'clock_in' => '09:00:00',
            'clock_out' => null,
            'late_minutes' => 0,
        ]);

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
        $this->assertNotNull($log->fresh()->clock_out);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'auth.logout',
            'entity_type' => 'User',
            'entity_id' => $user->id,
        ]);
    }

    public function test_logout_records_clock_out_for_user_with_staff_profile(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => [],
        ]);

        $user = User::factory()->create([
            'role_id' => $managerRole->id,
        ]);

        $profile = StaffProfile::create([
            'user_id' => $user->id,
            'employee_code' => 'EMP-LOG-02',
            'is_active' => true,
        ]);

        $log = AttendanceLog::create([
            'staff_profile_id' => $profile->id,
            'attendance_date' => '2026-05-15',
            'clock_in' => '09:00:00',
            'clock_out' => null,
            'late_minutes' => 0,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-15 18:30:00'));

        try {
            $response = $this->actingAs($user)->post('/logout');

            $this->assertGuest();
            $response->assertRedirect('/');
            $this->assertSame('18:30:00', $log->fresh()->clock_out);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_logout_does_not_close_open_attendance_from_a_previous_day(): void
    {
        $staffRole = Role::create([
            'name' => 'staff',
            'label' => 'Staff',
            'permissions' => [],
        ]);

        $user = User::factory()->create([
            'role_id' => $staffRole->id,
        ]);

        $profile = StaffProfile::create([
            'user_id' => $user->id,
            'employee_code' => 'EMP-LOG-03',
            'is_active' => true,
        ]);

        $log = AttendanceLog::create([
            'staff_profile_id' => $profile->id,
            'attendance_date' => '2026-05-14',
            'clock_in' => '09:00:00',
            'clock_out' => null,
            'late_minutes' => 0,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-05-15 18:30:00'));

        try {
            $response = $this->actingAs($user)->post('/logout');

            $this->assertGuest();
            $response->assertRedirect('/');
            $this->assertNull($log->fresh()->clock_out);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_user_is_locked_out_for_one_hour_after_four_failed_login_attempts(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 4) as $attempt) {
            $response = $this->from('/login')->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);

            $response->assertRedirect('/login');
        }

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('email');

        $key = strtolower($user->email).'|127.0.0.1';
        $this->assertGreaterThanOrEqual(3590, RateLimiter::availableIn($key));
    }

    public function test_login_requires_valid_recaptcha_when_configured(): void
    {
        config()->set('services.recaptcha.site_key', 'test-site-key');
        config()->set('services.recaptcha.secret_key', 'test-secret-key');
        Http::fake([
            '*' => Http::response(['success' => false], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'g-recaptcha-response' => 'bad-token',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('captcha');
        $this->assertGuest();
    }

    public function test_staff_users_can_access_the_appointments_screen(): void
    {
        $staffRole = Role::create([
            'name' => 'staff',
            'label' => 'Staff',
            'permissions' => [],
        ]);

        $user = User::factory()->create([
            'role_id' => $staffRole->id,
        ]);

        $response = $this->actingAs($user)->get('/appointments');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Appointments/Index')
            ->where('auth.user.role.name', 'staff')
        );
    }
}
