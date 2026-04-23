<?php

namespace App\Http\Controllers;

use App\Models\AttendanceLog;
use App\Models\StaffProfile;
use App\Models\StaffSchedule;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceLogController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $isStaff = $user?->hasRole('staff');
        $staffProfileId = $user?->staffProfile?->id;
        $today = now()->toDateString();

        $logsQuery = AttendanceLog::query()
            ->with('staffProfile.user')
            ->latest('attendance_date')
            ->limit(100);

        $staffProfilesQuery = StaffProfile::query()
            ->with('user')
            ->where('is_active', true)
            ->orderBy('employee_code');

        if ($isStaff) {
            $logsQuery->where('staff_profile_id', $staffProfileId ?: 0);
            $staffProfilesQuery->whereKey($staffProfileId ?: 0);
        }

        $todayLog = null;

        if ($staffProfileId) {
            $todayLog = AttendanceLog::query()
                ->where('staff_profile_id', $staffProfileId)
                ->where('attendance_date', $today)
                ->latest('id')
                ->first();
        }

        return Inertia::render('Attendance/Index', [
            'todayLog' => $todayLog ? [
                'id' => $todayLog->id,
                'attendance_date' => $todayLog->attendance_date,
                'clock_in' => $todayLog->clock_in,
                'clock_out' => $todayLog->clock_out,
            ] : null,
            'appTimezone' => config('app.timezone'),
            'logs' => $logsQuery->get()->map(fn (AttendanceLog $log) => [
                'id' => $log->id,
                'attendance_date' => $log->attendance_date,
                'clock_in' => $log->clock_in,
                'clock_in_latitude' => $log->clock_in_latitude,
                'clock_in_longitude' => $log->clock_in_longitude,
                'clock_in_location_url' => $log->clock_in_latitude !== null && $log->clock_in_longitude !== null
                    ? sprintf('https://www.google.com/maps?q=%s,%s', $log->clock_in_latitude, $log->clock_in_longitude)
                    : null,
                'clock_out' => $log->clock_out,
                'late_minutes' => $log->late_minutes,
                'staff_name' => $log->staffProfile?->user?->name,
            ]),
            'staffProfiles' => $staffProfilesQuery->get()->map(fn (StaffProfile $staff) => [
                'id' => $staff->id,
                'name' => $staff->user?->name,
            ]),
        ]);
    }

    public function clockIn(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $request->validate([
            'clock_in_latitude' => ['required', 'numeric', 'between:-90,90'],
            'clock_in_longitude' => ['required', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string'],
        ]);

        $staffProfile = $this->resolveStaffProfile($request);

        if (! $staffProfile) {
            return back()->withErrors(['staff_profile_id' => 'No staff profile found.']);
        }

        $today = now()->toDateString();
        $clockInTime = now()->format('H:i:s');
        $schedule = StaffSchedule::query()
            ->where('staff_profile_id', $staffProfile->id)
            ->whereDate('schedule_date', $today)
            ->first();

        $lateMinutes = 0;

        if ($schedule && $schedule->start_time) {
            $scheduled = Carbon::parse($today.' '.$schedule->start_time);
            $actual = Carbon::parse($today.' '.$clockInTime);
            $lateMinutes = max(0, $actual->diffInMinutes($scheduled, false));
        }

        $log = AttendanceLog::updateOrCreate(
            [
                'staff_profile_id' => $staffProfile->id,
                'attendance_date' => $today,
            ],
            [
                'scheduled_start' => $schedule?->start_time,
                'clock_in' => $clockInTime,
                'clock_in_latitude' => (float) $data['clock_in_latitude'],
                'clock_in_longitude' => (float) $data['clock_in_longitude'],
                'late_minutes' => $lateMinutes,
                'notes' => $data['notes'] ?? null,
            ],
        );

        Audit::log($request->user()->id, 'attendance.clock_in', 'AttendanceLog', $log->id, ['late_minutes' => $lateMinutes]);

        return back()->with('status', 'Clock in recorded.');
    }

    public function clockOut(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $staffProfile = $this->resolveStaffProfile($request);

        if (! $staffProfile) {
            return back()->withErrors(['staff_profile_id' => 'No staff profile found.']);
        }

        $today = now()->toDateString();

        $log = AttendanceLog::query()->firstOrCreate(
            [
                'staff_profile_id' => $staffProfile->id,
                'attendance_date' => $today,
            ],
        );

        $log->update([
            'clock_out' => now()->format('H:i:s'),
            'notes' => $request->input('notes', $log->notes),
        ]);

        Audit::log($request->user()->id, 'attendance.clock_out', 'AttendanceLog', $log->id);

        return back()->with('status', 'Clock out recorded.');
    }

    private function resolveStaffProfile(Request $request): ?StaffProfile
    {
        $user = $request->user();

        if ($user?->hasRole('staff')) {
            return $user->staffProfile;
        }

        if ($request->filled('staff_profile_id')) {
            return StaffProfile::find($request->integer('staff_profile_id'));
        }

        return $user?->staffProfile;
    }
}
