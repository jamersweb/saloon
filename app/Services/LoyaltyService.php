<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\CustomerLoyaltyAccount;
use App\Models\CustomerLoyaltyLedger;
use App\Models\LoyaltyProgramSetting;
use App\Models\LoyaltyTier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoyaltyService
{
    public function applyPoints(
        int $customerId,
        int $pointsChange,
        string $reason,
        ?string $reference = null,
        ?int $createdBy = null,
        ?string $notes = null
    ): CustomerLoyaltyLedger {
        if ($pointsChange === 0) {
            throw ValidationException::withMessages([
                'points_change' => 'Points change cannot be zero.',
            ]);
        }

        return DB::transaction(function () use ($customerId, $pointsChange, $reason, $reference, $createdBy, $notes): CustomerLoyaltyLedger {
            $account = CustomerLoyaltyAccount::query()
                ->where('customer_id', $customerId)
                ->lockForUpdate()
                ->first();

            if (! $account) {
                $account = CustomerLoyaltyAccount::create([
                    'customer_id' => $customerId,
                    'current_points' => 0,
                ]);
            }

            $nextBalance = $account->current_points + $pointsChange;
            if ($nextBalance < 0) {
                throw ValidationException::withMessages([
                    'points_change' => 'Points cannot go below zero.',
                ]);
            }

            $tier = LoyaltyTier::query()
                ->where('is_active', true)
                ->where('min_points', '<=', $nextBalance)
                ->orderByDesc('min_points')
                ->first();

            $account->update([
                'current_points' => $nextBalance,
                'loyalty_tier_id' => $tier?->id,
                'last_activity_at' => now(),
            ]);

            return CustomerLoyaltyLedger::create([
                'customer_id' => $customerId,
                'loyalty_tier_id' => $tier?->id,
                'points_change' => $pointsChange,
                'balance_after' => $nextBalance,
                'reason' => $reason,
                'reference' => $reference,
                'notes' => $notes,
                'created_by' => $createdBy,
            ]);
        });
    }

    public function earnFromCompletedAppointment(Appointment $appointment, ?int $createdBy = null): bool
    {
        if ($appointment->status !== Appointment::STATUS_COMPLETED || ! $appointment->customer_id) {
            return false;
        }

        $settings = LoyaltyProgramSetting::current();
        if (! $settings->auto_earn_enabled) {
            return false;
        }

        $appointment->loadMissing(['service:id,name,price', 'customer:id,birthday']);

        $spend = (float) ($appointment->service?->price ?? 0);
        if ($spend < (float) $settings->minimum_spend) {
            return false;
        }

        $currentAccount = CustomerLoyaltyAccount::query()
            ->where('customer_id', $appointment->customer_id)
            ->with('tier:id,earn_multiplier')
            ->first();
        $multiplier = (float) ($currentAccount?->tier?->earn_multiplier ?? 1);

        $rawPoints = ($spend * (float) $settings->points_per_currency * $multiplier) + (int) $settings->points_per_visit;
        $points = match ($settings->rounding_mode) {
            'ceil' => (int) ceil($rawPoints),
            'round' => (int) round($rawPoints),
            default => (int) floor($rawPoints),
        };

        if ($appointment->customer?->birthday && $appointment->scheduled_start) {
            $birthday = $appointment->customer->birthday;
            if ($birthday->month === $appointment->scheduled_start->month && $birthday->day === $appointment->scheduled_start->day) {
                $points += (int) $settings->birthday_bonus_points;
            }
        }

        if ($points <= 0) {
            return false;
        }

        $reference = 'APPOINTMENT-' . $appointment->id;
        $alreadyAwarded = CustomerLoyaltyLedger::query()
            ->where('customer_id', $appointment->customer_id)
            ->where('reason', 'Appointment completed')
            ->where('reference', $reference)
            ->exists();

        if ($alreadyAwarded) {
            return false;
        }

        $this->applyPoints(
            customerId: (int) $appointment->customer_id,
            pointsChange: $points,
            reason: 'Appointment completed',
            reference: $reference,
            createdBy: $createdBy,
            notes: 'Auto-earned from completed appointment'
        );

        return true;
    }

    public function awardConfiguredBonus(int $customerId, string $bonusType, ?int $createdBy = null): bool
    {
        $settings = LoyaltyProgramSetting::current();

        $points = match ($bonusType) {
            'referral' => (int) $settings->referral_bonus_points,
            'review' => (int) $settings->review_bonus_points,
            'birthday' => (int) $settings->birthday_bonus_points,
            default => 0,
        };

        if ($points <= 0) {
            return false;
        }

        $this->applyPoints(
            customerId: $customerId,
            pointsChange: $points,
            reason: ucfirst($bonusType) . ' bonus',
            reference: strtoupper($bonusType) . '-BONUS',
            createdBy: $createdBy,
            notes: 'Configured bonus payout'
        );

        return true;
    }
}
