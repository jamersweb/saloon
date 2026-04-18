<?php

namespace Database\Seeders;

use App\Models\MembershipCardType;
use Illuminate\Database\Seeder;

/**
 * Physical card products matching Vina "Loyalty Card.pdf" (Queen / Titanium / Gold).
 * min_points align with loyalty tiers for optional MembershipCardService::eligibleTypeForPoints().
 *
 * Direct purchase (PDF): Queen early physical AED 50; Titanium AED 3,000+VAT; Gold AED 5,000+VAT (VAT at invoice).
 */
class MembershipCardTypesSeeder extends Seeder
{
    public function run(): void
    {
        MembershipCardType::query()->where('slug', 'silver')->update(['is_active' => false]);

        $types = [
            [
                'name' => 'Queen card',
                'slug' => 'queen',
                'kind' => 'physical',
                'min_points' => 0,
                'direct_purchase_price' => 50.00,
                'validity_days' => 365,
                'is_active' => true,
                'is_transferable' => false,
            ],
            [
                'name' => 'Titanium card',
                'slug' => 'titanium',
                'kind' => 'physical',
                'min_points' => 1000,
                'direct_purchase_price' => 3000.00,
                'validity_days' => 365,
                'is_active' => true,
                'is_transferable' => false,
            ],
            [
                'name' => 'Gold card',
                'slug' => 'gold',
                'kind' => 'physical',
                'min_points' => 2500,
                'direct_purchase_price' => 5000.00,
                'validity_days' => 365,
                'is_active' => true,
                'is_transferable' => false,
            ],
        ];

        foreach ($types as $row) {
            MembershipCardType::updateOrCreate(
                ['slug' => $row['slug']],
                $row
            );
        }
    }
}
