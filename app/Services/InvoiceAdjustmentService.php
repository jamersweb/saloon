<?php

namespace App\Services;

use App\Models\TaxInvoice;
use App\Models\TaxInvoiceItem;
use App\Support\FinanceStructure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceAdjustmentService
{
    public function __construct(
        private readonly TaxInvoiceFinalizeService $finalizeService,
    ) {}

    public function createRefundAdjustment(TaxInvoice $invoice, float $amount, string $reason, ?int $createdById = null): TaxInvoice
    {
        if ($invoice->status !== TaxInvoice::STATUS_FINALIZED) {
            throw ValidationException::withMessages([
                'invoice' => 'Only finalized invoices can be adjusted.',
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Adjustment amount must be greater than zero.',
            ]);
        }

        if ($amount > (float) $invoice->total + 0.009) {
            throw ValidationException::withMessages([
                'amount' => 'Adjustment amount cannot exceed the original invoice total.',
            ]);
        }

        $firstItem = $invoice->items()->orderBy('id')->first();
        $revenueCategory = $firstItem?->revenue_category ?? FinanceStructure::DEFAULT_REVENUE_CATEGORY;
        $costCenter = $firstItem?->cost_center ?? FinanceStructure::DEFAULT_COST_CENTER;
        $vatRate = $firstItem?->tax_rate_percent ?? 0;

        return DB::transaction(function () use ($invoice, $amount, $reason, $createdById, $revenueCategory, $costCenter, $vatRate) {
            $adjustment = TaxInvoice::query()->create([
                'customer_id' => $invoice->customer_id,
                'customer_display_name' => $invoice->customer_display_name,
                'status' => TaxInvoice::STATUS_DRAFT,
                'appointment_id' => $invoice->appointment_id,
                'related_invoice_id' => $invoice->id,
                'adjustment_type' => 'refund_adjustment',
                'adjustment_reason' => $reason,
                'notes' => 'Refund / Adjustment for '.$invoice->invoice_number.'. '.$reason,
                'created_by' => $createdById,
                'subtotal' => 0,
                'vat_amount' => 0,
                'total' => 0,
            ]);

            $netAmount = round($amount / (1 + ((float) $vatRate / 100)), 2);
            $taxAmount = round($amount - $netAmount, 2);

            TaxInvoiceItem::query()->create([
                'tax_invoice_id' => $adjustment->id,
                'salon_service_id' => null,
                'revenue_category' => $revenueCategory,
                'cost_center' => $costCenter,
                'description' => 'Refund / Adjustment for '.$invoice->invoice_number,
                'quantity' => 1,
                'unit_price' => -$netAmount,
                'discount_amount' => 0,
                'line_subtotal' => -$netAmount,
                'tax_rate_percent' => (float) $vatRate,
                'line_tax' => -$taxAmount,
                'line_total' => -$amount,
            ]);

            $adjustment->update([
                'subtotal' => -$netAmount,
                'vat_amount' => -$taxAmount,
                'total' => -$amount,
            ]);

            return $this->finalizeService->finalize($adjustment);
        });
    }
}
