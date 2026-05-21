<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerPortalToken;
use Illuminate\Support\Str;

class CustomerPortalService
{
    public function issueToken(Customer $customer, ?int $createdBy = null, ?int $validForDays = null): CustomerPortalToken
    {
        $validForDays ??= (int) config('customer_portal.token_lifetime_days', 60);

        CustomerPortalToken::query()
            ->where('customer_id', $customer->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        return CustomerPortalToken::create([
            'customer_id' => $customer->id,
            'token' => Str::random(48),
            'expires_at' => $validForDays > 0 ? now()->addDays($validForDays) : null,
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

        if (! $this->isActive($portalToken)) {
            $portalToken->update(['revoked_at' => now()]);

            return null;
        }

        $portalToken->update(['last_accessed_at' => now()]);

        return $portalToken;
    }

    public function isActive(CustomerPortalToken $portalToken): bool
    {
        if ($portalToken->revoked_at !== null) {
            return false;
        }

        if ($portalToken->expires_at && $portalToken->expires_at->isPast()) {
            return false;
        }

        $idleTimeoutMinutes = (int) config('customer_portal.idle_timeout_minutes', 60);
        if ($idleTimeoutMinutes > 0
            && $portalToken->last_accessed_at
            && $portalToken->last_accessed_at->copy()->addMinutes($idleTimeoutMinutes)->isPast()) {
            return false;
        }

        return true;
    }

    public function portalUrl(CustomerPortalToken $token): string
    {
        return route('customer.portal.show', $token->token);
    }
}
