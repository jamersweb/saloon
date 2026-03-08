<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AttendanceLog;
use App\Models\Customer;
use App\Models\LeaveRequest;
use App\Models\SalonService;
use App\Models\StaffProfile;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $period = $request->string('period')->toString();
        if (! in_array($period, ['today', 'week', 'month'], true)) {
            $period = 'today';
        }

        [$dateFrom, $dateTo, $periodLabel] = $this->resolveDateRange($period);

        $appointmentsInPeriod = Appointment::query()
            ->whereBetween('scheduled_start', [$dateFrom, $dateTo])
            ->count();

        $pendingLeaves = LeaveRequest::query()
            ->where('status', 'pending')
            ->count();

        $lateInPeriod = AttendanceLog::query()
            ->whereBetween('attendance_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->where('late_minutes', '>', 0)
            ->count();

        return Inertia::render('Dashboard', [
            'selectedPeriod' => $period,
            'periodLabel' => $periodLabel,
            'range' => [
                'from' => $dateFrom->toDateString(),
                'to' => $dateTo->toDateString(),
            ],
            'stats' => [
                'customers' => Customer::count(),
                'services' => SalonService::count(),
                'staff' => StaffProfile::where('is_active', true)->count(),
                'appointments_in_period' => $appointmentsInPeriod,
                'pending_leaves' => $pendingLeaves,
                'late_staff_in_period' => $lateInPeriod,
            ],
            'upcomingAppointments' => Appointment::query()
                ->with(['service:id,name', 'staffProfile.user:id,name'])
                ->whereBetween('scheduled_start', [$dateFrom, $dateTo])
                ->orderBy('scheduled_start')
                ->limit(12)
                ->get()
                ->map(fn (Appointment $appointment) => [
                    'id' => $appointment->id,
                    'scheduled_start' => $appointment->scheduled_start,
                    'customer_name' => $appointment->customer_name,
                    'status' => $appointment->status,
                    'service_name' => $appointment->service?->name,
                    'staff_name' => $appointment->staffProfile?->user?->name,
                ]),
        ]);
    }

    private function resolveDateRange(string $period): array
    {
        $now = now();

        return match ($period) {
            'week' => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek(), 'This Week'],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth(), 'This Month'],
            default => [$now->copy()->startOfDay(), $now->copy()->endOfDay(), 'Today'],
        };
    }
}
