<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\CustomerMembershipCard;
use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GiftCardService
{
    /** @var array<string, string> */
    private const GIFT_CARD_SERIES_STARTS = [
        '300.00' => '3602567010010010',
        '500.00' => '3602568010020010',
        '1000.00' => '3602569010030010',
    ];

    public function issue(?Customer $customer, float $value, ?int $issuedBy = null, ?string $notes = null, ?string $nfcUid = null): GiftCard
    {
        $normalizedNfc = $this->normalizeNfcUid($nfcUid);
        if ($normalizedNfc !== null) {
            $this->assertNfcUidAvailableForGiftCard($normalizedNfc);
        }

        return DB::transaction(function () use ($customer, $value, $issuedBy, $notes, $normalizedNfc): GiftCard {
            return GiftCard::create([
                'code' => $this->nextGiftCardCodeForValue($value),
                'nfc_uid' => $normalizedNfc,
                'assigned_customer_id' => $customer?->id,
                'initial_value' => $value,
                'remaining_value' => $value,
                'status' => 'active',
                'issued_by' => $issuedBy,
                'notes' => $notes,
            ]);
        });
    }

    public function ensureGiftCardFromMembershipCard(CustomerMembershipCard $membershipCard, ?int $issuedBy = null): ?GiftCard
    {
        $membershipCard->loadMissing(['customer', 'type']);

        if ($membershipCard->type?->kind !== 'gift') {
            return null;
        }

        $value = round((float) ($membershipCard->type?->direct_purchase_price ?? 0), 2);
        if ($value <= 0) {
            return null;
        }

        $normalizedCode = $this->formatCardNumber((string) $membershipCard->card_number);
        $normalizedNfc = $this->normalizeNfcUid($membershipCard->nfc_uid);

        return DB::transaction(function () use ($membershipCard, $issuedBy, $value, $normalizedCode, $normalizedNfc): GiftCard {
            $existing = GiftCard::query()
                ->when($membershipCard->card_number, function ($query) use ($membershipCard, $normalizedCode) {
                    $query->where('code', $membershipCard->card_number)
                        ->orWhere('code', $normalizedCode);
                })
                ->when($normalizedNfc, function ($query) use ($normalizedNfc) {
                    $query->orWhere('nfc_uid', $normalizedNfc);
                })
                ->first();

            if (! $existing) {
                return GiftCard::create([
                    'code' => $normalizedCode,
                    'nfc_uid' => $normalizedNfc,
                    'assigned_customer_id' => $membershipCard->customer_id,
                    'initial_value' => $value,
                    'remaining_value' => $value,
                    'status' => $membershipCard->status === 'active' ? 'active' : 'inactive',
                    'issued_by' => $issuedBy,
                    'notes' => $membershipCard->notes,
                ]);
            }

            $updates = [
                'code' => $normalizedCode,
                'assigned_customer_id' => $membershipCard->customer_id,
                'issued_by' => $issuedBy ?? $existing->issued_by,
            ];

            if ($normalizedNfc !== null) {
                $updates['nfc_uid'] = $normalizedNfc;
            }

            if ((float) $existing->initial_value <= 0) {
                $updates['initial_value'] = $value;
            }

            if ((float) $existing->remaining_value <= 0 && (float) $existing->initial_value <= 0) {
                $updates['remaining_value'] = $value;
            }

            if ($existing->status !== 'redeemed') {
                $updates['status'] = $membershipCard->status === 'active' ? 'active' : 'inactive';
            }

            $existing->update($updates);

            return $existing->fresh();
        });
    }

    public function findByNfcUid(string $nfcUid): ?GiftCard
    {
        return GiftCard::query()
            ->with(['customer:id,name,phone,email'])
            ->where('nfc_uid', $this->normalizeNfcUid($nfcUid))
            ->first();
    }

    public function backfillGiftCardsForCustomer(int $customerId, ?int $issuedBy = null): void
    {
        CustomerMembershipCard::query()
            ->with('type')
            ->where('customer_id', $customerId)
            ->where('status', 'active')
            ->whereHas('type', fn ($query) => $query->where('kind', 'gift')->where('is_active', true))
            ->get()
            ->each(fn (CustomerMembershipCard $card) => $this->ensureGiftCardFromMembershipCard($card, $issuedBy));
    }

    public function bindNfcUid(GiftCard $giftCard, string $nfcUid, ?int $issuedBy = null, bool $replaceExisting = false): GiftCard
    {
        $normalizedUid = $this->normalizeNfcUid($nfcUid);
        if ($normalizedUid === null) {
            throw ValidationException::withMessages([
                'nfc_uid' => 'NFC UID cannot be empty.',
            ]);
        }

        return DB::transaction(function () use ($giftCard, $normalizedUid, $issuedBy, $replaceExisting) {
            $existingGift = GiftCard::query()
                ->where('nfc_uid', $normalizedUid)
                ->whereKeyNot($giftCard->id)
                ->first();

            if ($existingGift && ! $replaceExisting) {
                throw ValidationException::withMessages([
                    'nfc_uid' => 'This NFC UID is already linked to another gift card.',
                ]);
            }

            if ($existingGift && $replaceExisting) {
                $existingGift->update([
                    'nfc_uid' => null,
                    'notes' => trim(($existingGift->notes ? $existingGift->notes.PHP_EOL : '').'NFC UID reassigned on '.now()->toDateTimeString()),
                ]);
            }

            if (CustomerMembershipCard::query()->where('nfc_uid', $normalizedUid)->exists()) {
                throw ValidationException::withMessages([
                    'nfc_uid' => 'This NFC UID is already linked to a membership card.',
                ]);
            }

            $giftCard->update([
                'nfc_uid' => $normalizedUid,
                'issued_by' => $issuedBy ?? $giftCard->issued_by,
            ]);

            return $giftCard->fresh(['customer']);
        });
    }

    public function assertNfcUidAvailableForGiftCard(string $normalizedUid): void
    {
        if (GiftCard::query()->where('nfc_uid', $normalizedUid)->exists()) {
            throw ValidationException::withMessages([
                'nfc_uid' => 'This NFC UID is already linked to a gift card.',
            ]);
        }

        if (CustomerMembershipCard::query()->where('nfc_uid', $normalizedUid)->exists()) {
            throw ValidationException::withMessages([
                'nfc_uid' => 'This NFC UID is already linked to a membership card.',
            ]);
        }
    }

    private function normalizeNfcUid(?string $nfcUid): ?string
    {
        if ($nfcUid === null) {
            return null;
        }

        $normalized = strtoupper(trim($nfcUid));

        return $normalized === '' ? null : $normalized;
    }

    private function nextGiftCardCodeForValue(float $value): string
    {
        $seriesStart = $this->seriesStartForGiftValue($value);

        // Unsupported denominations keep backward-compatible random code generation.
        if ($seriesStart === null) {
            return 'GIFT-'.Str::upper(Str::random(10));
        }

        $prefix = substr($seriesStart, 0, 12);
        $nextNumber = (int) $seriesStart;

        $existingNumbers = GiftCard::query()
            ->where('initial_value', number_format(round($value, 2), 2, '.', ''))
            ->pluck('code');

        foreach ($existingNumbers as $existingCode) {
            $digits = preg_replace('/\D+/', '', (string) $existingCode) ?? '';
            if (strlen($digits) !== 16 || ! str_starts_with($digits, $prefix)) {
                continue;
            }

            $numeric = (int) $digits;
            if ($numeric >= $nextNumber) {
                $nextNumber = $numeric + 1;
            }
        }

        while (GiftCard::query()
            ->whereIn('code', [$this->formatCardNumber((string) $nextNumber), (string) $nextNumber])
            ->exists()) {
            $nextNumber++;
        }

        return $this->formatCardNumber((string) $nextNumber);
    }

    private function seriesStartForGiftValue(float $value): ?string
    {
        $key = number_format(round($value, 2), 2, '.', '');

        return self::GIFT_CARD_SERIES_STARTS[$key] ?? null;
    }

    private function formatCardNumber(string $digits): string
    {
        $digits = preg_replace('/\D+/', '', $digits) ?? '';
        $padded = str_pad($digits, 16, '0', STR_PAD_LEFT);

        return trim(chunk_split($padded, 4, ' '));
    }

    public function consume(GiftCard $giftCard, float $amount, string $reason, ?int $createdBy = null, ?string $notes = null, ?int $appointmentId = null): GiftCardTransaction
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.']);
        }

        return DB::transaction(function () use ($giftCard, $amount, $reason, $createdBy, $notes, $appointmentId) {
            $giftCard->refresh();

            if ((float) $giftCard->remaining_value < $amount) {
                throw ValidationException::withMessages(['amount' => 'Gift card balance is insufficient.']);
            }

            if ($appointmentId !== null) {
                $appointment = Appointment::query()->find($appointmentId);
                if ($appointment === null || $appointment->customer_id === null) {
                    throw ValidationException::withMessages([
                        'appointment_id' => 'Select a valid appointment with a customer.',
                    ]);
                }
                if ($giftCard->assigned_customer_id !== null
                    && (int) $giftCard->assigned_customer_id !== (int) $appointment->customer_id) {
                    throw ValidationException::withMessages([
                        'appointment_id' => 'The gift card is assigned to a different customer than this visit.',
                    ]);
                }
            }

            $nextBalance = round((float) $giftCard->remaining_value - $amount, 2);
            $giftCard->update([
                'remaining_value' => $nextBalance,
                'status' => $nextBalance <= 0 ? 'redeemed' : $giftCard->status,
            ]);

            return GiftCardTransaction::create([
                'gift_card_id' => $giftCard->id,
                'appointment_id' => $appointmentId,
                'amount_change' => -$amount,
                'balance_after' => $nextBalance,
                'reason' => $reason,
                'notes' => $notes,
                'created_by' => $createdBy,
            ]);
        });
    }
}
