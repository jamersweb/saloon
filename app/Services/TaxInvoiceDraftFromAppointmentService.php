<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\FinanceSetting;
use App\Models\TaxInvoice;
use App\Models\TaxInvoiceItem;

class TaxInvoiceDraftFromAppointmentService
{
    public function __construct(private readonly AppointmentVisitService $appointmentVisitService) {}

    public function create(Appointment $appointment, ?int $createdById, ?string $cashierName): TaxInvoice
    {
        $visitAppointments = $this->appointmentVisitService
            ->forAppointment($appointment)
            ->loadMissing([
            'service:id,name,price',
            'customer:id,name',
            'productUsages:id,appointment_id,inventory_item_id,quantity,notes',
            'productUsages.item:id,name,sku,selling_price,cost_price',
        ]);

        $primaryAppointment = $visitAppointments->first();
        if (! $primaryAppointment?->service) {
            throw new \InvalidArgumentException('Appointment has no linked service for invoicing.');
        }

        $vatRate = (float) FinanceSetting::current()->vat_rate_percent;

        $customerDisplayName = $primaryAppointment->customer?->name
            ?? $primaryAppointment->customer_name
            ?? 'Walk-in';

        $existingDraft = TaxInvoice::query()
            ->where('status', TaxInvoice::STATUS_DRAFT)
            ->whereIn('appointment_id', $visitAppointments->pluck('id'))
            ->orderBy('id')
            ->first();

        if ($existingDraft) {
            $invoice = tap($existingDraft)->update([
                'customer_id' => $primaryAppointment->customer_id,
                'customer_display_name' => $customerDisplayName,
                'appointment_id' => $primaryAppointment->id,
                'cashier_name' => $cashierName,
                'notes' => $visitAppointments->count() > 1
                    ? 'Created from visit appointments #'.$visitAppointments->pluck('id')->implode(', #')
                    : 'Created from appointment #'.$primaryAppointment->id,
                'created_by' => $createdById,
                'subtotal' => 0,
                'vat_amount' => 0,
                'total' => 0,
            ]);
            $invoice->items()->delete();
        } else {
            $invoice = TaxInvoice::query()->create([
                'customer_id' => $primaryAppointment->customer_id,
                'customer_display_name' => $customerDisplayName,
                'appointment_id' => $primaryAppointment->id,
                'status' => TaxInvoice::STATUS_DRAFT,
                'cashier_name' => $cashierName,
                'notes' => $visitAppointments->count() > 1
                    ? 'Created from visit appointments #'.$visitAppointments->pluck('id')->implode(', #')
                    : 'Created from appointment #'.$primaryAppointment->id,
                'created_by' => $createdById,
                'subtotal' => 0,
                'vat_amount' => 0,
                'total' => 0,
            ]);
        }

        foreach ($visitAppointments as $visitAppointment) {
            $service = $visitAppointment->service;
            if (! $service) {
                continue;
            }

            $unitPrice = $visitAppointment->customer_package_id ? 0.0 : (float) $service->price;
            $computed = TaxInvoiceLineCalculator::compute(1, $unitPrice, $vatRate);

            TaxInvoiceItem::query()->create([
                'tax_invoice_id' => $invoice->id,
                'salon_service_id' => $service->id,
                'description' => $visitAppointment->customer_package_id
                    ? $service->name.' (package session)'
                    : $service->name,
                'quantity' => 1,
                'unit_price' => $unitPrice,
                'line_subtotal' => $computed['line_subtotal'],
                'tax_rate_percent' => $computed['tax_rate_percent'],
                'line_tax' => $computed['line_tax'],
                'line_total' => $computed['line_total'],
            ]);

            foreach ($visitAppointment->productUsages as $usage) {
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
