<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\User;
use App\Services\LoyaltyRedemptionRulesService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoyaltyRedemptionRulesTest extends TestCase
{
    use RefreshDatabase;

    private function managerUser(): User
    {
        $role = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_redeem_respects_max_units_per_redemption(): void
    {
        $user = $this->managerUser();
        $customer = Customer::create([
            'customer_code' => 'CUST-R1',
            'name' => 'R One',
            'phone' => '5551112222',
            'is_active' => true,
        ]);
        $customer->loyaltyAccount()->update(['current_points' => 500]);
        $reward = LoyaltyReward::create([
            'name' => 'Micro',
            'points_cost' => 100,
            'max_units_per_redemption' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('loyalty.redeem'), [
                'customer_id' => $customer->id,
                'loyalty_reward_id' => $reward->id,
                'quantity' => 2,
            ])
            ->assertSessionHasErrors('quantity');
    }

    public function test_redeem_respects_monthly_cap(): void
    {
        $user = $this->managerUser();
        $customer = Customer::create([
            'customer_code' => 'CUST-R2',
            'name' => 'R Two',
            'phone' => '5551113333',
            'is_active' => true,
        ]);
        $customer->loyaltyAccount()->update(['current_points' => 500]);
        $reward = LoyaltyReward::create([
            'name' => 'Monthly cap',
            'points_cost' => 50,
            'max_redemptions_per_calendar_month' => 2,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('loyalty.redeem'), [
                'customer_id' => $customer->id,
                'loyalty_reward_id' => $reward->id,
                'quantity' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('loyalty.redeem'), [
                'customer_id' => $customer->id,
                'loyalty_reward_id' => $reward->id,
                'quantity' => 1,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('loyalty.redeem'), [
                'customer_id' => $customer->id,
                'loyalty_reward_id' => $reward->id,
                'quantity' => 1,
            ])
            ->assertSessionHasErrors('quantity');
    }

    public function test_redeem_respects_min_days_between(): void
    {
        $customer = Customer::create([
            'customer_code' => 'CUST-R3',
            'name' => 'R Three',
            'phone' => '5551114444',
            'is_active' => true,
        ]);
        $reward = LoyaltyReward::create([
            'name' => 'Cooldown',
            'points_cost' => 10,
            'min_days_between_redemptions' => 7,
            'is_active' => true,
        ]);

        $redemption = LoyaltyRedemption::create([
            'customer_id' => $customer->id,
            'loyalty_reward_id' => $reward->id,
            'points_spent' => 10,
            'quantity' => 1,
            'status' => 'redeemed',
        ]);
        $redemption->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();

        $this->expectException(ValidationException::class);
        app(LoyaltyRedemptionRulesService::class)->assertCanRedeem(
            $customer->id,
            $reward->fresh(),
            1,
            null,
        );
    }

    public function test_redeem_requires_appointment_and_blocks_duplicate_per_visit(): void
    {
        $user = $this->managerUser();
        $customer = Customer::create([
            'customer_code' => 'CUST-R4',
            'name' => 'R Four',
            'phone' => '5551115555',
            'is_active' => true,
        ]);
        $customer->loyaltyAccount()->update(['current_points' => 500]);
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
            'source' => 'admin',
            'status' => Appointment::STATUS_COMPLETED,
            'scheduled_start' => now()->subDay(),
            'scheduled_end' => now()->subDay()->addHour(),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'customer_email' => $customer->email,
        ]);
        $reward = LoyaltyReward::create([
            'name' => 'Visit reward',
            'points_cost' => 100,
            'requires_appointment_id' => true,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('loyalty.redeem'), [
                'customer_id' => $customer->id,
                'loyalty_reward_id' => $reward->id,
                'quantity' => 1,
            ])
            ->assertSessionHasErrors('appointment_id');

        $this->actingAs($user)
            ->post(route('loyalty.redeem'), [
                'customer_id' => $customer->id,
                'loyalty_reward_id' => $reward->id,
                'quantity' => 1,
                'appointment_id' => $appointment->id,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('loyalty.redeem'), [
                'customer_id' => $customer->id,
                'loyalty_reward_id' => $reward->id,
                'quantity' => 1,
                'appointment_id' => $appointment->id,
            ])
            ->assertSessionHasErrors('appointment_id');
    }

    public function test_redeem_with_service_allowlist_requires_matching_visit(): void
    {
        $user = $this->managerUser();
        $customer = Customer::create([
            'customer_code' => 'CUST-R5',
            'name' => 'R Five',
            'phone' => '5551116666',
            'is_active' => true,
        ]);
        $customer->loyaltyAccount()->update(['current_points' => 500]);

        $eligible = SalonService::create([
            'name' => 'Eligible Micro',
            'category' => 'Hair',
            'duration_minutes' => 15,
            'buffer_minutes' => 0,
            'price' => 40,
            'is_active' => true,
        ]);
        $other = SalonService::create([
            'name' => 'Other Service',
            'category' => 'Hair',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'price' => 120,
            'is_active' => true,
        ]);

        $appointmentWrong = Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $other->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_COMPLETED,
            'scheduled_start' => now()->subDay(),
            'scheduled_end' => now()->subDay()->addHour(),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'customer_email' => $customer->email,
        ]);

        $appointmentOk = Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $eligible->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_COMPLETED,
            'scheduled_start' => now()->subHours(3),
            'scheduled_end' => now()->subHours(2),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'customer_email' => $customer->email,
        ]);

        $reward = LoyaltyReward::create([
            'name' => 'Micro reward',
            'points_cost' => 100,
            'requires_appointment_id' => false,
            'is_active' => true,
        ]);
        $reward->allowedSalonServices()->sync([$eligible->id]);

        $this->actingAs($user)
            ->post(route('loyalty.redeem'), [
                'customer_id' => $customer->id,
                'loyalty_reward_id' => $reward->id,
                'quantity' => 1,
                'appointment_id' => $appointmentWrong->id,
            ])
            ->assertSessionHasErrors('appointment_id');

        $this->actingAs($user)
            ->post(route('loyalty.redeem'), [
                'customer_id' => $customer->id,
                'loyalty_reward_id' => $reward->id,
                'quantity' => 1,
                'appointment_id' => $appointmentOk->id,
            ])
            ->assertSessionHasNoErrors();
    }
}
