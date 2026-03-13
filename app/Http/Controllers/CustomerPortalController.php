<?php

namespace App\Http\Controllers;

use App\Services\CustomerPortalService;
use Inertia\Inertia;
use Inertia\Response;

class CustomerPortalController extends Controller
{
    public function show(string $token, CustomerPortalService $customerPortalService): Response
    {
        $portalToken = $customerPortalService->resolveActiveToken($token);

        abort_unless($portalToken, 404);

        $customer = $portalToken->customer;
        $currentCard = $customer->membershipCards->firstWhere('status', 'active') ?? $customer->membershipCards->first();
        $appointments = $customer->appointments
            ->sortByDesc('scheduled_start')
            ->take(20)
            ->values();

        return Inertia::render('Public/CustomerPortal', [
            'customer' => [
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'points' => $customer->loyaltyAccount?->current_points ?? 0,
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
            ],
        ]);
    }
}
