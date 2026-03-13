<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\CustomerDueService;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DueServiceAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_appointment_transition_creates_due_service_record(): void
    {
        $ownerRole = Role::create(['name' => 'owner', 'label' => 'Owner']);
        $user = User::factory()->create(['role_id' => $ownerRole->id]);

        $customer = Customer::create([
            'customer_code' => 'CUST-DUE-001',
            'name' => 'Reminder Customer',
            'phone' => '5551100110',
            'is_active' => true,
        ]);

        $service = SalonService::create([
            'name' => 'Hair Refresh',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'repeat_after_days' => 30,
            'price' => 150,
            'is_active' => true,
        ]);

        $appointment = Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_IN_PROGRESS,
            'scheduled_start' => now()->subHours(2),
            'scheduled_end' => now()->subHour(),
            'arrival_time' => now()->subHours(2),
            'service_start_time' => now()->subHours(2),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
        ]);

        $this->actingAs($user)
            ->patch(route('appointments.transition', $appointment), [
                'status' => Appointment::STATUS_COMPLETED,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customer_due_services', [
            'customer_id' => $customer->id,
            'salon_service_id' => $service->id,
            'last_appointment_id' => $appointment->id,
            'status' => 'pending',
        ]);
    }

    public function test_manual_due_service_generation_processes_full_completed_history(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $service = SalonService::create([
            'name' => 'Nail Maintenance',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'repeat_after_days' => 21,
            'price' => 90,
            'is_active' => true,
        ]);

        for ($i = 1; $i <= 810; $i++) {
            $customer = Customer::create([
                'customer_code' => 'CUST-BF-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'name' => 'Backfill Customer ' . $i,
                'phone' => '7000' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'is_active' => true,
            ]);

            Appointment::create([
                'customer_id' => $customer->id,
                'service_id' => $service->id,
                'source' => 'admin',
                'status' => Appointment::STATUS_COMPLETED,
                'scheduled_start' => now()->subDays($i + 1),
                'scheduled_end' => now()->subDays($i + 1)->addMinutes(45),
                'service_start_time' => now()->subDays($i + 1),
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
            ]);
        }

        $this->actingAs($user)
            ->post(route('customers.automation.due-services.generate'))
            ->assertSessionHasNoErrors();

        $this->assertSame(810, CustomerDueService::query()->count());
    }
}
