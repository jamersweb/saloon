<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\BookingRule;
use App\Models\LeaveRequest;
use App\Models\StaffProfile;
use App\Models\StaffSchedule;
use Carbon\Carbon;

class BookingAvailabilityService
{
    public function validateAdvanceWindow(Carbon $start): ?string
    {
        $rules = BookingRule::current();

        if ($start->lt(now()->addMinutes((int) $rules->min_advance_minutes))) {
            return 'Selected slot is too soon based on booking policy.';
        }

        if ($start->gt(now()->addDays((int) $rules->max_advance_days))) {
            return 'Selected slot exceeds advance booking window.';
        }

        if ((int) $rules->slot_interval_minutes > 0 && ((int) $start->format('i')) % (int) $rules->slot_interval_minutes !== 0) {
            return 'Selected slot does not match slot interval policy.';
        }

        return null;
    }

    public function validateStaffAvailability(int $staffProfileId, Carbon $start, Carbon $end, ?int $ignoreAppointmentId = null): ?string
    {
        $staff = StaffProfile::query()->find($staffProfileId);
        if (! $staff || ! $staff->is_active) {
            return 'Selected staff is not active.';
        }

        $schedule = StaffSchedule::query()
            ->where('staff_profile_id', $staffProfileId)
            ->whereDate('schedule_date', $start->toDateString())
            ->first();

        if (! $schedule || $schedule->is_day_off || ! $schedule->start_time || ! $schedule->end_time) {
            return 'Selected staff is not scheduled for that time.';
        }

        $shiftStart = Carbon::parse($start->toDateString() . ' ' . $schedule->start_time);
        $shiftEnd = Carbon::parse($start->toDateString() . ' ' . $schedule->end_time);
        if ($start->lt($shiftStart) || $end->gt($shiftEnd)) {
            return 'Selected time is outside staff shift.';
        }

        if ($schedule->break_start && $schedule->break_end) {
            $breakStart = Carbon::parse($start->toDateString() . ' ' . $schedule->break_start);
            $breakEnd = Carbon::parse($start->toDateString() . ' ' . $schedule->break_end);
            if ($start->lt($breakEnd) && $end->gt($breakStart)) {
                return 'Selected time overlaps staff break.';
            }
        }

        $onLeave = LeaveRequest::query()
            ->where('staff_profile_id', $staffProfileId)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $start->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->exists();

        if ($onLeave) {
            return 'Selected staff is on approved leave.';
        }

        $hasConflict = Appointment::query()
            ->where('staff_profile_id', $staffProfileId)
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW])
            ->when($ignoreAppointmentId, fn ($query) => $query->where('id', '!=', $ignoreAppointmentId))
            ->where(function ($query) use ($start, $end) {
                $query->whereBetween('scheduled_start', [$start, $end])
                    ->orWhereBetween('scheduled_end', [$start, $end])
                    ->orWhere(function ($nested) use ($start, $end) {
                        $nested->where('scheduled_start', '<=', $start)
                            ->where('scheduled_end', '>=', $end);
                    });
            })
            ->exists();

        return $hasConflict ? 'Selected staff already has a conflicting appointment.' : null;
    }

    public function findAnyAvailableStaffId(Carbon $start, Carbon $end): ?int
    {
        $staffIds = StaffProfile::query()
            ->where('is_active', true)
            ->pluck('id');

        foreach ($staffIds as $staffId) {
            if (! $this->validateStaffAvailability((int) $staffId, $start, $end)) {
                return (int) $staffId;
            }
        }

        return null;
    }
}

