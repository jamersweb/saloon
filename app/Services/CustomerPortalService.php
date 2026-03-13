<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerPortalToken;
use Illuminate\Support\Str;

class CustomerPortalService
{
    public function issueToken(Customer $customer, ?int $createdBy = null, ?int $validForDays = 60): CustomerPortalToken
    {
        CustomerPortalToken::query()
            ->where('customer_id', $customer->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        return CustomerPortalToken::create([
            'customer_id' => $customer->id,
            'token' => Str::random(48),
            'expires_at' => $validForDays ? now()->addDays($validForDays) : null,
            'created_by' => $createdBy,
        ]);
    }

    public function resolveActiveToken(string $token): ?CustomerPortalToken
    {
        $portalToken = CustomerPortalToken::query()
            ->with([
                'customer.loyaltyAccount.tier',
                'customer.membershipCards.type',
                'customer.packages.package',
                'customer.giftCards',
                'customer.appointments.service',
                'customer.appointments.staffProfile.user',
            ])
            ->where('token', $token)
            ->whereNull('revoked_at')
            ->first();

        if (! $portalToken) {
            return null;
        }

        if ($portalToken->expires_at && $portalToken->expires_at->isPast()) {
            $portalToken->update(['revoked_at' => now()]);
            return null;
        }

        $portalToken->update(['last_accessed_at' => now()]);

        return $portalToken;
    }

    public function portalUrl(CustomerPortalToken $token): string
    {
        return route('customer.portal.show', $token->token);
    }
}
