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

        return Inertia::render('Attendance/Index', [
            'logs' => $logsQuery->get()->map(fn (AttendanceLog $log) => [
                'id' => $log->id,
                'attendance_date' => $log->attendance_date,
                'clock_in' => $log->clock_in,
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
            $scheduled = Carbon::parse($today . ' ' . $schedule->start_time);
            $actual = Carbon::parse($today . ' ' . $clockInTime);
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
                'late_minutes' => $lateMinutes,
                'notes' => $request->input('notes'),
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
