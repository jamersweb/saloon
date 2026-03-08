<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\CustomerLoyaltyAccount;
use App\Models\CustomerLoyaltyLedger;
use App\Models\CustomerTag;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
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
            $tiers = collect([
                ['name' => 'Silver', 'min_points' => 100, 'discount_percent' => 5, 'is_active' => true],
                ['name' => 'Gold', 'min_points' => 300, 'discount_percent' => 10, 'is_active' => true],
                ['name' => 'Platinum', 'min_points' => 600, 'discount_percent' => 15, 'is_active' => true],
            ])->map(fn (array $tier) => LoyaltyTier::updateOrCreate(['name' => $tier['name']], $tier));

            $inventoryItems = [
                ['sku' => 'INV-SHA-001', 'name' => 'Argan Repair Shampoo', 'category' => 'Shampoo', 'unit' => 'bottle', 'cost_price' => 7.5, 'selling_price' => 16.0, 'stock_quantity' => 28, 'reorder_level' => 8, 'is_active' => true],
                ['sku' => 'INV-CON-001', 'name' => 'Keratin Conditioner', 'category' => 'Conditioner', 'unit' => 'bottle', 'cost_price' => 8.2, 'selling_price' => 18.0, 'stock_quantity' => 21, 'reorder_level' => 8, 'is_active' => true],
                ['sku' => 'INV-COL-001', 'name' => 'Permanent Hair Color - Brown', 'category' => 'Color', 'unit' => 'tube', 'cost_price' => 4.8, 'selling_price' => 12.0, 'stock_quantity' => 45, 'reorder_level' => 15, 'is_active' => true],
                ['sku' => 'INV-TOO-001', 'name' => 'Disposable Nitrile Gloves', 'category' => 'Consumables', 'unit' => 'box', 'cost_price' => 5.4, 'selling_price' => 11.0, 'stock_quantity' => 11, 'reorder_level' => 10, 'is_active' => true],
                ['sku' => 'INV-SER-001', 'name' => 'Hair Serum', 'category' => 'Treatment', 'unit' => 'bottle', 'cost_price' => 9.1, 'selling_price' => 22.0, 'stock_quantity' => 17, 'reorder_level' => 6, 'is_active' => true],
            ];

            $ownerId = User::query()->where('email', 'owner@saloon.local')->value('id');

            foreach ($inventoryItems as $itemData) {
                $item = InventoryItem::updateOrCreate(['sku' => $itemData['sku']], $itemData);

                InventoryTransaction::firstOrCreate(
                    ['inventory_item_id' => $item->id, 'type' => 'in', 'reference' => 'SEED-OPENING-STOCK'],
                    [
                        'quantity' => (int) $itemData['stock_quantity'],
                        'notes' => 'Demo opening stock',
                        'performed_by' => $ownerId,
                    ]
                );
            }

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
                ['name' => 'Aisha Khan', 'phone' => '5551002001', 'email' => 'aisha@example.com', 'points' => 85],
                ['name' => 'Daniel Reed', 'phone' => '5551002002', 'email' => 'daniel@example.com', 'points' => 220],
                ['name' => 'Maya Ortiz', 'phone' => '5551002003', 'email' => 'maya@example.com', 'points' => 410],
                ['name' => 'Sara Lee', 'phone' => '5551002004', 'email' => 'sara@example.com', 'points' => 715],
            ];

            foreach ($demoCustomers as $index => $entry) {
                $customer = Customer::updateOrCreate(
                    ['phone' => $entry['phone']],
                    [
                        'customer_code' => 'CUST-DEMO-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                        'name' => $entry['name'],
                        'email' => $entry['email'],
                        'acquisition_source' => 'demo_seed',
                        'is_active' => true,
                    ]
                );

                $tier = $tiers
                    ->filter(fn (LoyaltyTier $tier) => $entry['points'] >= $tier->min_points)
                    ->sortByDesc('min_points')
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
