<?php

namespace App\Services;

use App\Models\FinanceSetting;
use App\Models\RentalAgreement;
use App\Models\RentalSettlement;
use App\Models\TaxInvoice;
use App\Models\TaxInvoiceItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RentalSettlementPostingService
{
    public function __construct(
        private readonly TaxInvoiceFinalizeService $finalizeService,
    ) {}

    public function post(
        RentalAgreement $agreement,
        string $settlementDate,
        ?float $grossSalesAmount,
        float $fixedRentAmount,
        ?string $notes,
        ?int $createdById = null,
    ): RentalSettlement {
        $commissionPercent = (float) ($agreement->commission_percent ?? 0);
        $commissionAmount = $grossSalesAmount !== null ? round($grossSalesAmount * ($commissionPercent / 100), 2) : 0.0;
        $totalAmount = round($fixedRentAmount + $commissionAmount, 2);

        if ($totalAmount <= 0.009) {
            throw ValidationException::withMessages([
                'fixed_rent_amount' => 'Settlement total must be greater than zero.',
            ]);
        }

        return DB::transaction(function () use ($agreement, $settlementDate, $grossSalesAmount, $fixedRentAmount, $commissionAmount, $totalAmount, $notes, $createdById) {
            $settings = FinanceSetting::current();
            $vatRate = (float) $settings->vat_rate_percent;

            $invoice = TaxInvoice::query()->create([
                'customer_id' => $agreement->customer_id,
                'customer_display_name' => $agreement->customer?->name ?: $agreement->partner_name,
                'status' => TaxInvoice::STATUS_DRAFT,
                'notes' => trim(implode(' ', array_filter([
                    ucfirst($agreement->agreement_type).' rental settlement for '.$agreement->partner_name.'.',
                    $notes,
                ]))),
                'created_by' => $createdById,
                'subtotal' => 0,
                'vat_amount' => 0,
                'total' => 0,
            ]);

            $category = $agreement->agreement_type === RentalAgreement::TYPE_CHAIR
                ? 'chair_rental_income'
                : 'line_rental_income';

            $lines = [];

            if ($fixedRentAmount > 0.009) {
                $lines[] = [
                    'revenue_category' => $category,
                    'description' => ucfirst($agreement->agreement_type).' Rental Income: '.$agreement->partner_name,
                    'amount' => $fixedRentAmount,
                ];
            }

            if ($commissionAmount > 0.009) {
                $lines[] = [
                    'revenue_category' => 'commission_income',
                    'description' => 'Commission From Rented Line: '.$agreement->partner_name,
                    'amount' => $commissionAmount,
                ];
            }

            foreach ($lines as $line) {
                $computed = TaxInvoiceLineCalculator::compute(1, $line['amount'], $vatRate, 0);

                TaxInvoiceItem::query()->create([
                    'tax_invoice_id' => $invoice->id,
                    'salon_service_id' => null,
                    'revenue_category' => $line['revenue_category'],
                    'cost_center' => $agreement->cost_center,
                    'description' => $line['description'],
                    'quantity' => 1,
                    'unit_price' => $line['amount'],
                    'discount_amount' => 0,
                    'line_subtotal' => $computed['line_subtotal'],
                    'tax_rate_percent' => $computed['tax_rate_percent'],
                    'line_tax' => $computed['line_tax'],
                    'line_total' => $computed['line_total'],
                ]);
            }

            $invoice->load('items');
            $invoice->update([
                'subtotal' => round($invoice->items->sum('line_subtotal'), 2),
                'vat_amount' => round($invoice->items->sum('line_tax'), 2),
                'total' => round($invoice->items->sum('line_total'), 2),
            ]);

            $finalizedInvoice = $this->finalizeService->finalize($invoice);

            return RentalSettlement::query()->create([
                'rental_agreement_id' => $agreement->id,
                'settlement_date' => $settlementDate,
                'gross_sales_amount' => $grossSalesAmount,
                'fixed_rent_amount' => $fixedRentAmount,
                'commission_amount' => $commissionAmount,
                'total_amount' => $totalAmount,
                'tax_invoice_id' => $finalizedInvoice->id,
                'notes' => $notes,
                'created_by' => $createdById,
            ]);
        });
    }
}
