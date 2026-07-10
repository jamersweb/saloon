<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\TaxInvoice;
use App\Models\TaxInvoiceItem;
use App\Support\FinanceStructure;
use Illuminate\Support\Facades\DB;

class GiftCardSalePostingService
{
    public function __construct(
        private readonly TaxInvoiceFinalizeService $finalizeService,
    ) {}

    public function post(
        GiftCard $giftCard,
        ?Customer $customer = null,
        ?int $createdById = null,
        ?string $notes = null,
        ?float $amount = null,
        ?string $description = null,
    ): ?TaxInvoice {
        $saleAmount = round((float) ($amount ?? $giftCard->initial_value ?? 0), 2);
        if ($saleAmount <= 0.009) {
            return null;
        }

        $vatRate = (float) \App\Models\FinanceSetting::current()->vat_rate_percent;
        $label = $description ?: 'Gift Card Sale: '.$giftCard->code;

        return DB::transaction(function () use ($customer, $giftCard, $createdById, $notes, $saleAmount, $vatRate, $label) {
            $invoice = TaxInvoice::query()->create([
                'customer_id' => $customer?->id,
                'customer_display_name' => $customer?->name ?: 'Gift Card Customer',
                'status' => TaxInvoice::STATUS_DRAFT,
                'notes' => trim(implode(' ', array_filter([
                    'Gift card sale recorded at issue/top-up time.',
                    $notes,
                ]))),
                'created_by' => $createdById,
                'subtotal' => 0,
                'vat_amount' => 0,
                'total' => 0,
            ]);

            $computed = TaxInvoiceLineCalculator::compute(1, $saleAmount, $vatRate, 0);

            TaxInvoiceItem::query()->create([
                'tax_invoice_id' => $invoice->id,
                'salon_service_id' => null,
                'revenue_category' => 'gift_card_sales',
                'cost_center' => FinanceStructure::DEFAULT_COST_CENTER,
                'description' => $label,
                'quantity' => 1,
                'unit_price' => $saleAmount,
                'discount_amount' => 0,
                'line_subtotal' => $computed['line_subtotal'],
                'tax_rate_percent' => $computed['tax_rate_percent'],
                'line_tax' => $computed['line_tax'],
                'line_total' => $computed['line_total'],
            ]);

            $invoice->update([
                'subtotal' => round($computed['line_subtotal'], 2),
                'vat_amount' => round($computed['line_tax'], 2),
                'total' => round($computed['line_total'], 2),
            ]);

            return $this->finalizeService->finalize($invoice);
        });
    }
}
