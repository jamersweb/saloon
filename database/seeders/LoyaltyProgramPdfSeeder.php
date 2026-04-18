<?php

namespace Database\Seeders;

use App\Models\LoyaltyProgramSetting;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTier;
use Illuminate\Database\Seeder;

/**
 * Aligns loyalty program defaults with Vina "Loyalty Card.pdf" (Queen / Titanium / Gold).
 *
 * Queen: 10% off, 1x earn. Titanium: 15% off, 1.5x. Gold: 30% off, 3x.
 * Base earn: 1 point per AED 10 net (points_per_currency = 0.1 with multiplier applied in LoyaltyService).
 *
 * Micro-service redemption: 300 points (Queen PDF); monthly / gap rules on the reward row.
 */
class LoyaltyProgramPdfSeeder extends Seeder
{
    public function run(): void
    {
        $settings = LoyaltyProgramSetting::query()->firstOrCreate(
            ['id' => 1],
            [
                'auto_earn_enabled' => true,
                'points_per_currency' => 0.1,
                'points_per_visit' => 0,
                'birthday_bonus_points' => 0,
                'referral_bonus_points' => 0,
                'review_bonus_points' => 0,
                'minimum_spend' => 0,
                'rounding_mode' => 'floor',
            ]
        );
        $settings->update([
            'auto_earn_enabled' => true,
            'points_per_currency' => 0.1,
            'points_per_visit' => 0,
            'minimum_spend' => 0,
            'rounding_mode' => 'floor',
        ]);

        $tiers = [
            [
                'name' => 'Queen',
                'min_points' => 0,
                'discount_percent' => 10,
                'earn_multiplier' => 1,
                'is_active' => true,
            ],
            [
                'name' => 'Titanium',
                'min_points' => 1000,
                'discount_percent' => 15,
                'earn_multiplier' => 1.5,
                'is_active' => true,
            ],
            [
                'name' => 'Gold',
                'min_points' => 2500,
                'discount_percent' => 30,
                'earn_multiplier' => 3,
                'is_active' => true,
            ],
        ];

        foreach ($tiers as $row) {
            LoyaltyTier::updateOrCreate(
                ['name' => $row['name']],
                $row
            );
        }

        LoyaltyTier::query()->whereIn('name', ['Silver', 'Platinum'])->update(['is_active' => false]);

        LoyaltyReward::updateOrCreate(
            ['name' => 'Complimentary micro-service (300 pts)'],
            [
                'description' => 'PDF: 300 points = one selected low-cost service. Queen tier: max 1/month, 7-day gap; Titanium/Gold PDF allows 2/month — raise monthly cap in Loyalty if you use one reward for all tiers.',
                'points_cost' => 300,
                'stock_quantity' => null,
                'is_active' => true,
                'max_units_per_redemption' => 1,
                'max_redemptions_per_calendar_month' => 1,
                'min_days_between_redemptions' => 7,
                'requires_appointment_id' => true,
            ]
        );
    }
}
