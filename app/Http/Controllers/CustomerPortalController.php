<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerLoyaltyLedger;
use App\Models\CustomerMembershipCard;
use App\Models\Appointment;
use App\Services\CustomerPortalService;
use Inertia\Inertia;
use Inertia\Response;

class CustomerPortalController extends Controller
{
    public function show(string $token, CustomerPortalService $customerPortalService): Response
    {
        $portalToken = $customerPortalService->resolveActiveToken($token);

        abort_unless($portalToken, 404);

        return Inertia::render('Public/CustomerPortal', [
            'customer' => $this->buildPortalCustomerPayload($portalToken->customer),
        ]);
    }

    public function showByNfc(string $nfcUid): Response
    {
        $normalizedUid = strtoupper(trim($nfcUid));

        abort_if($normalizedUid === '', 404);

        $card = CustomerMembershipCard::query()
            ->with([
                'customer.loyaltyAccount.tier',
                'customer.membershipCards.type',
                'customer.packages.package',
                'customer.giftCards',
                'customer.appointments.service',
                'customer.appointments.staffProfile.user',
            ])
            ->where('nfc_uid', $normalizedUid)
            ->first();

        abort_unless($card?->customer, 404);

        return Inertia::render('Public/CustomerPortal', [
            'customer' => $this->buildPortalCustomerPayload($card->customer),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPortalCustomerPayload(Customer $customer): array
    {
        $currentCard = $customer->membershipCards->firstWhere('status', 'active') ?? $customer->membershipCards->first();
        $appointments = $customer->appointments
            ->filter(fn ($appointment) => in_array($appointment->status, [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_IN_PROGRESS,
            ], true))
            ->sortByDesc('scheduled_start')
            ->take(20)
            ->values();

        $pointsRemaining = (int) ($customer->loyaltyAccount?->current_points ?? 0);
        $pointsSpent = (int) abs((int) CustomerLoyaltyLedger::query()
            ->where('customer_id', $customer->id)
            ->where('points_change', '<', 0)
            ->sum('points_change'));

        return [
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'points' => $pointsRemaining,
            'points_remaining' => $pointsRemaining,
            'points_spent' => $pointsSpent,
            'tier' => $customer->loyaltyAccount?->tier?->name,
            'current_card' => $currentCard?->type?->name,
            'card_status' => $currentCard?->status,
            'card_expires_at' => $currentCard?->expires_at,
            'packages' => $customer->packages->map(fn ($package) => [
                'name' => $package->package?->name,
                'remaining_sessions' => $package->remaining_sessions,
                'remaining_value' => $package->remaining_value,
                'status' => $package->status,
                'expires_at' => $package->expires_at,
            ])->values(),
            'gift_cards' => $customer->giftCards->map(fn ($giftCard) => [
                'code' => $giftCard->code,
                'remaining_value' => $giftCard->remaining_value,
                'status' => $giftCard->status,
                'expires_at' => $giftCard->expires_at,
            ])->values(),
            'service_history' => $appointments->map(fn ($appointment) => [
                'id' => $appointment->id,
                'scheduled_start' => $appointment->scheduled_start,
                'service_name' => $appointment->service?->name,
                'staff_name' => $appointment->staffProfile?->user?->name,
                'status' => $appointment->status,
                'notes' => $appointment->notes,
            ]),
        ];
    }
}
