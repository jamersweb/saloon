<?php

namespace App\Services;

use App\Models\Appointment;
use Illuminate\Database\Eloquent\Collection;

class AppointmentVisitService
{
    /**
     * @return Collection<int, Appointment>
     */
    public function forAppointment(Appointment $appointment): Collection
    {
        if (! $appointment->exists) {
            return new Collection([$appointment]);
        }

        if ($appointment->visit_id) {
            return Appointment::query()
                ->where('visit_id', $appointment->visit_id)
                ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW])
                ->orderBy('scheduled_start')
                ->orderBy('id')
                ->get();
        }

        if (! $appointment->created_at) {
            return new Collection([$appointment]);
        }

        $query = Appointment::query()
            ->where('id', '!=', $appointment->id)
            ->where('source', $appointment->source)
            ->where('booked_by', $appointment->booked_by)
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW])
            ->whereBetween('created_at', [
                $appointment->created_at->copy()->startOfSecond(),
                $appointment->created_at->copy()->endOfSecond(),
            ]);

        if ($appointment->customer_id) {
            $query->where('customer_id', $appointment->customer_id);
        } else {
            $query->whereNull('customer_id')
                ->where('customer_name', $appointment->customer_name)
                ->where('customer_phone', $appointment->customer_phone);
        }

        return (new Collection([$appointment]))
            ->merge($query->get())
            ->sortBy([
                ['scheduled_start', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
    }
}
