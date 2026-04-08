<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\GiftCardService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyGiftCardEarnTest extends TestCase
{
    use RefreshDatabase;

    private function staffWithProfile(): array
    {
        $role = Role::create([
            'name' => 'staff',
            'label' => 'Staff',
            'permissions' => Permissions::defaultsForRole('staff'),
        ]);
        $user = User::factory()->create(['role_id' => $role->id]);
        $staffProfile = StaffProfile::create([
            'user_id' => $user->id,
            'employee_code' => 'STF-GC-01',
            'is_active' => true,
        ]);

        return [$user, $staffProfile];
    }

    public function test_completing_visit_with_exclude_loyalty_earn_skips_auto_points(): void
    {
        [$user, $staffProfile] = $this->staffWithProfile();

        $customer = Customer::create([
            'customer_code' => 'CUST-GC-1',
            'name' => 'Gift Card Customer A',
            'phone' => '5557771001',
            'is_active' => true,
        ]);

        $service = SalonService::create([
            'name' => 'Styling',
            'category' => 'Hair',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'price' => 150,
            'is_active' => true,
        ]);

        $appointment = Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_IN_PROGRESS,
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now(),
            'arrival_time' => now()->subHour(),
            'service_start_time' => now()->subHour(),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'customer_email' => $customer->email,
        ]);

        $this->actingAs($user)
            ->post(route('appointments.service-complete', $appointment), [
                'service_report' => 'Completed',
                'exclude_loyalty_earn' => true,
                'products' => [],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('customer_loyalty_ledgers', [
            'customer_id' => $customer->id,
            'reference' => 'APPOINTMENT-'.$appointment->id,
        ]);

        $appointment->refresh();
        $this->assertTrue($appointment->exclude_loyalty_earn);
    }

    public function test_gift_card_charge_linked_to_visit_blocks_auto_points_on_completion(): void
    {
        [$user, $staffProfile] = $this->staffWithProfile();

        $customer = Customer::create([
            'customer_code' => 'CUST-GC-2',
            'name' => 'Gift Card Customer B',
            'phone' => '5557771002',
            'is_active' => true,
        ]);

        $service = SalonService::create([
            'name' => 'Cut',
            'category' => 'Hair',
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'price' => 80,
            'is_active' => true,
        ]);

        $appointment = Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_IN_PROGRESS,
            'scheduled_start' => now()->subHour(),
            'scheduled_end' => now(),
            'arrival_time' => now()->subHour(),
            'service_start_time' => now()->subHour(),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'customer_email' => $customer->email,
        ]);

        $giftCard = app(GiftCardService::class)->issue($customer, 200.00);
        app(GiftCardService::class)->consume($giftCard, 50.00, 'Visit payment', null, null, $appointment->id);

        $this->actingAs($user)
            ->post(route('appointments.service-complete', $appointment), [
                'service_report' => 'Done',
                'products' => [],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('customer_loyalty_ledgers', [
            'customer_id' => $customer->id,
            'reference' => 'APPOINTMENT-'.$appointment->id,
        ]);
    }
}
