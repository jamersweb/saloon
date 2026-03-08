<?php

namespace App\Support;

use App\Models\InventoryItem;
use App\Models\LowStockAlert;

class InventoryAlerts
{
    public static function syncForItem(InventoryItem $item, ?int $resolvedBy = null): void
    {
        $item->refresh();

        if ($item->stock_quantity <= $item->reorder_level) {
            LowStockAlert::query()->firstOrCreate(
                [
                    'inventory_item_id' => $item->id,
                    'status' => 'open',
                ],
                [
                    'stock_quantity' => $item->stock_quantity,
                    'reorder_level' => $item->reorder_level,
                ]
            );

            return;
        }

        LowStockAlert::query()
            ->where('inventory_item_id', $item->id)
            ->where('status', 'open')
            ->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'resolved_by' => $resolvedBy,
            ]);
    }

    public static function scanAll(?int $resolvedBy = null): void
    {
        InventoryItem::query()->chunkById(200, function ($items) use ($resolvedBy): void {
            foreach ($items as $item) {
                self::syncForItem($item, $resolvedBy);
            }
        });
    }
}
