<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerMembershipCard;
use App\Models\MembershipCardType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VinaMembershipSeedersTest extends TestCase
{
    use RefreshDatabase;

    public function test_vina_membership_roster_seeds_fourteen_cards_with_expected_numbers(): void
    {
        $this->seed([
            \Database\Seeders\RoleSeeder::class,
            \Database\Seeders\VinaMembershipSeriesSeeder::class,
            \Database\Seeders\VinaMembershipRosterSeeder::class,
        ]);

        $type = MembershipCardType::query()->where('slug', 'vina-membership-2026')->sole();

        $this->assertSame(14, Customer::query()->where('acquisition_source', 'vina_membership_roster_2026')->count());
        $this->assertSame(14, CustomerMembershipCard::query()->where('membership_card_type_id', $type->id)->count());

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
