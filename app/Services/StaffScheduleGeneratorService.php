<?php

namespace App\Services;

use App\Models\BookingRule;
use App\Models\LeaveRequest;
use App\Models\StaffProfile;
use App\Models\StaffSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

class StaffScheduleGeneratorService
{
    private const AUTO_GENERATED_NOTES = [
        'Auto-generated shift',
        'Auto-assigned monthly default shift',
        'Salon hours refreshed for monthly schedule',
    ];

    /**
     * Fill missing schedule rows for every active staff between two dates (inclusive).
     * Existing rows are left unchanged. Uses default salon shift or a day-off row when
     * the staff already has approved leave on that date.
     */
    public function fillGapsForActiveStaff(CarbonInterface $rangeStart, CarbonInterface $rangeEnd, ?array $staffProfileIds = null): int
    {
        $start = Carbon::parse($rangeStart->toDateString())->startOfDay();
        $end = Carbon::parse($rangeEnd->toDateString())->startOfDay();

        if ($end->lt($start)) {
            return 0;
        }

        $added = 0;
        $staffIds = StaffProfile::query()
            ->where('is_active', true)
            ->assignableToServices()
            ->when($staffProfileIds !== null, fn ($query) => $query->whereIn('id', $staffProfileIds))
            ->pluck('id');

        foreach ($staffIds as $staffId) {
            foreach (CarbonPeriod::create($start, $end) as $date) {
                $day = Carbon::instance($date)->toDateString();
                $exists = StaffSchedule::query()
                    ->where('staff_profile_id', $staffId)
                    ->whereDate('schedule_date', $day)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $this->createMissingRowForStaffAndDate((int) $staffId, $day);
                $added++;
            }
        }

        return $added;
    }

    /**
     * Rolling week: today through today + 6 days.
     */
    public function fillRollingWeek(): int
    {
        return $this->fillGapsForActiveStaff(Carbon::today(), Carbon::today()->copy()->addDays(6));
    }

    /**
     * Rolling month: today through today + 30 days.
     */
    public function fillRollingMonth(): int
    {
        return $this->fillGapsForActiveStaff(Carbon::today(), Carbon::today()->copy()->addDays(30));
    }

    /**
     * Create missing rows and update system-managed working shifts to the current salon hours.
     * Day-off/leave rows are kept intact, and custom rows are left alone unless they still match
     * the previously configured default shift.
     */
    public function syncDefaultShiftsForActiveStaff(
        CarbonInterface $rangeStart,
        CarbonInterface $rangeEnd,
        ?array $staffProfileIds = null,
        ?string $previousStart = null,
        ?string $previousEnd = null,
    ): int {
        $start = Carbon::parse($rangeStart->toDateString())->startOfDay();
        $end = Carbon::parse($rangeEnd->toDateString())->startOfDay();

        if ($end->lt($start)) {
            return 0;
        }

        $changed = 0;
        $rules = BookingRule::current();
        $staffIds = StaffProfile::query()
            ->where('is_active', true)
            ->assignableToServices()
            ->when($staffProfileIds !== null, fn ($query) => $query->whereIn('id', $staffProfileIds))
            ->pluck('id');

        foreach ($staffIds as $staffId) {
            foreach (CarbonPeriod::create($start, $end) as $date) {
                $day = Carbon::instance($date)->toDateString();
                $schedule = StaffSchedule::query()
                    ->where('staff_profile_id', $staffId)
                    ->whereDate('schedule_date', $day)
                    ->first();

                if (! $schedule) {
                    $this->createMissingRowForStaffAndDate((int) $staffId, $day);
                    $changed++;

                    continue;
                }

                if ($schedule->is_day_off || str_starts_with((string) $schedule->notes, 'Approved leave #')) {
                    continue;
                }

                if (! $this->canRefreshDefaultShift($schedule, $previousStart, $previousEnd)) {
                    continue;
                }

                $attributes = [
                    'start_time' => $rules->defaultShiftStart(),
                    'end_time' => $rules->defaultShiftEnd(),
                    'notes' => 'Salon hours refreshed for monthly schedule',
                ];

                if (
                    $this->clock($schedule->start_time) === $attributes['start_time']
                    && $this->clock($schedule->end_time) === $attributes['end_time']
                    && $schedule->notes === $attributes['notes']
                ) {
                    continue;
                }

                $schedule->update($attributes);
                $changed++;
            }
        }

        return $changed;
    }

