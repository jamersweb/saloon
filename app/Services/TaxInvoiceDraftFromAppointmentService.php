<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\FinanceSetting;
use App\Models\TaxInvoice;
use App\Models\TaxInvoiceItem;

class TaxInvoiceDraftFromAppointmentService
{
    public function create(Appointment $appointment, ?int $createdById, ?string $cashierName): TaxInvoice
    {
        $appointment->loadMissing([
            'service:id,name,price',
            'customer:id,name',
            'productUsages:id,appointment_id,inventory_item_id,quantity,notes',
            'productUsages.item:id,name,sku,selling_price,cost_price',
        ]);

        $service = $appointment->service;
        if (! $service) {
            throw new \InvalidArgumentException('Appointment has no linked service for invoicing.');
        }

        $unitPrice = (float) $service->price;
        $vatRate = (float) FinanceSetting::current()->vat_rate_percent;

        $customerDisplayName = $appointment->customer?->name
            ?? $appointment->customer_name
            ?? 'Walk-in';

        $invoice = TaxInvoice::query()->create([
            'customer_id' => $appointment->customer_id,
            'customer_display_name' => $customerDisplayName,
            'appointment_id' => $appointment->id,
            'status' => TaxInvoice::STATUS_DRAFT,
            'cashier_name' => $cashierName,
            'notes' => 'Created from appointment #'.$appointment->id,
            'created_by' => $createdById,
            'subtotal' => 0,
            'vat_amount' => 0,
            'total' => 0,
        ]);

        $computed = TaxInvoiceLineCalculator::compute(1, $unitPrice, $vatRate);

        TaxInvoiceItem::query()->create([
            'tax_invoice_id' => $invoice->id,
            'salon_service_id' => $service->id,
            'description' => $service->name,
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'line_subtotal' => $computed['line_subtotal'],
            'tax_rate_percent' => $computed['tax_rate_percent'],
            'line_tax' => $computed['line_tax'],
            'line_total' => $computed['line_total'],
        ]);

        foreach ($appointment->productUsages as $usage) {
            $item = $usage->item;
            if (! $item) {
                continue;
            }

            $quantity = max(1, (int) $usage->quantity);
            $unitPrice = (float) ($item->selling_price ?? $item->cost_price ?? 0);
            $productComputed = TaxInvoiceLineCalculator::compute($quantity, $unitPrice, $vatRate);

            $description = $item->name;
            if (! empty($item->sku)) {
                $description .= ' ('.$item->sku.')';
            }

            TaxInvoiceItem::query()->create([
                'tax_invoice_id' => $invoice->id,
                'salon_service_id' => null,
                'description' => $description,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_subtotal' => $productComputed['line_subtotal'],
                'tax_rate_percent' => $productComputed['tax_rate_percent'],
                'line_tax' => $productComputed['line_tax'],
                'line_total' => $productComputed['line_total'],
            ]);
        }

        $invoice->load('items');
        $invoice->update([
            'subtotal' => round($invoice->items->sum('line_subtotal'), 2),
            'vat_amount' => round($invoice->items->sum('line_tax'), 2),
            'total' => round($invoice->items->sum('line_total'), 2),
        ]);

        return $invoice->fresh();
    }
}
