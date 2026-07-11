<?php

namespace Tests\Feature;

use App\Models\ExpenseEntry;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryFinanceSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_receiving_mixed_purchase_order_creates_separate_finance_expense_rows(): void
    {
        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
            'permissions' => Permissions::defaultsForRole('owner'),
        ]);

        $owner = User::factory()->create([
            'role_id' => $ownerRole->id,
        ]);

        $supplier = Supplier::create([
            'name' => 'Inventory Supplier',
            'contact_person' => 'Vendor',
            'phone' => '5552221100',
            'is_active' => true,
        ]);

        $retailItem = InventoryItem::create([
            'sku' => 'RET-001',
            'name' => 'Retail Shampoo',
            'category' => 'Retail',
            'unit' => 'pcs',
            'cost_price' => 20,
            'selling_price' => 35,
            'stock_quantity' => 5,
            'reorder_level' => 2,
            'is_active' => true,
        ]);

        $serviceItem = InventoryItem::create([
            'sku' => 'COL-001',
            'name' => 'Koleston Perfect 7/0',
            'category' => 'Wella Koleston Perfect',
            'unit' => 'pcs',
            'cost_price' => 25,
            'selling_price' => 0,
            'stock_quantity' => 4,
            'reorder_level' => 2,
            'is_active' => true,
        ]);

        $purchaseOrder = PurchaseOrder::create([
            'po_number' => 'PO-MIX-001',
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrder::STATUS_APPROVED,
            'order_date' => now()->toDateString(),
            'total_cost' => 130,
            'created_by' => $owner->id,
            'approved_by' => $owner->id,
            'approved_at' => now(),
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $purchaseOrder->id,
            'inventory_item_id' => $retailItem->id,
            'quantity_ordered' => 3,
            'quantity_received' => 0,
            'unit_cost' => 20,
            'line_total' => 60,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $purchaseOrder->id,
            'inventory_item_id' => $serviceItem->id,
            'quantity_ordered' => 2,
            'quantity_received' => 0,
            'unit_cost' => 35,
            'line_total' => 70,
        ]);

        $this->actingAs($owner)
            ->patch(route('purchase-orders.transition', $purchaseOrder), [
                'status' => PurchaseOrder::STATUS_RECEIVED,
            ])
            ->assertSessionHasNoErrors();

        $expenses = ExpenseEntry::query()
            ->where('purchase_order_id', $purchaseOrder->id)
            ->orderBy('expense_subcategory')
            ->get();

        $this->assertCount(2, $expenses);

        $retailExpense = $expenses->firstWhere('expense_subcategory', 'retail_stock');
        $serviceExpense = $expenses->firstWhere('expense_subcategory', 'service_consumables');

        $this->assertNotNull($retailExpense);
        $this->assertSame('inventory_purchase', $retailExpense->category);
        $this->assertSame('general_salon', $retailExpense->cost_center);
        $this->assertEqualsWithDelta(60.0, (float) $retailExpense->total_amount, 0.01);

        $this->assertNotNull($serviceExpense);
        $this->assertSame('inventory_purchase', $serviceExpense->category);
        $this->assertSame('hair_color', $serviceExpense->cost_center);
        $this->assertEqualsWithDelta(70.0, (float) $serviceExpense->total_amount, 0.01);
    }

    public function test_stock_out_without_explicit_classification_uses_item_profile(): void
    {
        $ownerRole = Role::create([
            'name' => 'owner',
            'label' => 'Owner',
            'permissions' => Permissions::defaultsForRole('owner'),
        ]);

        $owner = User::factory()->create([
            'role_id' => $ownerRole->id,
        ]);

        $retailItem = InventoryItem::create([
            'sku' => 'RET-OUT-01',
            'name' => 'Retail Conditioner',
            'category' => 'Retail',
            'unit' => 'pcs',
            'cost_price' => 18,
            'selling_price' => 30,
            'stock_quantity' => 10,
            'reorder_level' => 2,
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->post(route('inventory.adjust', $retailItem), [
                'type' => 'out',
                'quantity' => 2,
                'reference' => 'SALE-1',
            ])
            ->assertSessionHasNoErrors();

        $transaction = InventoryTransaction::query()->latest()->first();

        $this->assertNotNull($transaction);
        $this->assertSame('retail_products', $transaction->classification);
        $this->assertSame(-2, $transaction->quantity);
    }
}
