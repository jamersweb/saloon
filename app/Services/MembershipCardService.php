<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerMembershipCard;
use App\Models\MembershipCardType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MembershipCardService
{
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

            return CustomerMembershipCard::create([
                'customer_id' => $customer->id,
                'membership_card_type_id' => $type->id,
                'card_number' => $attributes['card_number'] ?? strtoupper($type->slug) . '-' . Str::upper(Str::random(8)),
                'nfc_uid' => isset($attributes['nfc_uid']) ? $this->normalizeNfcUid($attributes['nfc_uid']) : null,
                'status' => $attributes['status'] ?? 'active',
                'issued_at' => $attributes['issued_at'] ?? $issuedAt,
                'activated_at' => $attributes['activated_at'] ?? $issuedAt,
                'expires_at' => $attributes['expires_at'] ?? $expiresAt,
                'assigned_by' => $assignedBy,
                'notes' => $attributes['notes'] ?? null,
            ]);
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
                    'notes' => trim(($existingCard->notes ? $existingCard->notes . PHP_EOL : '') . 'NFC UID reassigned on ' . now()->toDateTimeString()),
                ]);
            }

            $card->update([
                'nfc_uid' => $normalizedUid,
                'assigned_by' => $assignedBy ?? $card->assigned_by,
            ]);

            return $card->fresh(['customer', 'type']);
        });
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
