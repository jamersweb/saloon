<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\CustomerLoyaltyAccount;
use App\Models\CustomerLoyaltyLedger;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyAutoEarnTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_appointment_awards_loyalty_points(): void
    {
        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
        ]);

        $user = User::factory()->create([
            'role_id' => $ownerRole->id,
        ]);

        $customer = Customer::create([
            'name' => 'Nadia Customer',
            'phone' => '5550001111',
            'email' => 'nadia@example.com',
            'customer_code' => 'CUST-T001',
        ]);

        $service = SalonService::create([
            'name' => 'Hair Cut',
            'category' => 'Hair',
            'duration_minutes' => 60,
            'buffer_minutes' => 10,
            'price' => 120.00,
            'is_active' => true,
        ]);

        $appointment = Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'booked_by' => $user->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_IN_PROGRESS,
            'scheduled_start' => now()->subHours(2),
            'scheduled_end' => now()->subHour(),
            'arrival_time' => now()->subHours(2),
            'service_start_time' => now()->subHours(2),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'customer_email' => $customer->email,
        ]);

        $this->actingAs($user)
            ->patch(route('appointments.transition', $appointment), [
                'status' => Appointment::STATUS_COMPLETED,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customer_loyalty_ledgers', [
            'customer_id' => $customer->id,
            'reason' => 'Appointment completed',
            'reference' => 'APPOINTMENT-' . $appointment->id,
            'points_change' => 120,
            'balance_after' => 120,
        ]);

        $account = CustomerLoyaltyAccount::query()->where('customer_id', $customer->id)->first();

        $this->assertNotNull($account);
        $this->assertSame(120, $account->current_points);
    }

    public function test_auto_earn_is_idempotent_for_same_appointment(): void
    {
        $customer = Customer::create([
            'name' => 'Amina Customer',
            'phone' => '5550002222',
            'email' => 'amina@example.com',
            'customer_code' => 'CUST-T002',
        ]);

        $service = SalonService::create([
            'name' => 'Color',
            'category' => 'Hair',
            'duration_minutes' => 90,
            'buffer_minutes' => 10,
            'price' => 200.00,
            'is_active' => true,
        ]);

        $appointment = Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_COMPLETED,
            'scheduled_start' => now()->subHours(3),
            'scheduled_end' => now()->subHours(2),
            'service_start_time' => now()->subHours(3),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'customer_email' => $customer->email,
        ]);

        $service = app(LoyaltyService::class);
        $service->earnFromCompletedAppointment($appointment);
        $service->earnFromCompletedAppointment($appointment);

        $entries = CustomerLoyaltyLedger::query()
            ->where('reference', 'APPOINTMENT-' . $appointment->id)
            ->where('reason', 'Appointment completed')
            ->get();

        $this->assertCount(1, $entries);

        $account = CustomerLoyaltyAccount::query()->where('customer_id', $customer->id)->first();
        $this->assertNotNull($account);
        $this->assertSame(200, $account->current_points);
    }
}

