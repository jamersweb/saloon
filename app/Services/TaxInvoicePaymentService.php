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

    public function applyAutoVoucher(TaxInvoice $invoice, User $user): ?InvoicePayment
    {
        if ($invoice->status !== TaxInvoice::STATUS_FINALIZED || $invoice->customer_id === null) {
            return null;
        }

        $invoice->refresh();
        if ((float) $invoice->total + 0.009 < GiftCardService::VOUCHER_MINIMUM_INVOICE_TOTAL || $invoice->balanceDue() <= 0.009) {
            return null;
        }

        $voucher = GiftCard::query()
            ->where('status', 'active')
            ->where('assigned_customer_id', $invoice->customer_id)
            ->where('remaining_value', '>', 0)
            ->where('notes', 'like', '%Random gift voucher%')
            ->whereIn('initial_value', array_map(
                fn (float $value): string => number_format($value, 2, '.', ''),
                GiftCardService::RANDOM_VOUCHER_VALUES,
            ))
            ->orderByDesc('remaining_value')
            ->orderBy('id')
            ->first();

        if (! $voucher) {
            return null;
        }

        $amount = min((float) $voucher->remaining_value, $invoice->balanceDue());
        if ($amount <= 0) {
            return null;
        }

        return $this->record($invoice, [
            'amount' => $amount,
            'method' => InvoicePayment::METHOD_GIFT_CARD,
            'paid_at' => now(),
            'reference_note' => 'Auto gift voucher',
            'gift_card_id' => $voucher->id,
        ], $user);
    }

    /**
     * @param  list<array{amount: float|string, method: string, paid_at: \DateTimeInterface|string, reference_note?: ?string, gift_card_id?: ?int}>  $rows
     * @return list<InvoicePayment>
     */
    public function recordBatch(TaxInvoice $invoice, array $rows, User $user): array
    {
        if ($invoice->status !== TaxInvoice::STATUS_FINALIZED) {
            throw ValidationException::withMessages([
                'payments' => 'Payments are only allowed on finalized invoices.',
            ]);
        }

        $invoice->refresh();
        $startingBalance = $invoice->balanceDue();
        $sum = round(array_sum(array_map(fn (array $row) => (float) $row['amount'], $rows)), 2);

        if ($sum > $startingBalance + 0.009) {
            throw ValidationException::withMessages([
                'payments' => 'Split payments exceed the remaining balance due.',
            ]);
        }

        $created = [];

        DB::transaction(function () use ($invoice, $rows, $user, &$created): void {
            foreach ($rows as $row) {
                $invoice->refresh();
                $created[] = $this->record($invoice, $row, $user);
            }
        });

        return $created;
    }
}
