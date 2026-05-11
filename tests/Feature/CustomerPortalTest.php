<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\CustomerLoyaltyLedger;
use App\Models\CustomerMembershipCard;
use App\Models\CustomerPackage;
use App\Models\CustomerPortalToken;
use App\Models\GiftCard;
use App\Models\MembershipCardType;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\ServicePackage;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\CustomerPortalService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_issue_customer_portal_token_and_revoke_previous_one(): void
    {
        $staffRole = Role::create([
            'name' => 'staff',
            'label' => 'Staff',
            'permissions' => Permissions::defaultsForRole('staff'),
        ]);
        $user = User::factory()->create(['role_id' => $staffRole->id]);

        $customer = Customer::create([
            'customer_code' => 'CUST-PORTAL-100',
            'name' => 'Portal Customer',
            'phone' => '5554001000',
            'is_active' => true,
        ]);

        $existingToken = CustomerPortalToken::create([
            'customer_id' => $customer->id,
            'token' => 'old-portal-token',
            'expires_at' => now()->addDays(10),
        ]);

        $this->actingAs($user)
            ->post(route('customers.portal-token.store', $customer))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Customer portal link generated.');

        $existingToken->refresh();
        $newToken = CustomerPortalToken::query()
            ->where('customer_id', $customer->id)
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();

        $this->assertNotNull($newToken);
        $this->assertNotSame($existingToken->token, $newToken->token);
        $this->assertNotNull($existingToken->revoked_at);
    }

    public function test_public_customer_portal_renders_customer_wallet_and_history(): void
    {
        $this->withoutVite();

        $customer = Customer::create([
            'customer_code' => 'CUST-PORTAL-200',
            'name' => 'Public Portal Customer',
            'phone' => '5554002000',
            'email' => 'portal@example.com',
            'is_active' => true,
        ]);

        $customer->loyaltyAccount()->update([
            'current_points' => 180,
        ]);

        $cardType = MembershipCardType::create([
            'name' => 'Titanium',
            'slug' => 'titanium-portal-test',
            'kind' => 'physical',
            'min_points' => 100,
            'is_active' => true,
        ]);

        CustomerMembershipCard::create([
            'customer_id' => $customer->id,
            'membership_card_type_id' => $cardType->id,
            'card_number' => '555044000001',
            'status' => 'active',
            'issued_at' => now()->subMonth(),
            'activated_at' => now()->subMonth(),
            'expires_at' => now()->addMonth(),
        ]);

        $package = ServicePackage::create([
            'name' => 'Bridal Package',
            'description' => 'Three premium sessions',
            'usage_limit' => 3,
            'is_active' => true,
        ]);

        CustomerPackage::create([
            'customer_id' => $customer->id,
            'service_package_id' => $package->id,
            'remaining_sessions' => 2,
            'remaining_value' => 150,
            'status' => 'active',
            'assigned_at' => now()->subWeek(),
            'expires_at' => now()->addMonths(2),
        ]);

        GiftCard::create([
            'code' => 'GIFT-PORTAL-01',
            'initial_value' => 100,
            'remaining_value' => 65,
            'status' => 'active',
            'assigned_customer_id' => $customer->id,
            'issued_at' => now()->subDays(10),
            'expires_at' => now()->addMonths(3),
        ]);

        $staffUser = User::factory()->create(['name' => 'Stylist One']);
        $staffProfile = StaffProfile::create([
            'user_id' => $staffUser->id,
            'employee_code' => 'STF-PORTAL-01',
            'is_active' => true,
        ]);

        $service = SalonService::create([
            'name' => 'Hair Spa',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'price' => 90,
            'is_active' => true,
        ]);

        Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'staff_profile_id' => $staffProfile->id,
            'source' => 'admin',
            'status' => Appointment::STATUS_COMPLETED,
            'scheduled_start' => now()->subDays(5),
            'scheduled_end' => now()->subDays(5)->addHour(),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'customer_email' => $customer->email,
            'notes' => 'Customer requested scalp treatment.',
        ]);

        CustomerLoyaltyLedger::create([
            'customer_id' => $customer->id,
            'points_change' => -40,
            'balance_after' => 180,
            'reason' => 'Reward redemption',
            'reference' => 'REWARD-1',
        ]);

        $portalToken = app(CustomerPortalService::class)->issueToken($customer);

        $this->get(route('customer.portal.show', $portalToken->token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/CustomerPortal')
                ->where('customer.name', 'Public Portal Customer')
                ->where('customer.current_card', 'Titanium')
                ->where('customer.points_spent', 40)
                ->where('customer.points_remaining', 180)
                ->where('customer.packages.0.name', 'Bridal Package')
                ->where('customer.gift_cards.0.code', 'GIFT-PORTAL-01')
                ->where('customer.service_history.0.service_name', 'Hair Spa'));
    }

    public function test_public_customer_portal_can_be_opened_by_membership_card_nfc_uid(): void
    {
        $this->withoutVite();

        $customer = Customer::create([
            'customer_code' => 'CUST-NFC-PORTAL-001',
            'name' => 'NFC Portal Customer',
            'phone' => '5554040404',
            'email' => 'nfc-portal@example.com',
            'is_active' => true,
        ]);

        $customer->loyaltyAccount()->update([
            'current_points' => 90,
        ]);

        $cardType = MembershipCardType::create([
            'name' => 'NFC Titanium',
            'slug' => 'nfc-titanium',
            'kind' => 'physical',
            'min_points' => 0,
            'is_active' => true,
        ]);

        CustomerMembershipCard::create([
            'customer_id' => $customer->id,
            'membership_card_type_id' => $cardType->id,
            'card_number' => '555077000001',
            'nfc_uid' => '04AB44CD',
            'status' => 'active',
            'issued_at' => now()->subWeek(),
            'activated_at' => now()->subWeek(),
        ]);

        CustomerLoyaltyLedger::create([
            'customer_id' => $customer->id,
            'points_change' => -10,
            'balance_after' => 90,
            'reason' => 'Reward redemption',
            'reference' => 'REWARD-2',
        ]);

        $this->get(route('customer.portal.nfc', ['nfcUid' => ' 04ab44cd ']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/CustomerPortal')
                ->where('customer.name', 'NFC Portal Customer')
                ->where('customer.points_spent', 10)
                ->where('customer.points_remaining', 90));
    }

    public function test_expired_customer_portal_token_returns_not_found(): void
    {
        $customer = Customer::create([
            'customer_code' => 'CUST-PORTAL-300',
            'name' => 'Expired Portal Customer',
            'phone' => '5554003000',
            'is_active' => true,
        ]);

        $portalToken = CustomerPortalToken::create([
            'customer_id' => $customer->id,
            'token' => 'expired-portal-token',
            'expires_at' => now()->subMinute(),
        ]);

        $this->get(route('customer.portal.show', $portalToken->token))
            ->assertNotFound();

        $portalToken->refresh();

        $this->assertNotNull($portalToken->revoked_at);
    }

    public function test_customer_profile_exposes_available_packages_and_can_assign_one(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $customer = Customer::create([
            'customer_code' => 'CUST-PKG-100',
            'name' => 'Package Customer',
            'phone' => '5554004000',
            'is_active' => true,
        ]);

        $package = ServicePackage::create([
            'name' => 'Vina 10 Sessions',
            'price' => 800,
            'usage_limit' => 10,
            'validity_days' => 365,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('customers.index', ['customer_id' => $customer->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Customers/Index')
                ->where('selectedCustomer.id', $customer->id)
                ->where('availablePackages.0.name', 'Vina 10 Sessions'));

        $this->actingAs($user)
            ->post(route('loyalty.packages.assign'), [
                'customer_id' => $customer->id,
                'service_package_id' => $package->id,
                'notes' => '10 sessions approved by Madam',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Package assigned.');

        $assignedPackage = CustomerPackage::query()->where('customer_id', $customer->id)->first();

        $this->assertNotNull($assignedPackage);
        $this->assertSame(10, $assignedPackage->remaining_sessions);
        $this->assertSame('active', $assignedPackage->status);
        $this->assertSame('10 sessions approved by Madam', $assignedPackage->notes);
    }

    public function test_assigning_membership_card_auto_assigns_linked_package(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $customer = Customer::create([
            'customer_code' => 'CUST-CARD-AUTO-001',
            'name' => 'Linked Package Customer',
            'phone' => '5555551001',
            'is_active' => true,
        ]);

        $package = ServicePackage::create([
            'name' => 'Gold Blowdry Package',
            'price' => 800,
            'usage_limit' => 10,
            'is_active' => true,
        ]);

        $cardType = MembershipCardType::create([
            'name' => 'Gold Membership',
            'slug' => 'gold-membership-auto-package',
            'kind' => 'physical',
            'min_points' => 0,
            'service_package_id' => $package->id,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('loyalty.cards.assign'), [
                'customer_id' => $customer->id,
                'membership_card_type_id' => $cardType->id,
                'status' => 'active',
                'notes' => 'Gold member package included',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Membership card assigned.');

        $assignedPackage = CustomerPackage::query()->where('customer_id', $customer->id)->latest('id')->first();

        $this->assertNotNull($assignedPackage);
        $this->assertSame($package->id, $assignedPackage->service_package_id);
        $this->assertSame(10, $assignedPackage->remaining_sessions);
        $this->assertSame('Gold member package included', $assignedPackage->notes);
    }

    public function test_membership_registration_auto_assigns_linked_package(): void
    {
        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id, 'name' => 'Manager User']);

        $package = ServicePackage::create([
            'name' => 'Vina 10 Sessions',
            'price' => 800,
            'usage_limit' => 10,
            'is_active' => true,
        ]);

        $cardType = MembershipCardType::create([
            'name' => 'Vina Membership',
            'slug' => 'vina-membership-auto-package',
            'kind' => 'physical',
            'min_points' => 0,
            'service_package_id' => $package->id,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('loyalty.cards.register-member'), [
                'registration_date' => now()->toDateString(),
                'staff_name' => 'Manager User',
                'full_name' => 'Pegah Emami-Kalb',
                'phone' => '971507356009',
                'email' => 'pegah@example.com',
                'is_first_visit' => true,
                'preferred_language' => 'English',
                'heard_about_us' => 'Instagram',
                'service_interests' => ['Blowdry'],
                'requires_home_service' => false,
                'preferred_visit_frequency' => 'Monthly',
                'spending_profile' => 'AED 500 - 2,000',
                'membership_card_type_id' => $cardType->id,
                'card_status' => 'active',
                'card_notes' => '10 session approved by Madam',
                'consent_data_processing' => '1',
                'consent_marketing' => '1',
                'signature_name' => 'Pegah Emami-Kalb',
                'signature_date' => now()->toDateString(),
                'notes' => 'Approved membership registration',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $customer = Customer::query()->where('phone', '971507356009')->first();
        $this->assertNotNull($customer);

        $assignedPackage = CustomerPackage::query()->where('customer_id', $customer->id)->latest('id')->first();

        $this->assertNotNull($assignedPackage);
        $this->assertSame($package->id, $assignedPackage->service_package_id);
        $this->assertSame(10, $assignedPackage->remaining_sessions);
        $this->assertSame('10 session approved by Madam', $assignedPackage->notes);
    }
}
