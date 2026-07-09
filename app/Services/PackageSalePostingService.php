<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\ServicePackage;
use App\Models\TaxInvoice;
use App\Models\TaxInvoiceItem;
use App\Support\FinanceStructure;
use Illuminate\Support\Facades\DB;

class PackageSalePostingService
{
    public function __construct(
        private readonly TaxInvoiceFinalizeService $finalizeService,
    ) {}

    public function post(Customer $customer, ServicePackage $package, ?int $createdById = null, ?string $notes = null): ?TaxInvoice
    {
        $saleAmount = (float) ($package->price ?? $package->initial_value ?? 0);
        if ($saleAmount <= 0.009) {
            return null;
        }

        $primaryService = $package->salonServices()->orderBy('salon_services.id')->first();
        $costCenter = FinanceStructure::inferCostCenterFromService($primaryService);
        $vatRate = (float) \App\Models\FinanceSetting::current()->vat_rate_percent;

        return DB::transaction(function () use ($customer, $package, $createdById, $notes, $saleAmount, $costCenter, $vatRate) {
            $invoice = TaxInvoice::query()->create([
                'customer_id' => $customer->id,
                'customer_display_name' => $customer->name,
                'status' => TaxInvoice::STATUS_DRAFT,
                'cashier_name' => null,
                'notes' => trim(implode(' ', array_filter([
                    'Package sale recorded at assignment time.',
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
                'revenue_category' => 'package_sales',
                'cost_center' => $costCenter,
                'description' => 'Package Sale: '.$package->name,
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
