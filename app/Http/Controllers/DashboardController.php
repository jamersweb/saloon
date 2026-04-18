<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AttendanceLog;
use App\Models\Customer;
use App\Models\Feedback;
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
        $user = $request->user();
        $isStaff = $user?->hasRole('staff') ?? false;
        $isManagerOrOwner = $user?->hasRole('manager', 'owner') ?? false;
        $isCustomer = $user?->hasRole('customer') ?? false;

        $period = $request->string('period')->toString();
        if (! in_array($period, ['today', 'week', 'month'], true)) {
            $period = 'today';
        }

        $canSeeCheckoutAlerts = $user && (
            $user->hasRole('owner', 'manager')
            || $user->hasPermission('can_manage_finance')
            || $user->hasPermission('can_collect_payments')
        );

        [$dateFrom, $dateTo, $periodLabel] = $this->resolveDateRange($period);
        $staffProfileId = $user?->staffProfile?->id;

        $appointmentsQuery = Appointment::query()
            ->when($isStaff && $staffProfileId, fn ($query) => $query->where('staff_profile_id', $staffProfileId));

        $appointmentsInPeriod = (clone $appointmentsQuery)
            ->whereBetween('scheduled_start', [$dateFrom, $dateTo])
            ->count();

        $pendingLeaves = LeaveRequest::query()
            ->when($isStaff && $staffProfileId, fn ($query) => $query->where('staff_profile_id', $staffProfileId))
            ->where('status', 'pending')
            ->count();

        $lateInPeriod = AttendanceLog::query()
            ->when($isStaff && $staffProfileId, fn ($query) => $query->where('staff_profile_id', $staffProfileId))
            ->whereBetween('attendance_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->where('late_minutes', '>', 0)
            ->count();

        $staffFeedbackQuery = Feedback::query()
            ->with(['staffProfile.user:id,name', 'customer:id,name'])
            ->where('direction', 'staff_to_customer');
        $customerReviewQuery = Feedback::query()
            ->with(['staffProfile.user:id,name', 'customer:id,name'])
            ->where('direction', 'customer_to_staff');

        if ($isStaff && $staffProfileId) {
            $staffFeedbackQuery->where('staff_profile_id', $staffProfileId);
            $customerReviewQuery->where('staff_profile_id', $staffProfileId);
        } elseif ($isCustomer && ! $isManagerOrOwner) {
            $customerReviewQuery->where('created_by_user_id', $user?->id);
            $staffFeedbackQuery->where('created_by_user_id', $user?->id);
        }

        $awaitingCheckoutVisits = [];
        if ($canSeeCheckoutAlerts) {
            $awaitingCheckoutVisits = Appointment::query()
                ->with(['taxInvoices.payments', 'service:id,name'])
                ->where('status', Appointment::STATUS_COMPLETED)
                ->where('scheduled_start', '>=', now()->subDays(14))
                ->orderByDesc('scheduled_start')
                ->limit(40)
                ->get()
                ->map(function (Appointment $appointment) {
                    $summary = $appointment->checkoutSummary();
                    if (! $summary['awaiting_checkout']) {
                        return null;
                    }

                    return [
                        'id' => $appointment->id,
                        'customer_name' => $appointment->customer_name,
                        'service_name' => $appointment->service?->name,
                        'scheduled_start' => $appointment->scheduled_start?->toIso8601String(),
                        'invoice_id' => $summary['checkout_invoice_id'],
                    ];
                })
                ->filter()
                ->values()
                ->take(12)
                ->all();
        }

        return Inertia::render('Dashboard', [
            'selectedPeriod' => $period,
            'periodLabel' => $periodLabel,
            'range' => [
                'from' => $dateFrom->toDateString(),
                'to' => $dateTo->toDateString(),
            ],
            'awaitingCheckoutVisits' => $awaitingCheckoutVisits,
            'stats' => [
                'customers' => Customer::count(),
                'services' => SalonService::count(),
                'staff' => StaffProfile::where('is_active', true)->count(),
                'appointments_in_period' => $appointmentsInPeriod,
                'pending_leaves' => $pendingLeaves,
                'late_staff_in_period' => $lateInPeriod,
            ],
            'upcomingAppointments' => (clone $appointmentsQuery)
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
            'staffFeedbackOptions' => [
                'customers' => Customer::query()
                    ->select('id', 'name')
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->limit(200)
                    ->get(),
                'staffProfiles' => StaffProfile::query()
                    ->with('user:id,name')
                    ->where('is_active', true)
                    ->orderBy('employee_code')
                    ->limit(200)
                    ->get()
                    ->map(fn (StaffProfile $profile) => [
                        'id' => $profile->id,
                        'name' => $profile->user?->name,
                        'employee_code' => $profile->employee_code,
                    ]),
            ],
            'staffToCustomerFeedback' => $staffFeedbackQuery
                ->latest()
                ->limit(12)
                ->get()
                ->map(fn (Feedback $feedback) => [
                    'id' => $feedback->id,
                    'created_at' => $feedback->created_at,
                    'staff_name' => $feedback->staffProfile?->user?->name ?? $feedback->reviewer_name,
                    'customer_name' => $feedback->customer?->name,
                    'comment' => $feedback->comment,
                ]),
            'customerToStaffReviews' => $customerReviewQuery
                ->latest()
                ->limit(12)
                ->get()
                ->map(fn (Feedback $feedback) => [
                    'id' => $feedback->id,
                    'created_at' => $feedback->created_at,
                    'reviewer_name' => $feedback->reviewer_name,
                    'staff_name' => $feedback->staffProfile?->user?->name,
                    'rating' => $feedback->rating,
                    'comment' => $feedback->comment,
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
