<?php

namespace App\Services;

use App\Models\FinanceSetting;
use App\Models\TaxInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaxInvoiceFinalizeService
{
    public function finalize(TaxInvoice $invoice): TaxInvoice
    {
        if (! $invoice->isEditable()) {
            throw ValidationException::withMessages([
                'invoice' => 'Invoice is already finalized or void.',
            ]);
        }

        return DB::transaction(function () use ($invoice) {
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

            return $invoice->fresh();
        });
    }
}
