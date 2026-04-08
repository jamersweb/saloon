<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMembershipCard;
use App\Models\MembershipCardType;
use App\Models\Role;
use App\Models\ServicePackage;
use App\Models\User;
use App\Services\GiftCardService;
use App\Services\PackageBalanceService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PackagesAndGiftCardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigning_and_consuming_package_updates_remaining_balances(): void
    {
        $customer = Customer::create([
            'customer_code' => 'CUST-PACK-001',
            'name' => 'Package Customer',
            'phone' => '5558181818',
            'is_active' => true,
        ]);

        $package = ServicePackage::create([
            'name' => 'Color Bundle',
            'usage_limit' => 5,
            'initial_value' => 300,
            'validity_days' => 90,
            'is_active' => true,
        ]);

        $assigned = app(PackageBalanceService::class)->assignPackage($customer, $package);

        $usage = app(PackageBalanceService::class)->consume($assigned, 2, 120.00, null, 'Applied to color refresh');

        $assigned->refresh();

        $this->assertSame(3, $assigned->remaining_sessions);
        $this->assertSame('180.00', $assigned->remaining_value);
        $this->assertSame(2, $usage->sessions_used);
        $this->assertSame('120.00', $usage->value_used);
    }

    public function test_gift_card_consumption_records_transaction_and_blocks_overspend(): void
    {
        $giftCard = app(GiftCardService::class)->issue(null, 200.00);

        $transaction = app(GiftCardService::class)->consume($giftCard, 75.00, 'Salon service');

        $giftCard->refresh();

        $this->assertSame('125.00', $giftCard->remaining_value);
        $this->assertSame('-75.00', $transaction->amount_change);

        $this->expectException(ValidationException::class);
        app(GiftCardService::class)->consume($giftCard, 500.00, 'Overspend attempt');
    }

    public function test_staff_can_issue_gift_card_and_assign_package_via_loyalty_routes(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $customer = Customer::create([
            'customer_code' => 'CUST-PACK-ROUTE',
            'name' => 'Route Customer',
            'phone' => '5557171717',
            'is_active' => true,
        ]);

        $package = ServicePackage::create([
            'name' => 'Spa Sessions',
            'usage_limit' => 4,
            'initial_value' => null,
            'validity_days' => 30,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('loyalty.packages.assign'), [
                'customer_id' => $customer->id,
                'service_package_id' => $package->id,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('loyalty.gift-cards.store'), [
                'assigned_customer_id' => $customer->id,
                'initial_value' => 150,
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customer_packages', [
            'customer_id' => $customer->id,
            'service_package_id' => $package->id,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('gift_cards', [
            'assigned_customer_id' => $customer->id,
            'initial_value' => 150,
            'remaining_value' => 150,
        ]);
    }

    public function test_gift_card_can_be_issued_with_nfc_uid_and_looked_up(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $this->actingAs($user)
            ->post(route('loyalty.gift-cards.store'), [
                'initial_value' => 300,
                'nfc_uid' => '  abcd12  ',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('gift_cards', [
            'nfc_uid' => 'ABCD12',
            'initial_value' => 300,
        ]);

        $this->actingAs($user)
            ->post(route('loyalty.gift-cards.nfc-lookup'), [
                'gift_nfc_uid' => 'ABCD12',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('gift_nfc_lookup.code');
    }

    public function test_gift_card_nfc_bind_rejects_uid_linked_to_membership_card(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $customer = Customer::create([
            'customer_code' => 'CUST-GIFT-NFC',
            'name' => 'Gift NFC Customer',
            'phone' => '5556060606',
            'is_active' => true,
        ]);

        $giftCard = app(GiftCardService::class)->issue(null, 100.00);

        $cardType = MembershipCardType::create([
            'name' => 'Test Card',
            'slug' => 'test-card',
            'kind' => 'physical',
            'min_points' => 0,
            'is_active' => true,
        ]);

        CustomerMembershipCard::create([
            'customer_id' => $customer->id,
            'membership_card_type_id' => $cardType->id,
            'card_number' => 'MEM-1',
            'nfc_uid' => 'SHAREDUID',
            'status' => 'active',
            'issued_at' => now(),
            'activated_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('loyalty.gift-cards.nfc-bind'), [
                'gift_card_id' => $giftCard->id,
                'nfc_uid' => 'SHAREDUID',
            ])
            ->assertSessionHasErrors('nfc_uid');
    }
}
