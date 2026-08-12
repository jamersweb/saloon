<?php

namespace Database\Seeders;

use App\Models\MembershipCardSequence;
use App\Models\MembershipCardType;
use Illuminate\Database\Seeder;

/**
 * Physical membership card series used for the 2026 Golden Members roster.
 */
class VinaMembershipSeriesSeeder extends Seeder
{
    public function run(): void
    {
        $type = MembershipCardType::updateOrCreate(
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

        MembershipCardSequence::updateOrCreate(
            ['membership_card_type_id' => $type->id],
            ['next_number' => '2607567810000019'],
        );
    }
}
