<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\AppointmentPhoto;
use App\Models\Customer;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppointmentDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_permanently_delete_appointment(): void
    {
        [$user, $staffProfile] = $this->createStaffUser();
        $service = SalonService::create([
            'name' => 'Cut',
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'price' => 50,
            'is_active' => true,
        ]);
        $customer = Customer::create([
            'name' => 'Del Customer',
            'phone' => '0500000999',
            'email' => null,
            'is_active' => true,
        ]);
        $appointment = Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_PENDING,
            'scheduled_start' => now()->addDay(),
            'scheduled_end' => now()->addDay()->addMinutes(30),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'customer_email' => null,
        ]);

        $this->actingAs($user)
            ->delete(route('appointments.destroy', $appointment))
            ->assertRedirect();

        $this->assertDatabaseMissing('appointments', ['id' => $appointment->id]);
    }

    public function test_delete_removes_appointment_photo_files(): void
    {
        Storage::fake('public');

        [$user, $staffProfile] = $this->createStaffUser();
        $service = SalonService::create([
            'name' => 'Cut',
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'price' => 50,
            'is_active' => true,
        ]);
        $customer = Customer::create([
            'name' => 'Photo Customer',
            'phone' => '0500000888',
            'email' => null,
            'is_active' => true,
        ]);
        $appointment = Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_CONFIRMED,
            'scheduled_start' => now()->addDay(),
            'scheduled_end' => now()->addDay()->addMinutes(30),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'customer_email' => null,
        ]);

        $path = 'appointment-photos/test-before.jpg';
        Storage::disk('public')->put($path, 'fake');
        AppointmentPhoto::create([
            'appointment_id' => $appointment->id,
            'type' => 'before',
            'path' => $path,
            'uploaded_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->delete(route('appointments.destroy', $appointment))
            ->assertRedirect();

        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseMissing('appointments', ['id' => $appointment->id]);
    }

    /**
     * @return array{0: User, 1: StaffProfile}
     */
    private function createStaffUser(): array
    {
        $role = Role::create([
            'name' => 'staff',
            'label' => 'Staff',
            'permissions' => Permissions::defaultsForRole('staff'),
        ]);
        $user = User::factory()->create(['role_id' => $role->id]);
        $staffProfile = StaffProfile::create([
            'user_id' => $user->id,
            'employee_code' => 'STF-DEL-01',
            'is_active' => true,
        ]);

        return [$user, $staffProfile];
    }
}
