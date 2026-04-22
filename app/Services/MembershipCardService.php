<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerMembershipCard;
use App\Models\GiftCard;
use App\Models\MembershipCardSequence;
use App\Models\MembershipCardType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MembershipCardService
{
    /** @var int First auto-issued number (12-digit block). Sequence table stores the *next* number to issue. */
    private const FIRST_ISSUABLE_NUMBER = 100_000_000_001;

    private static bool $ensuredSequenceColumnWidth = false;

    private const QUEEN_SERIES_START = '1602569010000001';
    private const TITANIUM_SERIES_START = '4602567010000001';
    private const GOLD_SERIES_START = '2602567810000001';

    private function ensureSequenceColumnSupportsIssuedCardNumbers(): void
    {
        if (self::$ensuredSequenceColumnWidth) {
            return;
        }

        MembershipCardSequence::ensureNextNumberColumnIsBigInt();
        self::$ensuredSequenceColumnWidth = true;
    }

    public function eligibleTypeForPoints(int $points): ?MembershipCardType
    {
        return MembershipCardType::query()
            ->where('is_active', true)
            ->where('kind', '!=', 'gift')
            ->where('min_points', '<=', $points)
            ->orderByDesc('min_points')
            ->first();
    }

    public function assignCard(Customer $customer, MembershipCardType $type, ?int $assignedBy = null, array $attributes = []): CustomerMembershipCard
    {
        return DB::transaction(function () use ($customer, $type, $assignedBy, $attributes) {
            if ($type->kind !== 'gift') {
                CustomerMembershipCard::query()
                    ->where('customer_id', $customer->id)
                    ->where('status', 'active')
                    ->update(['status' => 'inactive']);
            }

            $issuedAt = now();
            $expiresAt = $type->validity_days ? $issuedAt->copy()->addDays((int) $type->validity_days) : null;

            $nfc = isset($attributes['nfc_uid']) ? $this->normalizeNfcUid($attributes['nfc_uid']) : null;
            $this->assertNfcAvailableForMembership($nfc);

            $cardNumber = $this->resolveCardNumber($attributes['card_number'] ?? null, $type);

            return CustomerMembershipCard::create([
                'customer_id' => $customer->id,
                'membership_card_type_id' => $type->id,
                'card_number' => $cardNumber,
                'nfc_uid' => $nfc,
                'status' => $attributes['status'] ?? 'active',
                'issued_at' => $attributes['issued_at'] ?? $issuedAt,
                'activated_at' => $attributes['activated_at'] ?? $issuedAt,
                'expires_at' => $attributes['expires_at'] ?? $expiresAt,
                'assigned_by' => $assignedBy,
                'notes' => $attributes['notes'] ?? null,
            ]);
        });
    }

    /**
     * Pre-issue a physical card for inventory: numeric card number only, no customer until sale.
     */
    public function issueInventoryCard(MembershipCardType $type, ?int $assignedBy = null, array $attributes = []): CustomerMembershipCard
    {
        return DB::transaction(function () use ($type, $assignedBy, $attributes) {
            $nfc = isset($attributes['nfc_uid']) ? $this->normalizeNfcUid($attributes['nfc_uid']) : null;
            $this->assertNfcAvailableForMembership($nfc);

            $cardNumber = $this->resolveCardNumber($attributes['card_number'] ?? null, $type);

            return CustomerMembershipCard::create([
                'customer_id' => null,
                'membership_card_type_id' => $type->id,
                'card_number' => $cardNumber,
                'nfc_uid' => $nfc,
                'status' => $attributes['status'] ?? 'pending',
                'issued_at' => $attributes['issued_at'] ?? now(),
                'activated_at' => null,
                'expires_at' => null,
                'assigned_by' => $assignedBy,
                'notes' => $attributes['notes'] ?? null,
            ]);
        });
    }

    public function assignInventoryToCustomer(Customer $customer, CustomerMembershipCard $card, ?int $assignedBy = null, array $attributes = []): CustomerMembershipCard
    {
        return DB::transaction(function () use ($customer, $card, $assignedBy, $attributes) {
            $card->refresh();
            $card->loadMissing('type');

            if ($card->customer_id !== null) {
                throw ValidationException::withMessages([
                    'customer_membership_card_id' => 'This card is already assigned to a customer.',
                ]);
            }

            if ($card->type?->kind !== 'gift') {
                CustomerMembershipCard::query()
                    ->where('customer_id', $customer->id)
                    ->where('status', 'active')
                    ->update(['status' => 'inactive']);
            }

            $activatedAt = now();
            $expiresAt = $card->type?->validity_days
                ? $activatedAt->copy()->addDays((int) $card->type->validity_days)
                : null;

            $card->update([
                'customer_id' => $customer->id,
                'status' => $attributes['status'] ?? 'active',
                'activated_at' => $attributes['activated_at'] ?? $activatedAt,
                'expires_at' => $attributes['expires_at'] ?? $expiresAt,
                'assigned_by' => $assignedBy ?? $card->assigned_by,
                'notes' => array_key_exists('notes', $attributes) ? $attributes['notes'] : $card->notes,
            ]);

            return $card->fresh(['customer', 'type']);
        });
    }

    public function findByNfcUid(string $nfcUid): ?CustomerMembershipCard
    {
        return CustomerMembershipCard::query()
            ->with(['customer:id,name,phone,email', 'type:id,name,kind'])
            ->where('nfc_uid', $this->normalizeNfcUid($nfcUid))
            ->first();
    }

    public function bindNfcUid(CustomerMembershipCard $card, string $nfcUid, ?int $assignedBy = null, bool $replaceExisting = false): CustomerMembershipCard
    {
        $normalizedUid = $this->normalizeNfcUid($nfcUid);

        return DB::transaction(function () use ($card, $normalizedUid, $assignedBy, $replaceExisting) {
            $existingCard = CustomerMembershipCard::query()
                ->where('nfc_uid', $normalizedUid)
                ->whereKeyNot($card->id)
                ->first();

            if ($existingCard && ! $replaceExisting) {
                throw ValidationException::withMessages([
                    'nfc_uid' => 'This NFC UID is already linked to another membership card.',
                ]);
            }

            if ($existingCard && $replaceExisting) {
                $existingCard->update([
                    'nfc_uid' => null,
                    'notes' => trim(($existingCard->notes ? $existingCard->notes.PHP_EOL : '').'NFC UID reassigned on '.now()->toDateTimeString()),
                ]);
            }

            if (GiftCard::query()->where('nfc_uid', $normalizedUid)->exists()) {
                throw ValidationException::withMessages([
                    'nfc_uid' => 'This NFC UID is already linked to a gift card.',
                ]);
            }

            $card->update([
                'nfc_uid' => $normalizedUid,
                'assigned_by' => $assignedBy ?? $card->assigned_by,
            ]);

            return $card->fresh(['customer', 'type']);
        });
    }

    private function assertNfcAvailableForMembership(?string $nfc): void
    {
        if ($nfc === null) {
            return;
        }

        if (GiftCard::query()->where('nfc_uid', $nfc)->exists()) {
            throw ValidationException::withMessages([
                'nfc_uid' => 'This NFC UID is already linked to a gift card.',
            ]);
        }
    }

    private function resolveCardNumber(?string $explicit, MembershipCardType $type): string
    {
        if ($explicit !== null && $explicit !== '') {
            $trimmed = trim($explicit);
            if ($trimmed === '' || ! ctype_digit($trimmed)) {
                throw ValidationException::withMessages([
                    'card_number' => 'Card number must contain digits only.',
                ]);
            }
            if (CustomerMembershipCard::query()->where('card_number', $trimmed)->exists()) {
                throw ValidationException::withMessages([
                    'card_number' => 'This card number is already in use.',
                ]);
            }

            if ($this->seriesStartForType($type) === null) {
                $this->alignSequenceAfterExplicitIssue($type, $trimmed);
            }

            return $trimmed;
        }

        $seriesStart = $this->seriesStartForType($type);
        if ($seriesStart !== null) {
            return $this->allocateNextSeriesCardNumber($seriesStart);
        }

        return $this->allocateNextSequentialCardNumber($type);
    }

    private function seriesStartForType(MembershipCardType $type): ?string
    {
        $slug = strtolower((string) ($type->slug ?? ''));
        $name = strtolower((string) ($type->name ?? ''));

        if (str_contains($slug, 'queen') || str_contains($name, 'queen') || str_contains($slug, 'virtual') || str_contains($name, 'virtual')) {
            // Virtual cards share the Queen card range.
            return self::QUEEN_SERIES_START;
        }

        if (str_contains($slug, 'titanium') || str_contains($name, 'titanium')) {
            return self::TITANIUM_SERIES_START;
        }

        if (str_contains($slug, 'gold') || str_contains($name, 'gold')) {
            return self::GOLD_SERIES_START;
        }

        return null;
    }

    private function allocateNextSeriesCardNumber(string $seriesStart): string
    {
        $prefix = substr($seriesStart, 0, 12);
        $nextNumber = (int) $seriesStart;

        $existingNumbers = CustomerMembershipCard::query()
            ->whereNotNull('card_number')
            ->pluck('card_number');

        foreach ($existingNumbers as $cardNumber) {
            $digits = preg_replace('/\D+/', '', (string) $cardNumber) ?? '';
            if (strlen($digits) !== 16 || ! str_starts_with($digits, $prefix)) {
                continue;
            }

            $numeric = (int) $digits;
            if ($numeric >= $nextNumber) {
                $nextNumber = $numeric + 1;
            }
        }

        while (CustomerMembershipCard::query()
            ->whereIn('card_number', [
                (string) $nextNumber,
                trim(chunk_split((string) $nextNumber, 4, ' ')),
            ])
            ->exists()) {
            $nextNumber++;
        }

        return (string) $nextNumber;
    }

    private function alignSequenceAfterExplicitIssue(MembershipCardType $type, string $trimmedDigits): void
    {
        $this->ensureSequenceColumnSupportsIssuedCardNumbers();

        $n = (int) $trimmedDigits;
        $mustBeAtLeast = $n + 1;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $row = MembershipCardSequence::query()
                    ->where('membership_card_type_id', $type->id)
                    ->lockForUpdate()
                    ->first();

                if (! $row) {
                    MembershipCardSequence::create([
                        'membership_card_type_id' => $type->id,
                        'next_number' => max(self::FIRST_ISSUABLE_NUMBER, $mustBeAtLeast),
                    ]);

                    return;
                }

                if ((int) $row->next_number <= $n) {
                    $row->update(['next_number' => $mustBeAtLeast]);
                }

                return;
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw ValidationException::withMessages([
            'card_number' => 'Could not reserve card number sequence. Try again.',
        ]);
    }

    private function allocateNextSequentialCardNumber(MembershipCardType $type): string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            try {
                return $this->allocateNextSequentialCardNumberAttempt($type);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw ValidationException::withMessages([
            'card_number' => 'Could not allocate the next card number. Try again.',
        ]);
    }

    private function allocateNextSequentialCardNumberAttempt(MembershipCardType $type): string
    {
        $this->ensureSequenceColumnSupportsIssuedCardNumbers();

        $row = MembershipCardSequence::query()
            ->where('membership_card_type_id', $type->id)
            ->lockForUpdate()
            ->first();

        if (! $row) {
            MembershipCardSequence::create([
                'membership_card_type_id' => $type->id,
                'next_number' => self::FIRST_ISSUABLE_NUMBER + 1,
            ]);

            return (string) self::FIRST_ISSUABLE_NUMBER;
        }

        $issued = (int) $row->next_number;
        $row->update(['next_number' => $issued + 1]);

        return (string) $issued;
    }

    private function normalizeNfcUid(?string $nfcUid): ?string
    {
        if ($nfcUid === null) {
            return null;
        }

        $normalized = strtoupper(trim($nfcUid));

        return $normalized === '' ? null : $normalized;
    }
}
