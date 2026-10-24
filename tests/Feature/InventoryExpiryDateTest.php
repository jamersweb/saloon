<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryExpiryDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_expiry_date_can_be_saved_updated_and_cleared(): void
    {
        $role = Role::create(['name' => 'owner', 'label' => 'Owner', 'permissions' => Permissions::defaultsForRole('owner')]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));
        $payload = ['sku' => 'EXP-001', 'name' => 'Shampoo', 'unit' => 'pcs', 'cost_price' => 10, 'selling_price' => 20, 'stock_quantity' => 5, 'reorder_level' => 1, 'is_active' => true];

        $this->post(route('inventory.store'), $payload + ['expiry_date' => '2027-05-31'])->assertSessionHasNoErrors();
        $item = InventoryItem::where('sku', 'EXP-001')->firstOrFail();
        $this->assertSame('2027-05-31', $item->toArray()['expiry_date']);

        $this->put(route('inventory.update', $item), $payload + ['expiry_date' => '2028-02-29'])->assertSessionHasNoErrors();
        $this->assertSame('2028-02-29', $item->fresh()->toArray()['expiry_date']);

        $this->put(route('inventory.update', $item), $payload + ['expiry_date' => '2027-02-30'])->assertSessionHasErrors('expiry_date');
        $this->assertSame('2028-02-29', $item->fresh()->toArray()['expiry_date']);

        $this->put(route('inventory.update', $item), $payload + ['expiry_date' => ''])->assertSessionHasNoErrors();
        $this->assertNull($item->fresh()->expiry_date);

        $payload['sku'] = 'EXP-002';
        $this->post(route('inventory.store'), $payload)->assertSessionHasNoErrors();
        $this->assertNull(InventoryItem::where('sku', 'EXP-002')->firstOrFail()->expiry_date);
    }
}
