<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sku',
        'name',
        'category',
        'unit',
        'cost_price',
        'selling_price',
        'stock_quantity',
        'reorder_level',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function appointmentProductUsages(): HasMany
    {
        return $this->hasMany(AppointmentProductUsage::class, 'inventory_item_id');
    }

    public function lowStockAlerts(): HasMany
    {
        return $this->hasMany(LowStockAlert::class);
    }
}
