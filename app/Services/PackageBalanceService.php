<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\CustomerPackageUsage;
use App\Models\ServicePackage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PackageBalanceService
{
    public function assignPackage(Customer $customer, ServicePackage $package, ?int $assignedBy = null, ?string $notes = null): CustomerPackage
    {
        $expiresAt = $package->validity_days ? now()->addDays((int) $package->validity_days) : null;

        return CustomerPackage::create([
            'customer_id' => $customer->id,
            'service_package_id' => $package->id,
            'remaining_sessions' => $package->usage_limit,
            'remaining_value' => $package->initial_value,
            'expires_at' => $expiresAt,
            'status' => 'active',
            'assigned_by' => $assignedBy,
            'notes' => $notes,
        ]);
    }

    public function consume(CustomerPackage $customerPackage, int $sessionsUsed, float $valueUsed, ?int $recordedBy = null, ?string $notes = null): CustomerPackageUsage
    {
        return DB::transaction(function () use ($customerPackage, $sessionsUsed, $valueUsed, $recordedBy, $notes) {
            $customerPackage->refresh();

            $remainingSessions = $customerPackage->remaining_sessions;
            $remainingValue = $customerPackage->remaining_value;

            if ($remainingSessions !== null && $sessionsUsed > $remainingSessions) {
                throw ValidationException::withMessages(['sessions_used' => 'Package does not have enough sessions remaining.']);
            }

            if ($remainingValue !== null && $valueUsed > (float) $remainingValue) {
                throw ValidationException::withMessages(['value_used' => 'Package does not have enough value remaining.']);
            }

            $nextSessions = $remainingSessions !== null ? $remainingSessions - $sessionsUsed : null;
            $nextValue = $remainingValue !== null ? round((float) $remainingValue - $valueUsed, 2) : null;

            $status = 'active';
            if (($nextSessions !== null && $nextSessions <= 0) || ($nextValue !== null && $nextValue <= 0)) {
                $status = 'completed';
            }

            $customerPackage->update([
                'remaining_sessions' => $nextSessions,
                'remaining_value' => $nextValue,
                'status' => $status,
            ]);

            return CustomerPackageUsage::create([
                'customer_package_id' => $customerPackage->id,
                'sessions_used' => $sessionsUsed,
                'value_used' => $valueUsed,
                'remaining_sessions_after' => $nextSessions,
                'remaining_value_after' => $nextValue,
                'notes' => $notes,
                'recorded_by' => $recordedBy,
            ]);
        });
    }
}
