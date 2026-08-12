<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMembershipCard;
use App\Models\MembershipCardSequence;
use App\Models\MembershipCardType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VinaMembershipSeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_vina_membership_roster_seeds_cleaned_golden_members_list(): void
    {
        $this->seed([
            \Database\Seeders\RoleSeeder::class,
            \Database\Seeders\VinaMembershipSeriesSeeder::class,
            \Database\Seeders\VinaMembershipRosterSeeder::class,
        ]);

        $type = MembershipCardType::query()->where('slug', 'vina-membership-2026')->sole();

        $this->assertSame(17, Customer::query()->where('acquisition_source', 'vina_membership_roster_2026')->count());
        $this->assertSame(18, CustomerMembershipCard::query()->where('membership_card_type_id', $type->id)->count());

        $this->assertDatabaseHas('customer_membership_cards', [
            'card_number' => '2602567810000001',
            'membership_card_type_id' => $type->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('customer_membership_cards', [
            'card_number' => '2603567810000012',
        ]);
        $this->assertDatabaseHas('customer_membership_cards', [
            'card_number' => '2604567810000014',
        ]);
        $this->assertDatabaseHas('customer_membership_cards', [
            'card_number' => '2607567810000018',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('customer_membership_cards', [
            'card_number' => '2602567810000003',
            'status' => 'expired',
        ]);

        $mona = Customer::query()->where('phone', '971508077326')->sole();

        $this->assertSame(2, CustomerMembershipCard::query()
            ->where('customer_id', $mona->id)
            ->where('status', 'active')
            ->count());

        $this->assertSame(
            '2607567810000019',
            (string) MembershipCardSequence::query()->where('membership_card_type_id', $type->id)->sole()->next_number,
        );
    }

    public function test_admin_seeder_creates_admin_user(): void
    {
        $this->seed([
            \Database\Seeders\RoleSeeder::class,
            \Database\Seeders\AdminSeeder::class,
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'admin@vina.local',
        ]);
    }
}
