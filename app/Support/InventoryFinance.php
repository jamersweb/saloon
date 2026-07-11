<?php

namespace App\Support;

use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Collection;

class InventoryFinance
{
    /**
     * @return array{
     *   expense_subcategory: string,
     *   cost_center: string,
     *   out_classification: string
     * }
     */
    public static function profileForItem(?InventoryItem $item): array
    {
        $haystack = self::itemHaystack($item);

        if (self::containsAny($haystack, ['package', 'packaging', 'bag', 'box', 'pouch', 'label', 'wrap'])) {
            return [
                'expense_subcategory' => 'packaging',
                'cost_center' => FinanceStructure::DEFAULT_COST_CENTER,
                'out_classification' => 'manual_adjustment',
            ];
        }

        if (self::containsAny($haystack, [
            'retail', 'home care', 'homecare', 'shampoo', 'conditioner', 'mask', 'serum',
            'spray', 'mousse', 'styling', 'oil', 'leave in', 'leave-in', 'aftercare',
        ])) {
            return [
                'expense_subcategory' => 'retail_stock',
                'cost_center' => FinanceStructure::DEFAULT_COST_CENTER,
                'out_classification' => 'retail_products',
            ];
        }

        return [
            'expense_subcategory' => 'service_consumables',
            'cost_center' => self::inferCostCenter($item),
            'out_classification' => 'service_consumables',
        ];
    }

    public static function inferTransactionClassification(?InventoryItem $item, string $type): string
    {
        if ($type === 'in') {
            return 'inventory_purchase';
        }

        if ($type === 'adjustment') {
            return 'manual_adjustment';
        }

        return self::profileForItem($item)['out_classification'];
    }

    /**
     * @param  Collection<int, PurchaseOrderItem>  $rows
     * @return list<array{
     *   category: string,
     *   cost_center: string,
     *   expense_type: string,
     *   expense_subcategory: string,
     *   amount_subtotal: float,
     *   vat_amount: float,
     *   total_amount: float,
     *   notes: string
     * }>
     */
    public static function groupedPurchaseOrderExpenseRows(Collection $rows, PurchaseOrder $purchaseOrder): array
    {
        return $rows
            ->groupBy(function (PurchaseOrderItem $row): string {
                $profile = self::profileForItem($row->item);

                return $profile['expense_subcategory'].'|'.$profile['cost_center'];
            })
            ->map(function (Collection $group, string $key) use ($purchaseOrder): array {
                [$expenseSubcategory, $costCenter] = array_pad(explode('|', $key), 2, '');
                $itemLabels = $group
                    ->map(fn (PurchaseOrderItem $row) => $row->item?->name)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'category' => 'inventory_purchase',
                    'cost_center' => $costCenter ?: FinanceStructure::DEFAULT_COST_CENTER,
                    'expense_type' => 'inventory_related',
                    'expense_subcategory' => $expenseSubcategory ?: 'retail_stock',
                    'amount_subtotal' => round((float) $group->sum('line_total'), 2),
                    'vat_amount' => 0.0,
                    'total_amount' => round((float) $group->sum('line_total'), 2),
                    'notes' => 'Auto-created from purchase order receipt '.$purchaseOrder->po_number
                        .($itemLabels !== [] ? ' | Items: '.implode(', ', array_slice($itemLabels, 0, 4)) : ''),
                ];
            })
            ->values()
            ->all();
    }

    private static function inferCostCenter(?InventoryItem $item): string
    {
        $haystack = self::itemHaystack($item);

        return match (true) {
            self::containsAny($haystack, ['extension']) => 'hair_extension',
            self::containsAny($haystack, ['color', 'toner', 'bleach', 'developer', 'oxidant', 'koleston', 'illumin', 'blondor', 'lakme', 'wella']) => 'hair_color',
            self::containsAny($haystack, ['lash', 'eyelash', 'brow tint']) => 'eyelash',
            self::containsAny($haystack, ['nail', 'gel polish', 'acrylic', 'manicure', 'pedicure', 'cuticle', 'dadi']) => 'nail',
            self::containsAny($haystack, ['wax', 'strip', 'thread']) => 'waxing',
            self::containsAny($haystack, ['makeup', 'lip', 'pigment', 'foundation']) => 'makeup',
            default => FinanceStructure::DEFAULT_COST_CENTER,
        };
    }

    private static function itemHaystack(?InventoryItem $item): string
    {
        return strtolower(trim(implode(' ', array_filter([
            $item?->name,
            $item?->category,
            $item?->sku,
        ]))));
    }

    /**
     * @param  list<string>  $needles
     */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
