<?php

namespace Database\Seeders;

use App\Models\MembershipCardType;
use Illuminate\Database\Seeder;

/**
 * Physical membership card series used for the March–April 2026 roster (16-digit numbers:
 * 2602 5678 1000 0001 … 0011, then 2603 … 0012–0013, then 2604 … 0014). New cards issued
 * for this type continue the numeric sequence via MembershipCardService / membership_card_sequences.
 */
class VinaMembershipSeriesSeeder extends Seeder
{
    public function run(): void
    {
        MembershipCardType::updateOrCreate(
            ['slug' => 'vina-membership-2026'],
            [
                'name' => 'Vina membership card (2026 series)',
                'kind' => 'physical',
                'min_points' => 0,
                'direct_purchase_price' => null,
                'validity_days' => 365,
                'is_active' => true,
                'is_transferable' => false,
            ],
        );
    }
}
