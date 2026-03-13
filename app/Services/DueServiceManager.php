<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\CustomerDueService;
use Carbon\Carbon;

class DueServiceManager
{
    public function syncForAppointment(Appointment $appointment): ?CustomerDueService
    {
        $appointment->loadMissing('service:id,repeat_after_days');

        if ($appointment->status !== Appointment::STATUS_COMPLETED || ! $appointment->customer_id) {
            return null;
        }

        $repeatAfter = (int) ($appointment->service?->repeat_after_days ?? 0);
        if ($repeatAfter <= 0) {
            return null;
        }

        $dueDate = Carbon::parse($appointment->scheduled_end ?? $appointment->scheduled_start)
            ->addDays($repeatAfter)
            ->toDateString();

        return CustomerDueService::query()->updateOrCreate(
            [
                'customer_id' => $appointment->customer_id,
                'salon_service_id' => $appointment->service_id,
                'due_date' => $dueDate,
            ],
            [
                'last_appointment_id' => $appointment->id,
                'status' => 'pending',
            ],
        );
    }

    public function backfillCompletedAppointments(?int $limit = null): int
    {
        $processed = 0;

        Appointment::query()
            ->with('service:id,repeat_after_days')
            ->where('status', Appointment::STATUS_COMPLETED)
            ->whereNotNull('customer_id')
            ->when($limit, fn ($query) => $query->limit($limit))
            ->orderBy('id')
            ->chunkById(200, function ($appointments) use (&$processed): void {
                foreach ($appointments as $appointment) {
                    if ($this->syncForAppointment($appointment)) {
                        $processed++;
                    }
                }
            });

        return $processed;
    }
}
