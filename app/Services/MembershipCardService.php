<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerMembershipCard;
use App\Models\MembershipCardType;
use Illuminate\Support\Str;

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
            'nfc_uid' => $attributes['nfc_uid'] ?? null,
            'status' => $attributes['status'] ?? 'active',
            'issued_at' => $attributes['issued_at'] ?? $issuedAt,
            'activated_at' => $attributes['activated_at'] ?? $issuedAt,
            'expires_at' => $attributes['expires_at'] ?? $expiresAt,
            'assigned_by' => $assignedBy,
            'notes' => $attributes['notes'] ?? null,
        ]);
    }
}
