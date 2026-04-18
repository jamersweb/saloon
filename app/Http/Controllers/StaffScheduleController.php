<?php

namespace App\Http\Controllers;

use App\Models\BookingRule;
use App\Models\StaffProfile;
use App\Models\StaffSchedule;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffScheduleController extends Controller
{
    public function index(Request $request): Response
    {
        $rules = BookingRule::current();

        return Inertia::render('Schedules/Index', [
            'defaultShiftStart' => $rules->defaultShiftStart(),
            'defaultShiftEnd' => $rules->defaultShiftEnd(),
            'salonHoursLabel' => $rules->defaultShiftStart().'–'.$rules->defaultShiftEnd(),
            'staffProfiles' => StaffProfile::query()->with('user')->where('is_active', true)->orderBy('employee_code')->get()->map(fn (StaffProfile $staff) => [
                'id' => $staff->id,
                'employee_code' => $staff->employee_code,
                'name' => $staff->user?->name,
            ]),
            'schedules' => StaffSchedule::query()->with('staffProfile.user')->orderByDesc('schedule_date')->limit(100)->get()->map(fn (StaffSchedule $schedule) => [
                'id' => $schedule->id,
                'staff_profile_id' => $schedule->staff_profile_id,
                'schedule_date' => $schedule->schedule_date,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'break_start' => $schedule->break_start,
                'break_end' => $schedule->break_end,
                'is_day_off' => $schedule->is_day_off,
                'notes' => $schedule->notes,
                'staff_name' => $schedule->staffProfile?->user?->name,
                'staff_code' => $schedule->staffProfile?->employee_code,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'staff_profile_id' => ['required', 'exists:staff_profiles,id'],
            'schedule_date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'break_start' => ['nullable', 'date_format:H:i'],
            'break_end' => ['nullable', 'date_format:H:i'],
            'is_day_off' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        if (! ($data['is_day_off'] ?? false) && ! empty($data['start_time']) && ! empty($data['end_time'])) {
            if ($message = $this->shiftSalonHoursError($data['schedule_date'], $data['start_time'], $data['end_time'])) {
                return back()->withErrors(['start_time' => $message])->withInput();
            }
        }

        $schedule = StaffSchedule::updateOrCreate(
            [
                'staff_profile_id' => $data['staff_profile_id'],
                'schedule_date' => $data['schedule_date'],
            ],
            [
                'start_time' => $data['start_time'] ?? null,
                'end_time' => $data['end_time'] ?? null,
                'break_start' => $data['break_start'] ?? null,
                'break_end' => $data['break_end'] ?? null,
                'is_day_off' => (bool) ($data['is_day_off'] ?? false),
                'notes' => $data['notes'] ?? null,
            ],
        );

        Audit::log($request->user()->id, 'schedule.upserted', 'StaffSchedule', $schedule->id, $schedule->toArray());

        return back()->with('status', 'Schedule saved.');
    }

    public function update(Request $request, StaffSchedule $schedule): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'break_start' => ['nullable', 'date_format:H:i'],
            'break_end' => ['nullable', 'date_format:H:i'],
            'is_day_off' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        if (! ($data['is_day_off'] ?? false) && ! empty($data['start_time']) && ! empty($data['end_time'])) {
            if ($message = $this->shiftSalonHoursError($schedule->schedule_date->toDateString(), $data['start_time'], $data['end_time'])) {
                return back()->withErrors(['start_time' => $message])->withInput();
            }
        }

        $schedule->update([
            'start_time' => $data['start_time'] ?? null,
            'end_time' => $data['end_time'] ?? null,
            'break_start' => $data['break_start'] ?? null,
            'break_end' => $data['break_end'] ?? null,
            'is_day_off' => (bool) ($data['is_day_off'] ?? false),
            'notes' => $data['notes'] ?? null,
        ]);

        Audit::log($request->user()->id, 'schedule.updated', 'StaffSchedule', $schedule->id);

        return back()->with('status', 'Schedule updated.');
    }

    public function destroy(Request $request, StaffSchedule $schedule): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $id = $schedule->id;
        $schedule->delete();

        Audit::log($request->user()->id, 'schedule.deleted', 'StaffSchedule', $id);

        return back()->with('status', 'Schedule removed.');
    }

    private function shiftSalonHoursError(string $scheduleDate, string $start, string $end): ?string
    {
        $rules = BookingRule::current();
        $day = Carbon::parse($scheduleDate);
        $shiftStart = Carbon::parse($scheduleDate.' '.$start);
        $shiftEnd = Carbon::parse($scheduleDate.' '.$end);

        if ($shiftEnd->lessThanOrEqualTo($shiftStart)) {
            return 'Shift end must be after shift start.';
        }

        if ($shiftStart->lt($rules->salonOpenOn($day))) {
            return 'Shift starts before salon opening hours.';
        }

        if ($shiftEnd->gt($rules->salonCloseOn($day))) {
            return 'Shift ends after salon closing hours.';
        }

        return null;
    }
}