    public function seedMonthForNewStaffProfile(StaffProfile $profile): void
    {
        $rules = BookingRule::current();
        $monthStart = Carbon::parse(now()->toDateString())->startOfMonth();
        $monthEnd = Carbon::parse(now()->toDateString())->endOfMonth();

        foreach (CarbonPeriod::create($monthStart, $monthEnd) as $date) {
            $day = $date->toDateString();
            StaffSchedule::updateOrCreate(
                [
                    'staff_profile_id' => $profile->id,
                    'schedule_date' => $day,
                ],
                [
                    'start_time' => $rules->defaultShiftStart(),
                    'end_time' => $rules->defaultShiftEnd(),
                    'break_start' => null,
                    'break_end' => null,
                    'is_day_off' => false,
                    'notes' => 'Auto-assigned monthly default shift',
                ],
            );
        }
    }

    /**
     * When leave is approved, mark each day in range as unavailable on the schedule calendar.
     */
    public function applyApprovedLeave(LeaveRequest $leave): void
    {
        if ($leave->status !== 'approved') {
            return;
        }

        foreach (CarbonPeriod::create($leave->start_date, $leave->end_date) as $date) {
            $day = Carbon::instance($date)->toDateString();
            $this->upsertScheduleDay((int) $leave->staff_profile_id, $day, [
                'start_time' => null,
                'end_time' => null,
                'break_start' => null,
                'break_end' => null,
                'is_day_off' => true,
                'notes' => 'Approved leave #'.$leave->id,
            ]);
        }
    }

    /**
     * When an approved leave is rejected, cancelled, or changed away from approved, remove
     * the leave-specific calendar rows and recreate defaults where still missing.
     */
    public function revokeApprovedLeaveFromCalendar(LeaveRequest $leave): void
    {
        $start = Carbon::parse($leave->start_date)->toDateString();
        $end = Carbon::parse($leave->end_date)->toDateString();

        StaffSchedule::query()
            ->where('staff_profile_id', $leave->staff_profile_id)
            ->whereDate('schedule_date', '>=', $start)
            ->whereDate('schedule_date', '<=', $end)
            ->where('notes', 'Approved leave #'.$leave->id)
            ->delete();

        foreach (CarbonPeriod::create($start, $end) as $date) {
            $day = Carbon::instance($date)->toDateString();
            if (StaffSchedule::query()
                ->where('staff_profile_id', $leave->staff_profile_id)
                ->whereDate('schedule_date', $day)
                ->exists()) {
                continue;
            }

            $this->createMissingRowForStaffAndDate($leave->staff_profile_id, $day);
        }
    }

    private function createMissingRowForStaffAndDate(int $staffProfileId, string $dateYmd): void
    {
        $leaveId = $this->firstApprovedLeaveIdCoveringDate($staffProfileId, $dateYmd);

        if ($leaveId !== null) {
            $this->upsertScheduleDay($staffProfileId, $dateYmd, [
                'start_time' => null,
                'end_time' => null,
                'break_start' => null,
                'break_end' => null,
                'is_day_off' => true,
                'notes' => 'Approved leave #'.$leaveId,
            ]);

            return;
        }

        $rules = BookingRule::current();

        $this->upsertScheduleDay($staffProfileId, $dateYmd, [
            'start_time' => $rules->defaultShiftStart(),
            'end_time' => $rules->defaultShiftEnd(),
            'break_start' => null,
            'break_end' => null,
            'is_day_off' => false,
            'notes' => 'Auto-generated shift',
        ]);
    }

    /**
     * Match by calendar date (whereDate) so SQLite/date casts do not miss rows and attempt duplicate inserts.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function upsertScheduleDay(int $staffProfileId, string $dateYmd, array $attributes): StaffSchedule
    {
        $row = StaffSchedule::query()
            ->where('staff_profile_id', $staffProfileId)
            ->whereDate('schedule_date', $dateYmd)
            ->first();

        if ($row) {
            $row->update($attributes);

            return $row->fresh();
        }

        return StaffSchedule::query()->create(array_merge([
            'staff_profile_id' => $staffProfileId,
            'schedule_date' => $dateYmd,
        ], $attributes));
    }

    private function firstApprovedLeaveIdCoveringDate(int $staffProfileId, string $dateYmd): ?int
    {
        return LeaveRequest::query()
            ->where('staff_profile_id', $staffProfileId)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $dateYmd)
            ->whereDate('end_date', '>=', $dateYmd)
            ->orderBy('id')
            ->value('id');
    }

    private function canRefreshDefaultShift(StaffSchedule $schedule, ?string $previousStart, ?string $previousEnd): bool
    {
        if (in_array($schedule->notes, self::AUTO_GENERATED_NOTES, true)) {
            return true;
        }

        return $previousStart !== null
            && $previousEnd !== null
            && $this->clock($schedule->start_time) === $this->clock($previousStart)
            && $this->clock($schedule->end_time) === $this->clock($previousEnd);
    }

    /**
     * @param  mixed  $value
     */
    private function clock($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = (string) $value;

        return strlen($raw) >= 5 ? substr($raw, 0, 5) : $raw;
    }
}
