<?php

namespace App\Services;

use App\Models\GiftCard;
use App\Models\InvoicePayment;
use App\Models\TaxInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TaxInvoicePaymentService
{
    public function __construct(
        private GiftCardService $giftCardService,
    ) {}

    /**
     * @param  array{amount: float|string, method: string, paid_at: \DateTimeInterface|string, reference_note?: ?string, gift_card_id?: ?int}  $data
     */
    public function record(TaxInvoice $invoice, array $data, User $user): InvoicePayment
    {
        if ($invoice->status !== TaxInvoice::STATUS_FINALIZED) {
            throw ValidationException::withMessages([
                'payment' => 'Payments are only allowed on finalized invoices.',
            ]);
        }

        if ($data['method'] === InvoicePayment::METHOD_GIFT_CARD) {
            $invoice = $this->prepareGiftCardInvoice($invoice);
        }

        $amount = (float) $data['amount'];
        $balance = $invoice->balanceDue();
        if ($data['method'] === InvoicePayment::METHOD_GIFT_CARD && $amount > $balance && $balance > 0) {
            $amount = $balance;
        }
        if ($amount > $balance + 0.009) {
            throw ValidationException::withMessages([
                'amount' => 'Amount exceeds balance due ('.number_format($balance, 2).').',
            ]);
        }

        return DB::transaction(function () use ($invoice, $data, $amount, $user) {
            $referenceNote = $data['reference_note'] ?? null;

            if ($data['method'] === InvoicePayment::METHOD_GIFT_CARD) {
                $giftCardId = $data['gift_card_id'] ?? null;
                if (! $giftCardId) {
                    throw ValidationException::withMessages([
                        'gift_card_id' => 'Select a gift card for this payment.',
                    ]);
                }

                $card = GiftCard::query()->lockForUpdate()->findOrFail((int) $giftCardId);

                if ($card->status !== 'active') {
                    throw ValidationException::withMessages([
                        'gift_card_id' => 'This gift card is not active.',
                    ]);
                }

                if ($invoice->customer_id !== null
                    && $card->assigned_customer_id !== null
                    && (int) $card->assigned_customer_id !== (int) $invoice->customer_id) {
                    throw ValidationException::withMessages([
                        'gift_card_id' => 'This gift card is assigned to a different customer than the invoice.',
                    ]);
                }

                $reason = $invoice->invoice_number
                    ? 'Tax invoice '.$invoice->invoice_number
                    : 'Tax invoice draft #'.$invoice->id;

                $this->giftCardService->consume(
                    $card,
                    $amount,
                    $reason,
                    $user->id,
                    'Checkout payment',
                    $invoice->appointment_id,
                );

                $referenceNote = trim(($referenceNote ? $referenceNote.' · ' : '').$card->code);
            }

            return InvoicePayment::query()->create([
                'tax_invoice_id' => $invoice->id,
                'amount' => $amount,
                'method' => $data['method'],
                'paid_at' => $data['paid_at'],
                'reference_note' => $referenceNote,
                'created_by' => $user->id,
            ]);
        });
    }

    public function prepareGiftCardInvoice(TaxInvoice $invoice): TaxInvoice
    {
        $invoice->loadMissing('items', 'payments');

        if ($invoice->payments->isNotEmpty()) {
            return $invoice;
        }

        $hasVat = $invoice->items->contains(fn ($item) => (float) $item->line_tax > 0 || (float) $item->tax_rate_percent > 0);
        if (! $hasVat) {
            return $invoice;
        }

        DB::transaction(function () use ($invoice): void {
            $invoice->items()->get()->each(function ($item): void {
                $item->update([
                    'tax_rate_percent' => 0,
                    'line_tax' => 0,
                    'line_total' => (float) $item->line_subtotal,
                ]);
            });

            $invoice->refresh();
            $invoice->load('items');
            $invoice->update([
                'subtotal' => round($invoice->items->sum('line_subtotal'), 2),
                'vat_amount' => 0,
                'total' => round($invoice->items->sum('line_subtotal'), 2),
            ]);
        });

        return $invoice->fresh(['items', 'payments']);
    }
}
