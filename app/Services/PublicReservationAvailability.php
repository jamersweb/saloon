<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentBlock;
use App\Models\BookingRule;
use App\Models\LeaveRequest;
use App\Models\PublicReservation;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\StaffSchedule;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/** Read the salon catalogue/calendar; never mutate portal appointments or schedules. */
class PublicReservationAvailability
{
    public function services(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $services = SalonService::where('is_active', true)->whereIn('id', $ids)->get()->keyBy('id');
        if (! $ids || count($ids) !== $services->count()) {
            throw ValidationException::withMessages(['service_ids' => 'Please select available services.']);
        }

        return array_map(function ($id) use ($services) {
            $s = $services[$id];
            if ($s->duration_minutes < 1 || $s->price <= 0) {
                throw ValidationException::withMessages(['service_ids' => 'Please contact the salon to book this service.']);
            }

            return ['id' => $s->id, 'name' => $s->name, 'price_minor' => (int) round((float) $s->price * 100),
                'duration_minutes' => (int) $s->duration_minutes, 'buffer_minutes' => max(0, (int) $s->buffer_minutes)];
        }, $ids);
    }

    public function duration(array $services): int
    {
        return array_sum(array_map(fn ($s) => $s['duration_minutes'] + $s['buffer_minutes'], $services));
    }

    public function available(int $staffId, Carbon $start, int $minutes, ?int $ignore = null, bool $enforceAdvance = true): bool
    {
        $end = $start->copy()->addMinutes($minutes);
        $helper = app(BookingAvailabilityService::class);
        if ($start->isPast() || ($enforceAdvance && $helper->validateAdvanceWindow($start, false)) || $helper->validateSalonHours($start, $end)
            || $helper->validateStaffAvailability($staffId, $start, $end)) {
            return false;
        }
        if (! StaffProfile::whereKey($staffId)->where('is_active', true)->bookableOnline()->exists()) {
            return false;
        }
        $schedule = StaffSchedule::where('staff_profile_id', $staffId)->whereDate('schedule_date', $start->toDateString())->first();
        if (! $schedule || ! $schedule->end_time || $end->gt(Carbon::parse($start->toDateString().' '.$schedule->end_time))) {
            return false;
        }
        if (Appointment::where('staff_profile_id', $staffId)->whereNotIn('status', ['cancelled', 'no_show'])
            ->where('scheduled_start', '<', $end)->where('scheduled_end', '>', $start)->exists()) {
            return false;
        }

        return ! PublicReservation::where('staff_profile_id', $staffId)
            ->whereIn('status', ['awaiting_payment', 'confirmed', 'payment_review'])
            ->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists();
    }

    public function slots(string $date, int $minutes, ?int $ignore = null): array
    {
        $rules = BookingRule::current();
        $day = Carbon::parse($date)->startOfDay();
        if ($day->lt(today()) || $day->gt(today()->addDays($rules->max_advance_days))) {
            return [];
        }
        $open = $rules->salonOpenOn($day);
        $close = $rules->salonCloseOn($day);
        $earliest = now()->addMinutes($rules->min_advance_minutes);
        $latest = now()->addDays($rules->max_advance_days);
        // Load intervals once per day; checking each candidate must not issue database queries.
        $schedules = StaffSchedule::whereDate('schedule_date', $date)->get()->keyBy('staff_profile_id');
        $leave = LeaveRequest::where('status', 'approved')->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->pluck('staff_profile_id')->all();
        $appointments = Appointment::whereNotIn('status', ['cancelled', 'no_show'])->where('scheduled_start', '<', $close)->where('scheduled_end', '>', $open)->get()->groupBy('staff_profile_id');
        $reservations = PublicReservation::whereIn('status', ['awaiting_payment', 'confirmed', 'payment_review'])
            ->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->where('starts_at', '<', $close)->where('ends_at', '>', $open)->get()->groupBy('staff_profile_id');
        $blocks = AppointmentBlock::where('starts_at', '<', $close)->where('ends_at', '>', $open)->get();
        $slots = [];
        foreach (StaffProfile::where('is_active', true)->bookableOnline()->orderBy('id')->pluck('id') as $staffId) {
            $schedule = $schedules->get($staffId);
            if (! $schedule || $schedule->is_day_off || ! $schedule->start_time || ! $schedule->end_time || in_array($staffId, $leave)) {
                continue;
            }
            $anchor = max($open, Carbon::parse($date.' '.$schedule->start_time));
            $shiftEnd = min($close, Carbon::parse($date.' '.$schedule->end_time));
            $busy = [];
            foreach ($appointments->get($staffId, collect()) as $appointment) {
                $busy[] = [$appointment->scheduled_start, $appointment->scheduled_end];
            }
            foreach ($reservations->get($staffId, collect()) as $reservation) {
                $busy[] = [$reservation->starts_at, $reservation->ends_at];
            }
            foreach ($blocks as $block) {
                if (! $block->staff_profile_id || $block->staff_profile_id == $staffId) {
                    $busy[] = [Carbon::parse($block->starts_at), Carbon::parse($block->ends_at)];
                }
            }
            if ($schedule->break_start && $schedule->break_end) {
                $breakStart = Carbon::parse($date.' '.$schedule->break_start);
                $breakEnd = Carbon::parse($date.' '.$schedule->break_end);
                if ($breakEnd->lte($breakStart)) {
                    $breakEnd->addDay();
                }
                $busy[] = [$breakStart, $breakEnd];
            }
            $preferred = [$anchor->format('Y-m-d H:i:s')];
            foreach ($busy as [$busyStart, $busyEnd]) {
                $preferred[] = $busyEnd->format('Y-m-d H:i:s');
            }
            $candidates = $preferred;
            for ($cursor = $anchor->copy(); $cursor->lt($close); $cursor->addMinutes(max(5, (int) $rules->slot_interval_minutes))) {
                $candidates[] = $cursor->format('Y-m-d H:i:s');
            }
            foreach (array_unique($candidates) as $value) {
                $start = Carbon::parse($value);
                $end = $start->copy()->addMinutes($minutes);
                if ($start->lt($anchor) || $start->lt($earliest) || $start->gt($latest) || $end->gt($shiftEnd)) {
                    continue;
                }
                foreach ($busy as [$busyStart, $busyEnd]) {
                    if ($start->lt($busyEnd) && $end->gt($busyStart)) {
                        continue 2;
                    }
                }
                $key = $start->format('Y-m-d\TH:i');
                $preferredSlot = in_array($value, $preferred, true);
                if (! isset($slots[$key]) || $preferredSlot) {
                    $slots[$key] = ['start' => $key, 'staff_id' => $staffId,
                        'label' => $start->format('g:i A'), 'end_label' => $start->copy()->addMinutes($minutes)->format('g:i A'),
                        'recommended' => $preferredSlot];
                }
            }
        }
        ksort($slots);

        return array_values($slots);
    }
}
