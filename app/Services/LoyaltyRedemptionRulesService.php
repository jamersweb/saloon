<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use Illuminate\Validation\ValidationException;

class LoyaltyRedemptionRulesService
{
    public const GLOBAL_MAX_UNITS_PER_REQUEST = 20;

    public function assertCanRedeem(int $customerId, LoyaltyReward $reward, int $quantity, ?int $appointmentId = null): void
    {
        if ($quantity < 1) {
            throw ValidationException::withMessages([
                'quantity' => 'Quantity must be at least 1.',
            ]);
        }

        $perRequestCap = $reward->max_units_per_redemption ?? self::GLOBAL_MAX_UNITS_PER_REQUEST;
        $perRequestCap = min(self::GLOBAL_MAX_UNITS_PER_REQUEST, max(1, (int) $perRequestCap));

        if ($quantity > $perRequestCap) {
            throw ValidationException::withMessages([
                'quantity' => "This reward allows at most {$perRequestCap} unit(s) per redemption.",
            ]);
        }

        if ($reward->max_redemptions_per_calendar_month !== null) {
            $limit = (int) $reward->max_redemptions_per_calendar_month;
            $start = now()->startOfMonth();
            $end = now()->endOfMonth();

            $usedThisMonth = (int) LoyaltyRedemption::query()
                ->where('customer_id', $customerId)
                ->where('loyalty_reward_id', $reward->id)
                ->where('status', 'redeemed')
                ->whereBetween('created_at', [$start, $end])
                ->sum('quantity');

            if ($usedThisMonth + $quantity > $limit) {
                $remaining = max(0, $limit - $usedThisMonth);
                throw ValidationException::withMessages([
                    'quantity' => "Monthly limit for this reward is {$limit} ({$remaining} remaining this calendar month).",
                ]);
            }
        }

        if ($reward->min_days_between_redemptions !== null) {
            $minDays = (int) $reward->min_days_between_redemptions;
            $last = LoyaltyRedemption::query()
                ->where('customer_id', $customerId)
                ->where('loyalty_reward_id', $reward->id)
                ->where('status', 'redeemed')
                ->orderByDesc('created_at')
                ->first();

            if ($last !== null) {
                $eligibleAt = $last->created_at->copy()->addDays($minDays);
                if (now()->lt($eligibleAt)) {
                    throw ValidationException::withMessages([
                        'loyalty_reward_id' => 'Minimum gap between redemptions of this reward has not passed yet (next eligible: '.$eligibleAt->timezone(config('app.timezone'))->toDateTimeString().').',
                    ]);
                }
            }
        }

        $reward->loadMissing('allowedSalonServices:id,name');
        $allowedServiceIds = $reward->allowedSalonServices
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $visitRequired = $reward->requires_appointment_id || $allowedServiceIds !== [];

        $appointment = null;
        if ($appointmentId !== null) {
            $appointment = Appointment::query()->find($appointmentId);
            if ($appointment === null || (int) $appointment->customer_id !== $customerId) {
                throw ValidationException::withMessages([
                    'appointment_id' => 'The appointment must belong to the selected customer.',
                ]);
            }
        }

        if ($visitRequired && $appointmentId === null) {
            throw ValidationException::withMessages([
                'appointment_id' => $allowedServiceIds !== [] && ! $reward->requires_appointment_id
                    ? 'Select a visit that uses an eligible service for this reward.'
                    : 'This reward must be linked to a visit (select an appointment).',
            ]);
        }

        if ($visitRequired && $appointment !== null) {
            $already = LoyaltyRedemption::query()
                ->where('appointment_id', $appointmentId)
                ->where('loyalty_reward_id', $reward->id)
                ->where('status', 'redeemed')
                ->exists();

            if ($already) {
                throw ValidationException::withMessages([
                    'appointment_id' => 'This reward has already been redeemed for this visit.',
                ]);
            }
        }

        if ($allowedServiceIds !== [] && $appointment !== null) {
            if (! in_array((int) $appointment->service_id, $allowedServiceIds, true)) {
                $names = $reward->allowedSalonServices->pluck('name')->filter()->implode(', ');
                throw ValidationException::withMessages([
                    'appointment_id' => $names !== ''
                        ? 'This reward is only valid for visits booked as: '.$names.'.'
                        : 'The selected visit does not use a service eligible for this reward.',
                ]);
            }
        }
    }
}
