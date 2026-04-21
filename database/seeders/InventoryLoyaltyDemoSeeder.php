<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\CustomerLoyaltyAccount;
use App\Models\CustomerLoyaltyLedger;
use App\Models\CustomerTag;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTier;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InventoryLoyaltyDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $tiersByName = LoyaltyTier::query()
                ->whereIn('name', ['Queen', 'Titanium', 'Gold'])
                ->get()
                ->keyBy('name');

            $ownerId = User::query()->where('email', 'owner@saloon.local')->value('id');

            foreach ([
                ['name' => 'Glow Distributors', 'contact_person' => 'Noah Patel', 'phone' => '5557001001', 'email' => 'orders@glowdist.example', 'is_active' => true],
                ['name' => 'Salon Supply Hub', 'contact_person' => 'Emily Stone', 'phone' => '5557001002', 'email' => 'support@salonsupplyhub.example', 'is_active' => true],
            ] as $supplierData) {
                Supplier::updateOrCreate(['name' => $supplierData['name']], $supplierData);
            }

            foreach ([
                ['name' => '10% Service Discount', 'description' => 'Apply on next service', 'points_cost' => 120, 'stock_quantity' => null, 'is_active' => true],
                ['name' => 'Free Hair Spa Add-on', 'description' => 'One complimentary add-on', 'points_cost' => 220, 'stock_quantity' => 40, 'is_active' => true],
                ['name' => 'VIP Priority Slot', 'description' => 'Priority booking benefit', 'points_cost' => 320, 'stock_quantity' => null, 'is_active' => true],
            ] as $rewardData) {
                LoyaltyReward::updateOrCreate(['name' => $rewardData['name']], $rewardData);
            }

            foreach ([
                ['name' => 'VIP', 'color' => '#0ea5e9', 'is_active' => true],
                ['name' => 'Regular', 'color' => '#10b981', 'is_active' => true],
                ['name' => 'Inactive', 'color' => '#f59e0b', 'is_active' => true],
            ] as $tagData) {
                CustomerTag::updateOrCreate(['name' => $tagData['name']], $tagData);
            }

            $demoCustomers = [
                ['name' => 'Aisha Khan', 'phone' => '5551002001', 'email' => 'aisha@example.com', 'points' => 85, 'tier_name' => 'Queen'],
                ['name' => 'Daniel Reed', 'phone' => '5551002002', 'email' => 'daniel@example.com', 'points' => 220, 'tier_name' => 'Queen'],
                ['name' => 'Maya Ortiz', 'phone' => '5551002003', 'email' => 'maya@example.com', 'points' => 410, 'tier_name' => 'Queen'],
                ['name' => 'Sara Lee', 'phone' => '5551002004', 'email' => 'sara@example.com', 'points' => 1500, 'tier_name' => 'Titanium'],
            ];

            foreach ($demoCustomers as $index => $entry) {
                $customer = Customer::updateOrCreate(
                    ['phone' => $entry['phone']],
                    [
                        'customer_code' => 'CUST-DEMO-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                        'name' => $entry['name'],
                        'email' => $entry['email'],
                        'acquisition_source' => 'demo_seed',
                        'is_active' => true,
                    ]
                );

                $tier = $tiersByName->get($entry['tier_name'])
                    ?? LoyaltyTier::query()
                        ->where('is_active', true)
                        ->where('min_points', '<=', $entry['points'])
                        ->orderByDesc('min_points')
                        ->first();

                CustomerLoyaltyAccount::updateOrCreate(
                    ['customer_id' => $customer->id],
                    [
                        'loyalty_tier_id' => $tier?->id,
                        'current_points' => $entry['points'],
                        'last_activity_at' => now()->subDays(random_int(1, 10)),
                    ]
                );

                CustomerLoyaltyLedger::firstOrCreate(
                    ['customer_id' => $customer->id, 'reason' => 'Opening demo balance'],
                    [
                        'loyalty_tier_id' => $tier?->id,
                        'points_change' => $entry['points'],
                        'balance_after' => $entry['points'],
                        'reference' => 'SEED-LOYALTY-OPENING',
                        'notes' => 'Demo seeded loyalty points',
                        'created_by' => $ownerId,
                    ]
                );
            }
        });
    }
}
