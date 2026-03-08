<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Appointment;
use App\Models\AttendanceLog;
use App\Models\Customer;
use App\Models\CustomerLoyaltyAccount;
use App\Models\CustomerLoyaltyLedger;
use App\Models\InventoryItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $dateFrom = $request->date('date_from')?->startOfDay() ?? now()->startOfMonth();
        $dateTo = $request->date('date_to')?->endOfDay() ?? now()->endOfDay();
        $report = $this->collectReportData($dateFrom, $dateTo);

        return Inertia::render('Reports/Index', [
            'filters' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
            ],
            'overview' => $report['overview'],
            'statusBreakdown' => $report['statusBreakdown'],
            'servicePerformance' => $report['servicePerformance'],
            'staffPerformance' => $report['staffPerformance'],
            'dailyRevenue' => $report['dailyRevenue'],
            'waitingTimeByStaff' => $report['waitingTimeByStaff'],
            'lateMinutesByStaff' => $report['lateMinutesByStaff'],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'type' => ['required', Rule::in(['appointments', 'customers', 'inventory', 'loyalty'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $dateFrom = isset($data['date_from']) ? Carbon::parse($data['date_from'])->startOfDay() : now()->startOfMonth();
        $dateTo = isset($data['date_to']) ? Carbon::parse($data['date_to'])->endOfDay() : now()->endOfDay();

        $rows = [];
        $headers = [];

        switch ($data['type']) {
            case 'appointments':
                $headers = ['ID', 'Date', 'Customer', 'Phone', 'Service', 'Status'];
                $rows = Appointment::query()
                    ->with('service:id,name')
                    ->whereBetween('scheduled_start', [$dateFrom, $dateTo])
                    ->orderBy('scheduled_start')
                    ->get()
                    ->map(fn (Appointment $appointment) => [
                        $appointment->id,
                        optional($appointment->scheduled_start)->format('Y-m-d H:i'),
                        $appointment->customer_name,
                        $appointment->customer_phone,
                        $appointment->service?->name,
                        $appointment->status,
                    ])
                    ->all();
                break;

            case 'customers':
                $headers = ['ID', 'Name', 'Phone', 'Email', 'Joined'];
                $rows = Customer::query()
                    ->whereBetween('created_at', [$dateFrom, $dateTo])
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Customer $customer) => [
                        $customer->id,
                        $customer->name,
                        $customer->phone,
                        $customer->email,
                        optional($customer->created_at)->toDateString(),
                    ])
                    ->all();
                break;

            case 'inventory':
                $headers = ['SKU', 'Name', 'Category', 'Stock', 'Reorder Level', 'Cost Price', 'Selling Price'];
                $rows = InventoryItem::query()
                    ->orderBy('name')
                    ->get()
                    ->map(fn (InventoryItem $item) => [
                        $item->sku,
                        $item->name,
                        $item->category,
                        $item->stock_quantity,
                        $item->reorder_level,
                        $item->cost_price,
                        $item->selling_price,
                    ])
                    ->all();
                break;

            case 'loyalty':
                $headers = ['Customer', 'Phone', 'Points', 'Tier', 'Last Activity'];
                $rows = CustomerLoyaltyAccount::query()
                    ->with(['customer:id,name,phone', 'tier:id,name'])
                    ->orderByDesc('current_points')
                    ->get()
                    ->map(fn (CustomerLoyaltyAccount $account) => [
                        $account->customer?->name,
                        $account->customer?->phone,
                        $account->current_points,
                        $account->tier?->name,
                        optional($account->last_activity_at)->format('Y-m-d H:i'),
                    ])
                    ->all();
                break;
        }

        $filename = sprintf('%s-report-%s.csv', $data['type'], now()->format('Ymd-His'));

        return response()->streamDownload(function () use ($headers, $rows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function exportPdf(Request $request): Response
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $dateFrom = isset($data['date_from']) ? Carbon::parse($data['date_from'])->startOfDay() : now()->startOfMonth();
        $dateTo = isset($data['date_to']) ? Carbon::parse($data['date_to'])->endOfDay() : now()->endOfDay();
        $report = $this->collectReportData($dateFrom, $dateTo);

        $pdf = Pdf::loadView('reports.pdf', [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'overview' => $report['overview'],
            'statusBreakdown' => $report['statusBreakdown'],
            'servicePerformance' => $report['servicePerformance'],
            'staffPerformance' => $report['staffPerformance'],
            'dailyRevenue' => $report['dailyRevenue'],
            'waitingTimeByStaff' => $report['waitingTimeByStaff'],
            'lateMinutesByStaff' => $report['lateMinutesByStaff'],
        ])->setPaper('a4', 'portrait');

        return $pdf->download(sprintf('reports-summary-%s.pdf', now()->format('Ymd-His')));
    }

    private function collectReportData(Carbon $dateFrom, Carbon $dateTo): array
    {
        $appointmentsInRange = Appointment::query()
            ->whereBetween('scheduled_start', [$dateFrom, $dateTo]);

        $completedRevenue = Appointment::query()
            ->join('salon_services', 'appointments.service_id', '=', 'salon_services.id')
            ->where('appointments.status', Appointment::STATUS_COMPLETED)
            ->whereBetween('appointments.scheduled_start', [$dateFrom, $dateTo])
            ->sum('salon_services.price');

        $statusBreakdown = (clone $appointmentsInRange)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $servicePerformance = Appointment::query()
            ->join('salon_services', 'appointments.service_id', '=', 'salon_services.id')
            ->whereBetween('appointments.scheduled_start', [$dateFrom, $dateTo])
            ->selectRaw('salon_services.name as service_name, COUNT(*) as total, SUM(CASE WHEN appointments.status = ? THEN salon_services.price ELSE 0 END) as revenue', [Appointment::STATUS_COMPLETED])
            ->groupBy('salon_services.name')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->toArray();

        $staffPerformance = Appointment::query()
            ->join('staff_profiles', 'appointments.staff_profile_id', '=', 'staff_profiles.id')
            ->join('users', 'staff_profiles.user_id', '=', 'users.id')
            ->whereBetween('appointments.scheduled_start', [$dateFrom, $dateTo])
            ->selectRaw('users.name as staff_name, COUNT(*) as total')
            ->groupBy('users.name')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->toArray();

        $dailyRevenue = Appointment::query()
            ->join('salon_services', 'appointments.service_id', '=', 'salon_services.id')
            ->where('appointments.status', Appointment::STATUS_COMPLETED)
            ->whereBetween('appointments.scheduled_start', [$dateFrom, $dateTo])
            ->selectRaw('DATE(appointments.scheduled_start) as date, SUM(salon_services.price) as revenue')
            ->groupByRaw('DATE(appointments.scheduled_start)')
            ->orderBy('date')
            ->get()
            ->toArray();

        $waitingTimeByStaff = Appointment::query()
            ->join('staff_profiles', 'appointments.staff_profile_id', '=', 'staff_profiles.id')
            ->join('users', 'staff_profiles.user_id', '=', 'users.id')
            ->whereBetween('appointments.scheduled_start', [$dateFrom, $dateTo])
            ->whereNotNull('appointments.arrival_time')
            ->whereNotNull('appointments.service_start_time')
            ->selectRaw('users.name as staff_name, AVG(TIMESTAMPDIFF(MINUTE, appointments.arrival_time, appointments.service_start_time)) as avg_waiting_minutes')
            ->groupBy('users.name')
            ->orderByDesc('avg_waiting_minutes')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'staff_name' => $row->staff_name,
                'avg_waiting_minutes' => round((float) $row->avg_waiting_minutes, 1),
            ])
            ->toArray();

        $lateMinutesByStaff = AttendanceLog::query()
            ->join('staff_profiles', 'attendance_logs.staff_profile_id', '=', 'staff_profiles.id')
            ->join('users', 'staff_profiles.user_id', '=', 'users.id')
            ->whereBetween('attendance_logs.attendance_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->selectRaw('users.name as staff_name, SUM(attendance_logs.late_minutes) as late_minutes')
            ->groupBy('users.name')
            ->orderByDesc('late_minutes')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'staff_name' => $row->staff_name,
                'late_minutes' => (int) $row->late_minutes,
            ])
            ->toArray();

        $overallAvgWaiting = Appointment::query()
            ->whereBetween('scheduled_start', [$dateFrom, $dateTo])
            ->whereNotNull('arrival_time')
            ->whereNotNull('service_start_time')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, arrival_time, service_start_time)) as avg_waiting_minutes')
            ->value('avg_waiting_minutes');

        return [
            'overview' => [
                'appointments_total' => (clone $appointmentsInRange)->count(),
                'completed_revenue' => (float) $completedRevenue,
                'new_customers' => Customer::query()->whereBetween('created_at', [$dateFrom, $dateTo])->count(),
                'inventory_items' => InventoryItem::query()->count(),
                'inventory_low_stock' => InventoryItem::query()->whereColumn('stock_quantity', '<=', 'reorder_level')->count(),
                'loyalty_members' => CustomerLoyaltyAccount::query()->count(),
                'loyalty_points_issued' => CustomerLoyaltyLedger::query()->whereBetween('created_at', [$dateFrom, $dateTo])->where('points_change', '>', 0)->sum('points_change'),
                'avg_waiting_minutes' => round((float) ($overallAvgWaiting ?? 0), 1),
                'late_minutes_total' => (int) AttendanceLog::query()->whereBetween('attendance_date', [$dateFrom->toDateString(), $dateTo->toDateString()])->sum('late_minutes'),
            ],
            'statusBreakdown' => $statusBreakdown,
            'servicePerformance' => $servicePerformance,
            'staffPerformance' => $staffPerformance,
            'dailyRevenue' => $dailyRevenue,
            'waitingTimeByStaff' => $waitingTimeByStaff,
            'lateMinutesByStaff' => $lateMinutesByStaff,
        ];
    }
}
