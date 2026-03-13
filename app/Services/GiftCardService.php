<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GiftCardService
{
    public function issue(?Customer $customer, float $value, ?int $issuedBy = null, ?string $notes = null): GiftCard
    {
        return GiftCard::create([
            'code' => 'GIFT-' . Str::upper(Str::random(10)),
            'assigned_customer_id' => $customer?->id,
            'initial_value' => $value,
            'remaining_value' => $value,
            'status' => 'active',
            'issued_by' => $issuedBy,
            'notes' => $notes,
        ]);
    }

    public function consume(GiftCard $giftCard, float $amount, string $reason, ?int $createdBy = null, ?string $notes = null): GiftCardTransaction
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }

        return DB::transaction(function () use ($giftCard, $amount, $reason, $createdBy, $notes) {
            $giftCard->refresh();

            if ((float) $giftCard->remaining_value < $amount) {
                throw ValidationException::withMessages(['amount' => 'Gift card balance is insufficient.']);
            }

            $nextBalance = round((float) $giftCard->remaining_value - $amount, 2);
            $giftCard->update([
                'remaining_value' => $nextBalance,
                'status' => $nextBalance <= 0 ? 'redeemed' : $giftCard->status,
            ]);

            return GiftCardTransaction::create([
                'gift_card_id' => $giftCard->id,
                'amount_change' => -$amount,
                'balance_after' => $nextBalance,
                'reason' => $reason,
                'notes' => $notes,
                'created_by' => $createdBy,
            ]);
        });
    }
}
