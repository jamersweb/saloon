<?php

namespace App\Services;

use App\Models\FinanceSetting;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\TaxInvoice;
use App\Models\TaxInvoiceItem;
use App\Support\InventoryAlerts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaxInvoiceFinalizeService
{
    public function finalize(TaxInvoice $invoice, ?int $performedBy = null): TaxInvoice
    {
        if (! $invoice->isEditable()) {
            throw ValidationException::withMessages([
                'invoice' => 'Invoice is already finalized or void.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $performedBy) {
            $settings = FinanceSetting::query()->whereKey(1)->lockForUpdate()->firstOrFail();

            $num = (int) $settings->next_invoice_number;
            $invoiceNumber = $settings->invoice_prefix.str_pad((string) $num, 5, '0', STR_PAD_LEFT);

            $settings->next_invoice_number = $num + 1;
            $settings->save();

            $invoice->update([
                'invoice_number' => $invoiceNumber,
                'status' => TaxInvoice::STATUS_FINALIZED,
                'issued_at' => now(),
            ]);

            $this->deductInventoryForInvoice($invoice->fresh('items'), $invoiceNumber, $performedBy);

            return $invoice->fresh();
        });
    }

    private function deductInventoryForInvoice(TaxInvoice $invoice, string $invoiceNumber, ?int $performedBy): void
    {
        $inventoryLines = $invoice->items
            ->filter(fn (TaxInvoiceItem $item) => $item->inventory_item_id !== null && (float) $item->quantity > 0);

        foreach ($inventoryLines as $line) {
            $quantity = (int) $line->quantity;

            $item = InventoryItem::query()
                ->whereKey($line->inventory_item_id)
                ->lockForUpdate()
                ->first();

            if (! $item) {
                throw ValidationException::withMessages([
                    'invoice' => "Inventory item for invoice line {$line->description} no longer exists.",
                ]);
            }

            if ($item->stock_quantity < $quantity) {
                throw ValidationException::withMessages([
                    'invoice' => "{$item->name} has {$item->stock_quantity} in stock; {$quantity} is needed to issue this invoice.",
                ]);
            }

            $item->update([
                'stock_quantity' => $item->stock_quantity - $quantity,
            ]);

            InventoryTransaction::query()->create([
                'inventory_item_id' => $item->id,
                'type' => 'out',
                'classification' => 'retail_products',
                'source_type' => TaxInvoiceItem::class,
                'source_id' => $line->id,
                'quantity' => -$quantity,
                'reference' => $invoiceNumber,
                'notes' => 'Auto deduction from tax invoice '.$invoiceNumber,
                'performed_by' => $performedBy ?? $invoice->created_by,
            ]);

            InventoryAlerts::syncForItem($item->fresh(), $performedBy ?? $invoice->created_by);
        }
    }
}
