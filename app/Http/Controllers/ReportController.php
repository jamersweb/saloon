<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AttendanceLog;
use App\Models\Customer;
use App\Models\CustomerLoyaltyAccount;
use App\Models\CustomerLoyaltyLedger;
use App\Models\FinanceSetting;
use App\Models\InventoryItem;
use App\Models\TaxInvoice;
use App\Models\TaxInvoiceItem;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        $serviceReportFilters = $this->serviceReportFilters($request);
        $report = $this->collectReportData($dateFrom, $dateTo);
        $currencyCode = FinanceSetting::current()->currency_code ?: 'AED';

        return Inertia::render('Reports/Index', [
            'filters' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'customer_name' => $serviceReportFilters['customer_name'],
                'invoice_number' => $serviceReportFilters['invoice_number'],
            ],
            'overview' => $report['overview'],
            'statusBreakdown' => $report['statusBreakdown'],
            'servicePerformance' => $report['servicePerformance'],
            'staffPerformance' => $report['staffPerformance'],
            'dailyRevenue' => $report['dailyRevenue'],
            'waitingTimeByStaff' => $report['waitingTimeByStaff'],
            'lateMinutesByStaff' => $report['lateMinutesByStaff'],
            'currencyCode' => $currencyCode,
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
                $headers = ['ID', 'Date', 'Customer', 'Phone', 'Invoice No.', 'Service', 'Status', 'Service Report'];
                $appointments = Appointment::query()
                    ->with('service:id,name')
                    ->whereBetween('scheduled_start', [$dateFrom, $dateTo])
                    ->orderBy('scheduled_start')
                    ->get();

                $invoiceLabels = $this->invoiceLabelsForAppointments($appointments);

                $rows = $appointments
                    ->map(fn (Appointment $appointment) => [
                        $appointment->id,
                        optional($appointment->scheduled_start)->format('Y-m-d H:i'),
                        $appointment->customer_name,
                        $appointment->customer_phone,
                        $invoiceLabels[$appointment->id] ?? '',
                        $appointment->service?->name,
                        $appointment->status,
                        $appointment->notes,
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
            'report' => ['nullable', Rule::in(['summary', 'service'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'invoice_number' => ['nullable', 'string', 'max:100'],
        ]);

        $dateFrom = isset($data['date_from']) ? Carbon::parse($data['date_from'])->startOfDay() : now()->startOfMonth();
        $dateTo = isset($data['date_to']) ? Carbon::parse($data['date_to'])->endOfDay() : now()->endOfDay();
        $serviceReportFilters = [
            'customer_name' => trim((string) ($data['customer_name'] ?? '')),
            'invoice_number' => trim((string) ($data['invoice_number'] ?? '')),
        ];
        $reportType = $data['report'] ?? 'summary';

        if ($reportType === 'service') {
            $serviceReports = $this->collectServiceReportRows($dateFrom, $dateTo, $serviceReportFilters);
            $currencyCode = FinanceSetting::current()->currency_code ?: 'AED';

            $pdf = Pdf::loadView('reports.service-report-pdf', [
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'currencyCode' => $currencyCode,
                'filters' => $serviceReportFilters,
                'serviceReports' => $serviceReports,
                'totals' => $this->serviceReportTotals($serviceReports),
            ])->setPaper('a4', 'landscape');

            return $pdf->download(sprintf('service-reports-%s.pdf', now()->format('Ymd-His')));
        }

        $report = $this->collectReportData($dateFrom, $dateTo);
        $currencyCode = FinanceSetting::current()->currency_code ?: 'AED';

        $pdf = Pdf::loadView('reports.pdf', [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'currencyCode' => $currencyCode,
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

    /**
     * @return array{customer_name: string, invoice_number: string}
     */
    private function serviceReportFilters(Request $request): array
    {
        return [
            'customer_name' => trim((string) $request->query('customer_name', '')),
            'invoice_number' => trim((string) $request->query('invoice_number', '')),
        ];
    }

    /**
     * @param  array{customer_name: string, invoice_number: string}  $filters
     * @return list<array<string, mixed>>
     */
    private function collectServiceReportRows(Carbon $dateFrom, Carbon $dateTo, array $filters): array
    {
        $financeSetting = FinanceSetting::current();
        $vatRatePercent = (float) $financeSetting->vat_rate_percent;

        $query = Appointment::query()
            ->with(['customer:id,name', 'service:id,name,price', 'staffProfile.user:id,name'])
            ->where('status', Appointment::STATUS_COMPLETED)
            ->whereBetween('scheduled_start', [$dateFrom, $dateTo])
            ->orderBy('scheduled_start');

        $this->applyServiceReportFilters($query, $filters);

        $appointments = $query->get();
        $invoiceLabels = $this->invoiceLabelsForAppointments($appointments);
        $invoiceItems = $this->invoiceItemsForAppointments($appointments);

        return $appointments
            ->map(function (Appointment $appointment) use ($invoiceLabels, $invoiceItems, $vatRatePercent): array {
                $serviceName = $appointment->service?->name;
                $item = $this->matchingInvoiceItemForAppointment(
                    $appointment,
                    $invoiceItems[$appointment->id] ?? collect()
                );

                $quantity = max(1.0, (float) ($item?->quantity ?? $appointment->service_quantity ?? 1));
                $unitPrice = $item
                    ? (float) $item->unit_price
                    : ($appointment->customer_package_id ? 0.0 : (float) ($appointment->service?->price ?? 0));
                $discountAmount = $item ? (float) $item->discount_amount : 0.0;
                $subtotal = $item ? (float) $item->line_subtotal : max(0.0, ($quantity * $unitPrice) - $discountAmount);
                $tax = $item ? (float) $item->line_tax : round($subtotal * ($vatRatePercent / 100), 2);
                $total = $item ? (float) $item->line_total : round($subtotal + $tax, 2);

                return [
                    'id' => $appointment->id,
                    'date' => optional($appointment->scheduled_start)->format('Y-m-d H:i'),
                    'customer_name' => $appointment->customer?->name ?: $appointment->customer_name,
                    'customer_phone' => $appointment->customer_phone,
                    'invoice_number' => $invoiceLabels[$appointment->id] ?? '',
                    'service_name' => $serviceName,
                    'quantity' => $quantity,
                    'unit_price' => round($unitPrice, 2),
                    'discount_amount' => round($discountAmount, 2),
                    'subtotal' => round($subtotal, 2),
                    'tax' => round($tax, 2),
                    'total' => round($total, 2),
                    'staff_name' => $appointment->staffProfile?->user?->name,
                    'service_report' => $appointment->notes,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{service_count: int, service_quantity: float, subtotal: float, tax: float, total: float}
     */
    private function serviceReportTotals(array $rows): array
    {
        return [
            'service_count' => count($rows),
            'service_quantity' => round(array_sum(array_map(fn (array $row) => (float) ($row['quantity'] ?? 0), $rows)), 2),
            'subtotal' => round(array_sum(array_map(fn (array $row) => (float) ($row['subtotal'] ?? 0), $rows)), 2),
            'tax' => round(array_sum(array_map(fn (array $row) => (float) ($row['tax'] ?? 0), $rows)), 2),
            'total' => round(array_sum(array_map(fn (array $row) => (float) ($row['total'] ?? 0), $rows)), 2),
        ];
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return array<int, Collection<int, TaxInvoiceItem>>
     */
    private function invoiceItemsForAppointments(Collection $appointments): array
    {
        if ($appointments->isEmpty()) {
            return [];
        }

        $appointmentIds = $appointments->pluck('id')->all();
        $visitIds = $appointments->pluck('visit_id')->filter()->unique()->values()->all();
        $visitAppointmentIds = [];
        $appointmentVisitMap = [];

        if ($visitIds !== []) {
            $appointmentVisitMap = Appointment::query()
                ->whereIn('visit_id', $visitIds)
                ->pluck('visit_id', 'id')
                ->all();
            $visitAppointmentIds = array_keys($appointmentVisitMap);
        }

        $invoices = TaxInvoice::query()
            ->with('items')
            ->where('status', '!=', TaxInvoice::STATUS_VOID)
            ->whereIn('appointment_id', array_values(array_unique(array_merge($appointmentIds, $visitAppointmentIds))))
            ->orderByRaw('invoice_number IS NULL')
            ->orderBy('invoice_number')
            ->get();

        $byAppointment = [];
        $byVisit = [];

        foreach ($invoices as $invoice) {
            $byAppointment[$invoice->appointment_id] = ($byAppointment[$invoice->appointment_id] ?? collect())->concat($invoice->items);

            $visitId = $appointmentVisitMap[$invoice->appointment_id] ?? null;
            if ($visitId) {
                $byVisit[$visitId] = ($byVisit[$visitId] ?? collect())->concat($invoice->items);
            }
        }

        $items = [];
        foreach ($appointments as $appointment) {
            $appointmentItems = $byAppointment[$appointment->id] ?? collect();

            if ($appointmentItems->isEmpty() && $appointment->visit_id) {
                $appointmentItems = $byVisit[$appointment->visit_id] ?? collect();
            }

            $items[$appointment->id] = $appointmentItems->values();
        }

        return $items;
    }

    /**
     * @param  Collection<int, TaxInvoiceItem>  $invoiceItems
     */
    private function matchingInvoiceItemForAppointment(Appointment $appointment, Collection $invoiceItems): ?TaxInvoiceItem
    {
        if ($invoiceItems->isEmpty()) {
            return null;
        }

        $serviceId = (int) $appointment->service_id;
        $serviceName = mb_strtolower(trim((string) $appointment->service?->name));

        $serviceIdMatch = $invoiceItems->first(
            fn (TaxInvoiceItem $item) => (int) $item->salon_service_id === $serviceId
        );

        if ($serviceIdMatch) {
            return $serviceIdMatch;
        }

        $descriptionMatch = $invoiceItems->first(
            fn (TaxInvoiceItem $item) => $serviceName !== '' && mb_strtolower(trim((string) $item->description)) === $serviceName
        );

        if ($descriptionMatch) {
            return $descriptionMatch;
        }

        return $invoiceItems->count() === 1 ? $invoiceItems->first() : null;
    }

    /**
     * @param  Builder<Appointment>  $query
     * @param  array{customer_name: string, invoice_number: string}  $filters
     */
    private function applyServiceReportFilters(Builder $query, array $filters): void
    {
        if ($filters['customer_name'] !== '') {
            $customerName = $filters['customer_name'];

            $query->where(function (Builder $query) use ($customerName): void {
                $query->where('appointments.customer_name', 'like', "%{$customerName}%")
                    ->orWhereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('name', 'like', "%{$customerName}%"));
            });
        }

        if ($filters['invoice_number'] !== '') {
            $invoiceNumber = $filters['invoice_number'];

            $query->where(function (Builder $query) use ($invoiceNumber): void {
                $query->whereHas('taxInvoices', function (Builder $invoiceQuery) use ($invoiceNumber): void {
                    $invoiceQuery
                        ->where('status', '!=', TaxInvoice::STATUS_VOID)
                        ->where('invoice_number', 'like', "%{$invoiceNumber}%");
                })->orWhereExists(function ($subQuery) use ($invoiceNumber): void {
                    $subQuery
                        ->selectRaw('1')
                        ->from('appointments as visit_appointments')
                        ->join('tax_invoices as visit_tax_invoices', 'visit_tax_invoices.appointment_id', '=', 'visit_appointments.id')
                        ->whereColumn('visit_appointments.visit_id', 'appointments.visit_id')
                        ->whereNotNull('appointments.visit_id')
                        ->where('visit_tax_invoices.status', '!=', TaxInvoice::STATUS_VOID)
                        ->where('visit_tax_invoices.invoice_number', 'like', "%{$invoiceNumber}%");
                });
            });
        }
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return array<int, string>
     */
    private function invoiceLabelsForAppointments(Collection $appointments): array
    {
        if ($appointments->isEmpty()) {
            return [];
        }

        $appointmentIds = $appointments->pluck('id')->all();
        $visitIds = $appointments->pluck('visit_id')->filter()->unique()->values()->all();
        $visitAppointmentIds = [];
        $appointmentVisitMap = [];

        if ($visitIds !== []) {
            $appointmentVisitMap = Appointment::query()
                ->whereIn('visit_id', $visitIds)
                ->pluck('visit_id', 'id')
                ->all();
            $visitAppointmentIds = array_keys($appointmentVisitMap);
        }

        $invoices = TaxInvoice::query()
            ->where('status', '!=', TaxInvoice::STATUS_VOID)
            ->whereIn('appointment_id', array_values(array_unique(array_merge($appointmentIds, $visitAppointmentIds))))
            ->orderByRaw('invoice_number IS NULL')
            ->orderBy('invoice_number')
            ->get(['id', 'appointment_id', 'invoice_number']);

        $byAppointment = [];
        $byVisit = [];

        foreach ($invoices as $invoice) {
            $label = $invoice->invoice_number ?: '';
            if ($label === '') {
                continue;
            }

            $byAppointment[$invoice->appointment_id][] = $label;

            $visitId = $appointmentVisitMap[$invoice->appointment_id] ?? null;
            if ($visitId) {
                $byVisit[$visitId][] = $label;
            }
        }

        $labels = [];
        foreach ($appointments as $appointment) {
            $invoiceNumbers = $byAppointment[$appointment->id] ?? [];

            if ($invoiceNumbers === [] && $appointment->visit_id) {
                $invoiceNumbers = $byVisit[$appointment->visit_id] ?? [];
            }

            $labels[$appointment->id] = implode(', ', array_values(array_unique($invoiceNumbers)));
        }

        return $labels;
    }

    private function collectReportData(Carbon $dateFrom, Carbon $dateTo): array
    {
        $waitingMinutesExpression = $this->minutesBetweenExpression('appointments.arrival_time', 'appointments.service_start_time');

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
            ->selectRaw("users.name as staff_name, AVG({$waitingMinutesExpression}) as avg_waiting_minutes")
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
            ->selectRaw("AVG({$this->minutesBetweenExpression('arrival_time', 'service_start_time')}) as avg_waiting_minutes")
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

    private function minutesBetweenExpression(string $startColumn, string $endColumn): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "(strftime('%s', {$endColumn}) - strftime('%s', {$startColumn})) / 60.0";
        }

        return "TIMESTAMPDIFF(MINUTE, {$startColumn}, {$endColumn})";
    }
}
