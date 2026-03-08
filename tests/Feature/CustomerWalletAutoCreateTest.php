<?php

namespace Tests\Feature;

use App\Models\CustomerLoyaltyAccount;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerWalletAutoCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_is_auto_created_when_customer_is_created(): void
    {
        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
        ]);

        $user = User::factory()->create([
            'role_id' => $ownerRole->id,
        ]);

        $this->actingAs($user)
            ->post(route('customers.store'), [
                'name' => 'Wallet Test',
                'phone' => '5558881212',
                'email' => 'wallet@example.com',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'phone' => '5558881212',
        ]);

        $customerId = (int) \App\Models\Customer::query()->where('phone', '5558881212')->value('id');
        $wallet = CustomerLoyaltyAccount::query()->where('customer_id', $customerId)->first();

        $this->assertNotNull($wallet);
        $this->assertSame(0, $wallet->current_points);
    }
}

