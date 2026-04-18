<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMembershipCard;
use App\Models\MembershipCardType;
use App\Models\Role;
use App\Models\User;
use App\Services\MembershipCardService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MembershipCardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigning_new_membership_card_deactivates_previous_active_card(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $customer = Customer::create([
            'customer_code' => 'CUST-CARD-001',
            'name' => 'Card Customer',
            'phone' => '5559191919',
            'is_active' => true,
        ]);

        $silver = MembershipCardType::create([
            'name' => 'Silver',
            'slug' => 'silver',
            'kind' => 'physical',
            'min_points' => 100,
            'is_active' => true,
        ]);

        $gold = MembershipCardType::create([
            'name' => 'Gold',
            'slug' => 'gold',
            'kind' => 'physical',
            'min_points' => 250,
            'is_active' => true,
        ]);

        CustomerMembershipCard::create([
            'customer_id' => $customer->id,
            'membership_card_type_id' => $silver->id,
            'card_number' => '555055000001',
            'status' => 'active',
            'issued_at' => now()->subMonth(),
            'activated_at' => now()->subMonth(),
        ]);

        $this->actingAs($user)
            ->post(route('loyalty.cards.assign'), [
                'customer_id' => $customer->id,
                'membership_card_type_id' => $gold->id,
                'card_number' => '555055000002',
                'status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customer_membership_cards', [
            'customer_id' => $customer->id,
            'membership_card_type_id' => $silver->id,
            'status' => 'inactive',
        ]);

        $this->assertDatabaseHas('customer_membership_cards', [
            'customer_id' => $customer->id,
            'membership_card_type_id' => $gold->id,
            'status' => 'active',
        ]);
    }

    public function test_membership_card_service_returns_highest_eligible_non_gift_card(): void
    {
        MembershipCardType::create([
            'name' => 'Normal',
            'slug' => 'normal',
            'kind' => 'physical',
            'min_points' => 0,
            'is_active' => true,
        ]);

        MembershipCardType::create([
            'name' => 'Silver',
            'slug' => 'silver',
            'kind' => 'physical',
            'min_points' => 100,
            'is_active' => true,
        ]);

        MembershipCardType::create([
            'name' => 'Gift',
            'slug' => 'gift',
            'kind' => 'gift',
            'min_points' => 1000,
            'is_active' => true,
        ]);

        MembershipCardType::create([
            'name' => 'Gold',
            'slug' => 'gold',
            'kind' => 'virtual',
            'min_points' => 250,
            'is_active' => true,
        ]);

        $eligible = app(MembershipCardService::class)->eligibleTypeForPoints(300);

        $this->assertNotNull($eligible);
        $this->assertSame('Gold', $eligible->name);
    }

    public function test_staff_can_lookup_card_by_nfc_uid(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $customer = Customer::create([
            'customer_code' => 'CUST-NFC-001',
            'name' => 'Scan Customer',
            'phone' => '5551212121',
            'email' => 'scan@example.com',
            'is_active' => true,
        ]);

        $cardType = MembershipCardType::create([
            'name' => 'Platinum',
            'slug' => 'platinum',
            'kind' => 'physical',
            'min_points' => 400,
            'is_active' => true,
        ]);

        CustomerMembershipCard::create([
            'customer_id' => $customer->id,
            'membership_card_type_id' => $cardType->id,
            'card_number' => '555055000003',
            'nfc_uid' => '04AB77CD',
            'status' => 'active',
            'issued_at' => now()->subDay(),
            'activated_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->post(route('loyalty.cards.nfc-lookup'), [
                'nfc_uid' => '04ab77cd',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('nfc_lookup.customer_name', 'Scan Customer')
            ->assertSessionHas('nfc_lookup.card_number', '555055000003');
    }

    public function test_membership_nfc_lookup_normalizes_uid_case(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $customer = Customer::create([
            'customer_code' => 'CUST-NFC-CASE',
            'name' => 'Case Customer',
            'phone' => '5551313131',
            'is_active' => true,
        ]);

        $cardType = MembershipCardType::create([
            'name' => 'Case Tier',
            'slug' => 'case-tier',
            'kind' => 'physical',
            'min_points' => 0,
            'is_active' => true,
        ]);

        CustomerMembershipCard::create([
            'customer_id' => $customer->id,
            'membership_card_type_id' => $cardType->id,
            'card_number' => '555099000001',
            'nfc_uid' => 'AABBCCDD',
            'status' => 'active',
            'issued_at' => now()->subDay(),
            'activated_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->post(route('loyalty.cards.nfc-lookup'), [
                'nfc_uid' => '  aabbccdd  ',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('nfc_lookup.card_number', '555099000001');
    }

    public function test_binding_nfc_uid_can_replace_existing_link_when_requested(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $cardType = MembershipCardType::create([
            'name' => 'Silver',
            'slug' => 'silver',
            'kind' => 'physical',
            'min_points' => 100,
            'is_active' => true,
        ]);

        $firstCustomer = Customer::create([
            'customer_code' => 'CUST-NFC-002',
            'name' => 'First Customer',
            'phone' => '5555656565',
            'is_active' => true,
        ]);

        $secondCustomer = Customer::create([
            'customer_code' => 'CUST-NFC-003',
            'name' => 'Second Customer',
            'phone' => '5557878787',
            'is_active' => true,
        ]);

        $firstCard = CustomerMembershipCard::create([
            'customer_id' => $firstCustomer->id,
            'membership_card_type_id' => $cardType->id,
            'card_number' => '555055000004',
            'nfc_uid' => '0422AA11',
            'status' => 'active',
            'issued_at' => now()->subWeek(),
            'activated_at' => now()->subWeek(),
        ]);

        $secondCard = CustomerMembershipCard::create([
            'customer_id' => $secondCustomer->id,
            'membership_card_type_id' => $cardType->id,
            'card_number' => '555055000005',
            'status' => 'active',
            'issued_at' => now()->subWeek(),
            'activated_at' => now()->subWeek(),
        ]);

        $this->actingAs($user)
            ->post(route('loyalty.cards.nfc-bind'), [
                'customer_membership_card_id' => $secondCard->id,
                'nfc_uid' => '0422AA11',
                'replace_existing' => true,
            ])
            ->assertSessionHasNoErrors();

        $firstCard->refresh();
        $secondCard->refresh();

        $this->assertNull($firstCard->nfc_uid);
        $this->assertSame('0422AA11', $secondCard->nfc_uid);
    }

    public function test_pre_issue_inventory_card_has_no_customer_and_numeric_card_number(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $cardType = MembershipCardType::create([
            'name' => 'Bronze',
            'slug' => 'bronze',
            'kind' => 'physical',
            'min_points' => 0,
            'validity_days' => 365,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('loyalty.cards.issue-inventory'), [
                'membership_card_type_id' => $cardType->id,
                'status' => 'pending',
            ])
            ->assertSessionHasNoErrors();

        $row = CustomerMembershipCard::query()->where('membership_card_type_id', $cardType->id)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->customer_id);
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $row->card_number);
        $this->assertSame('100000000001', (string) $row->card_number);
        $this->assertSame('pending', $row->status);
    }

    public function test_consecutive_inventory_issues_increment_sequence_per_card_type(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $cardType = MembershipCardType::create([
            'name' => 'Seq Test',
            'slug' => 'seq-test',
            'kind' => 'physical',
            'min_points' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('loyalty.cards.issue-inventory'), [
                'membership_card_type_id' => $cardType->id,
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post(route('loyalty.cards.issue-inventory'), [
                'membership_card_type_id' => $cardType->id,
            ])
            ->assertSessionHasNoErrors();

        $numbers = CustomerMembershipCard::query()
            ->where('membership_card_type_id', $cardType->id)
            ->orderBy('id')
            ->pluck('card_number')
            ->all();

        $this->assertSame(['100000000001', '100000000002'], $numbers);
    }

    public function test_link_inventory_card_assigns_customer_and_sets_validity(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $customer = Customer::create([
            'customer_code' => 'CUST-LINK-001',
            'name' => 'Link Customer',
            'phone' => '5550001111',
            'is_active' => true,
        ]);

        $cardType = MembershipCardType::create([
            'name' => 'Bronze',
            'slug' => 'bronze-link',
            'kind' => 'physical',
            'min_points' => 0,
            'validity_days' => 30,
            'is_active' => true,
        ]);

        $inventory = CustomerMembershipCard::create([
            'customer_id' => null,
            'membership_card_type_id' => $cardType->id,
            'card_number' => '777066000099',
            'status' => 'pending',
            'issued_at' => now()->subDay(),
        ]);

        $this->actingAs($user)
            ->post(route('loyalty.cards.link-customer'), [
                'customer_id' => $customer->id,
                'customer_membership_card_id' => $inventory->id,
            ])
            ->assertSessionHasNoErrors();

        $inventory->refresh();
        $this->assertSame($customer->id, $inventory->customer_id);
        $this->assertSame('active', $inventory->status);
        $this->assertNotNull($inventory->activated_at);
        $this->assertNotNull($inventory->expires_at);
    }

    public function test_assign_rejects_non_numeric_card_number(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $customer = Customer::create([
            'customer_code' => 'CUST-BADNUM',
            'name' => 'Bad Num',
            'phone' => '5550002222',
            'is_active' => true,
        ]);

        $cardType = MembershipCardType::create([
            'name' => 'Bronze',
            'slug' => 'bronze-bad',
            'kind' => 'physical',
            'min_points' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('loyalty.cards.assign'), [
                'customer_id' => $customer->id,
                'membership_card_type_id' => $cardType->id,
                'card_number' => 'ABC-123',
                'status' => 'active',
            ])
            ->assertSessionHasErrors('card_number');
    }
}
