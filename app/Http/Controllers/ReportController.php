<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AttendanceLog;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\CustomerLoyaltyAccount;
use App\Models\CustomerLoyaltyLedger;
use App\Models\ExpenseEntry;
use App\Models\FinanceSetting;
use App\Models\InventoryItem;
use App\Models\InvoicePayment;
use App\Models\RentalSettlement;
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
            'clientRevenue' => $this->clientRevenueRows($dateFrom, $dateTo),
            'rentalAnalytics' => $this->rentalAnalytics($dateFrom, $dateTo),
            'marketingSpend' => $this->marketingSpendRows($dateFrom, $dateTo),
            'currencyCode' => $currencyCode,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'type' => ['required', Rule::in(['appointments', 'customers', 'inventory', 'loyalty', 'client_revenue', 'rentals', 'marketing_campaigns'])],
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

            case 'client_revenue':
                $headers = ['Customer', 'Invoice Count', 'Revenue Total', 'Amount Paid', 'Outstanding Balance', 'Last Invoice Date'];
                $rows = array_map(fn (array $row) => [
                    $row['customer_name'],
                    $row['invoice_count'],
                    $row['revenue_total'],
                    $row['amount_paid'],
                    $row['outstanding_balance'],
                    $row['last_invoice_date'],
                ], $this->clientRevenueRows($dateFrom, $dateTo));
                break;

            case 'rentals':
                $headers = ['Partner', 'Agreement Type', 'Cost Center', 'Settlements', 'Fixed Rent', 'Commission', 'Total Income'];
                $rows = array_map(fn (array $row) => [
                    $row['partner_name'],
                    $row['agreement_type'],
                    $row['cost_center_label'],
                    $row['settlement_count'],
                    $row['fixed_rent_total'],
                    $row['commission_total'],
                    $row['total_income'],
                ], $this->rentalAnalytics($dateFrom, $dateTo)['partners']);
                break;

            case 'marketing_campaigns':
                $headers = ['Campaign', 'Expense Count', 'Spend Total', 'Last Expense Date'];
                $rows = array_map(fn (array $row) => [
                    $row['campaign_name'],
                    $row['expense_count'],
                    $row['spend_total'],
                    $row['last_expense_date'],
                ], $this->marketingSpendRows($dateFrom, $dateTo));
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
            $serviceReports = $this->collectServiceReportRows($dateFrom, $dateTo, $serviceReportFilters, true);
            $servicePaymentTotals = $this->paymentTotalsForServiceRows($dateFrom, $dateTo, $serviceReports);
            $currencyCode = FinanceSetting::current()->currency_code ?: 'AED';

            $pdf = Pdf::loadView('reports.service-report-pdf', [
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
                'currencyCode' => $currencyCode,
                'filters' => $serviceReportFilters,
                'serviceReports' => $serviceReports,
                'totals' => $this->serviceReportTotals($serviceReports, $servicePaymentTotals),
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
    private function collectServiceReportRows(Carbon $dateFrom, Carbon $dateTo, array $filters, bool $includeRetailProducts = false): array
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
        $invoiceIds = $this->invoiceIdsForAppointments($appointments);
        $invoiceItems = $this->invoiceItemsForAppointments($appointments);

        $rows = $appointments
            ->flatMap(function (Appointment $appointment) use ($invoiceLabels, $invoiceIds, $invoiceItems, $vatRatePercent): Collection {
                $serviceItems = ($invoiceItems[$appointment->id] ?? collect())
                    ->filter(fn (TaxInvoiceItem $item) => $item->salon_service_id !== null)
                    ->values();

                if ($serviceItems->isEmpty()) {
                    return collect([
                        $this->fallbackServiceReportRow($appointment, $invoiceLabels, $invoiceIds, $vatRatePercent),
                    ]);
                }

                return $serviceItems->map(
                    fn (TaxInvoiceItem $item, int $index): array => $this->serviceReportRowFromInvoiceItem(
                        $appointment,
                        $item,
                        $invoiceLabels[$appointment->id] ?? '',
                        $invoiceIds[$appointment->id] ?? [],
                        $index
                    )
                );
            })
            ->values()
            ->all();

        if (! $includeRetailProducts) {
            return $rows;
        }

        return collect($rows)
            ->concat($this->retailProductReportRows($appointments))
            ->sortBy([
                ['date', 'asc'],
                ['id', 'asc'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{cash_total_payment?: float, card_total_payment?: float}  $paymentTotals
     * @return array{service_count: int, service_quantity: float, subtotal: float, tax: float, total: float, cash_total_payment: float, card_total_payment: float}
     */
    private function serviceReportTotals(array $rows, array $paymentTotals = []): array
    {
        return [
            'service_count' => count($rows),
            'service_quantity' => round(array_sum(array_map(fn (array $row) => (float) ($row['quantity'] ?? 0), $rows)), 2),
            'subtotal' => round(array_sum(array_map(fn (array $row) => (float) ($row['subtotal'] ?? 0), $rows)), 2),
            'tax' => round(array_sum(array_map(fn (array $row) => (float) ($row['tax'] ?? 0), $rows)), 2),
            'total' => round(array_sum(array_map(fn (array $row) => (float) ($row['total'] ?? 0), $rows)), 2),
            'cash_total_payment' => round((float) ($paymentTotals['cash_total_payment'] ?? 0), 2),
            'card_total_payment' => round((float) ($paymentTotals['card_total_payment'] ?? 0), 2),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{cash_total_payment: float, card_total_payment: float}
     */
    private function paymentTotalsForServiceRows(Carbon $dateFrom, Carbon $dateTo, array $rows): array
    {
        $invoiceIds = collect($rows)
            ->flatMap(fn (array $row) => $row['invoice_ids'] ?? [])
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($invoiceIds === []) {
            return [
                'cash_total_payment' => 0.0,
                'card_total_payment' => 0.0,
            ];
        }

        return $this->paymentTotalsForInvoices($invoiceIds);
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return list<array<string, mixed>>
     */
    private function retailProductReportRows(Collection $appointments): array
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

        $reportAppointmentsById = $appointments->keyBy('id');
        $reportAppointmentsByVisit = $appointments
            ->filter(fn (Appointment $appointment) => $appointment->visit_id)
            ->groupBy('visit_id')
            ->map(fn (Collection $visitAppointments) => $visitAppointments
                ->sortBy([
                    ['scheduled_start', 'asc'],
                    ['id', 'asc'],
                ])
                ->values());

        return TaxInvoice::query()
            ->with(['items', 'appointment'])
            ->where('status', '!=', TaxInvoice::STATUS_VOID)
            ->whereIn('appointment_id', array_values(array_unique(array_merge($appointmentIds, $visitAppointmentIds))))
            ->orderByRaw('invoice_number IS NULL')
            ->orderBy('invoice_number')
            ->get()
            ->flatMap(function (TaxInvoice $invoice) use ($reportAppointmentsById, $reportAppointmentsByVisit, $appointmentVisitMap): Collection {
                $invoiceAppointment = $reportAppointmentsById->get($invoice->appointment_id);
                $visitId = $appointmentVisitMap[$invoice->appointment_id] ?? $invoiceAppointment?->visit_id ?? $invoice->appointment?->visit_id;
                $reportAppointment = $visitId
                    ? optional($reportAppointmentsByVisit->get($visitId))->first()
                    : $invoiceAppointment;

                if (! $reportAppointment) {
                    return collect();
                }

                return $invoice->items
                    ->filter(fn (TaxInvoiceItem $item) => $item->salon_service_id === null)
                    ->values()
                    ->map(fn (TaxInvoiceItem $item, int $index): array => [
                        'id' => sprintf('product-%d-%d', $invoice->id, $item->id ?: $index),
                        'date' => optional($reportAppointment->scheduled_start)->format('Y-m-d H:i'),
                        'customer_name' => $invoice->customer_display_name ?: $reportAppointment->customer?->name ?: $reportAppointment->customer_name,
                        'customer_phone' => $reportAppointment->customer_phone,
                        'invoice_number' => $invoice->invoice_number ?? '',
                        'invoice_ids' => [$invoice->id],
                        'service_name' => $item->description,
                        'quantity' => round((float) $item->quantity, 2),
                        'unit_price' => round((float) $item->unit_price, 2),
                        'discount_amount' => round((float) $item->discount_amount, 2),
                        'subtotal' => round((float) $item->line_subtotal, 2),
                        'tax' => round((float) $item->line_tax, 2),
                        'total' => round((float) $item->line_total, 2),
                        'staff_name' => $reportAppointment->staffProfile?->user?->name,
                        'service_report' => 'Retail product sale',
                    ]);
            })
            ->values()
            ->all();
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
            ->with('items.staffProfile.user:id,name')
            ->where('status', '!=', TaxInvoice::STATUS_VOID)
            ->whereIn('appointment_id', array_values(array_unique(array_merge($appointmentIds, $visitAppointmentIds))))
            ->orderByRaw('invoice_number IS NULL')
            ->orderBy('invoice_number')
            ->get();

        $reportAppointmentsById = $appointments->keyBy('id');
        $reportAppointmentsByVisit = $appointments
            ->filter(fn (Appointment $appointment) => $appointment->visit_id)
            ->groupBy('visit_id')
            ->map(fn (Collection $visitAppointments) => $visitAppointments
                ->sortBy([
                    ['scheduled_start', 'asc'],
                    ['id', 'asc'],
                ])
                ->values());

        $items = [];
        foreach ($invoices as $invoice) {
            $invoiceAppointment = $reportAppointmentsById->get($invoice->appointment_id);
            $visitId = $appointmentVisitMap[$invoice->appointment_id] ?? $invoiceAppointment?->visit_id;
            $invoiceAppointments = $visitId
                ? ($reportAppointmentsByVisit->get($visitId) ?? collect())
                : ($invoiceAppointment ? collect([$invoiceAppointment]) : collect());

            if ($invoiceAppointments->isEmpty()) {
                continue;
            }

            foreach ($this->assignInvoiceItemsToAppointments($invoiceAppointments, $invoice->items) as $appointmentId => $appointmentItems) {
                $items[$appointmentId] = ($items[$appointmentId] ?? collect())->concat($appointmentItems);
            }
        }

        foreach ($appointments as $appointment) {
            $items[$appointment->id] = ($items[$appointment->id] ?? collect())->values();
        }

        return $items;
    }

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @param  Collection<int, TaxInvoiceItem>  $invoiceItems
     * @return array<int, Collection<int, TaxInvoiceItem>>
     */
    private function assignInvoiceItemsToAppointments(Collection $appointments, Collection $invoiceItems): array
    {
        $appointments = $appointments
            ->sortBy([
                ['scheduled_start', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        $remainingItems = $invoiceItems
            ->filter(fn (TaxInvoiceItem $item) => $item->salon_service_id !== null)
            ->values();
        $pendingAppointments = $appointments;
        $assignments = [];

        foreach ($appointments as $appointment) {
            $assignments[$appointment->id] = collect();
        }

        $assignBy = function (callable $matches) use (&$pendingAppointments, &$remainingItems, &$assignments): void {
            $stillPending = collect();

            foreach ($pendingAppointments as $appointment) {
                $matchedKey = null;
                $matchedItem = null;

                foreach ($remainingItems as $key => $item) {
                    if ($matches($appointment, $item)) {
                        $matchedKey = $key;
                        $matchedItem = $item;
                        break;
                    }
                }

                if ($matchedItem) {
                    $assignments[$appointment->id] = $assignments[$appointment->id]->push($matchedItem);
                    $remainingItems->forget($matchedKey);
                } else {
                    $stillPending->push($appointment);
                }
            }

            $pendingAppointments = $stillPending->values();
            $remainingItems = $remainingItems->values();
        };

        $assignBy(fn (Appointment $appointment, TaxInvoiceItem $item): bool => (int) $appointment->service_id > 0
            && (int) $item->salon_service_id === (int) $appointment->service_id);

        $assignBy(function (Appointment $appointment, TaxInvoiceItem $item): bool {
            $serviceName = mb_strtolower(trim((string) $appointment->service?->name));

            return $serviceName !== ''
                && mb_strtolower(trim((string) $item->description)) === $serviceName;
        });

        if ($remainingItems->isNotEmpty()) {
            foreach ($remainingItems->values() as $item) {
                $targetAppointment = null;

                if ($item->staff_profile_id) {
                    $targetAppointment = $appointments->first(
                        fn (Appointment $appointment) => (int) $appointment->staff_profile_id === (int) $item->staff_profile_id
                    );
                }

                if (! $targetAppointment) {
                    $targetAppointment = $pendingAppointments->first() ?? $appointments->first();
                }

                if ($targetAppointment) {
                    $assignments[$targetAppointment->id] = $assignments[$targetAppointment->id]->push($item);
                }
            }
        }

        foreach ($appointments as $appointment) {
            $assignments[$appointment->id] = $assignments[$appointment->id] ?? collect();
        }

        return $assignments;
    }

    /**
     * @param  array<int, string>  $invoiceLabels
     * @param  array<int, list<int>>  $invoiceIds
     * @return array<string, mixed>
     */
    private function fallbackServiceReportRow(Appointment $appointment, array $invoiceLabels, array $invoiceIds, float $vatRatePercent): array
    {
        $quantity = max(1.0, (float) ($appointment->service_quantity ?? 1));
        $unitPrice = $appointment->customer_package_id
            ? 0.0
            : (float) ($appointment->service_unit_price ?? $appointment->service?->price ?? 0);
        $discountAmount = (float) ($appointment->customer_package_id ? 0.0 : ($appointment->service_discount_amount ?? 0));
        $subtotal = max(0.0, ($quantity * $unitPrice) - $discountAmount);
        $tax = round($subtotal * ($vatRatePercent / 100), 2);
        $total = round($subtotal + $tax, 2);

        return [
            'id' => $appointment->id,
            'appointment_id' => $appointment->id,
            'date' => optional($appointment->scheduled_start)->format('Y-m-d H:i'),
            'customer_name' => $appointment->customer?->name ?: $appointment->customer_name,
            'customer_phone' => $appointment->customer_phone,
            'invoice_number' => $invoiceLabels[$appointment->id] ?? '',
            'invoice_ids' => $invoiceIds[$appointment->id] ?? [],
            'service_name' => $appointment->service?->name,
            'quantity' => $quantity,
            'unit_price' => round($unitPrice, 2),
            'discount_amount' => round($discountAmount, 2),
            'subtotal' => round($subtotal, 2),
            'tax' => round($tax, 2),
            'total' => round($total, 2),
            'staff_name' => $appointment->staffProfile?->user?->name,
            'service_report' => $appointment->notes,
        ];
    }

    /**
     * @param  list<int>  $invoiceIds
     * @return array<string, mixed>
     */
    private function serviceReportRowFromInvoiceItem(Appointment $appointment, TaxInvoiceItem $item, string $invoiceNumber, array $invoiceIds, int $index = 0): array
    {
        return [
            'id' => sprintf('%d-%d-%d', $appointment->id, $item->tax_invoice_id, $item->id ?: $index),
            'appointment_id' => $appointment->id,
            'date' => optional($appointment->scheduled_start)->format('Y-m-d H:i'),
            'customer_name' => $appointment->customer?->name ?: $appointment->customer_name,
            'customer_phone' => $appointment->customer_phone,
            'invoice_number' => $invoiceNumber,
            'invoice_ids' => $invoiceIds,
            'service_name' => $item->description ?: $appointment->service?->name,
            'quantity' => round((float) $item->quantity, 2),
            'unit_price' => round((float) $item->unit_price, 2),
            'discount_amount' => round((float) $item->discount_amount, 2),
            'subtotal' => round((float) $item->line_subtotal, 2),
            'tax' => round((float) $item->line_tax, 2),
            'total' => round((float) $item->line_total, 2),
            'staff_name' => $item->staffProfile?->user?->name ?: $appointment->staffProfile?->user?->name,
            'service_report' => $appointment->notes,
        ];
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

    /**
     * @param  Collection<int, Appointment>  $appointments
     * @return array<int, list<int>>
     */
    private function invoiceIdsForAppointments(Collection $appointments): array
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
            ->get(['id', 'appointment_id']);

        $byAppointment = [];
        $byVisit = [];

        foreach ($invoices as $invoice) {
            $byAppointment[$invoice->appointment_id][] = (int) $invoice->id;

            $visitId = $appointmentVisitMap[$invoice->appointment_id] ?? null;
            if ($visitId) {
                $byVisit[$visitId][] = (int) $invoice->id;
            }
        }

        $ids = [];
        foreach ($appointments as $appointment) {
            $invoiceIds = $byAppointment[$appointment->id] ?? [];

            if ($invoiceIds === [] && $appointment->visit_id) {
                $invoiceIds = $byVisit[$appointment->visit_id] ?? [];
            }

            $ids[$appointment->id] = array_values(array_unique($invoiceIds));
        }

        return $ids;
    }

    private function collectReportData(Carbon $dateFrom, Carbon $dateTo): array
    {
        $waitingMinutesExpression = $this->minutesBetweenExpression('appointments.arrival_time', 'appointments.service_start_time');
        $serviceReportRows = $this->collectServiceReportRows($dateFrom, $dateTo, [
            'customer_name' => '',
            'invoice_number' => '',
        ]);
        $serviceReportTotals = $this->serviceReportTotals($serviceReportRows);

        $appointmentsInRange = Appointment::query()
            ->whereBetween('scheduled_start', [$dateFrom, $dateTo]);

        $statusBreakdown = (clone $appointmentsInRange)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $servicePerformance = collect($serviceReportRows)
            ->groupBy(fn (array $row) => (string) ($row['service_name'] ?? 'Unknown service'))
            ->map(fn (Collection $group, string $serviceName): array => [
                'service_name' => $serviceName,
                'total' => $group->count(),
                'revenue' => round((float) $group->sum(fn (array $row) => (float) ($row['total'] ?? 0)), 2),
            ])
            ->sortByDesc('revenue')
            ->take(8)
            ->values()
            ->all();

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

        $dailyRevenue = collect($serviceReportRows)
            ->groupBy(fn (array $row) => substr((string) ($row['date'] ?? ''), 0, 10))
            ->map(fn (Collection $group, string $date): array => [
                'date' => $date,
                'revenue' => round((float) $group->sum(fn (array $row) => (float) ($row['total'] ?? 0)), 2),
            ])
            ->filter(fn (array $row) => $row['date'] !== '')
            ->sortBy('date')
            ->values()
            ->all();

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

        $paymentTotals = $this->paymentTotals($dateFrom, $dateTo);

        return [
            'overview' => [
                'appointments_total' => (clone $appointmentsInRange)->count(),
                'completed_services' => (int) $serviceReportTotals['service_count'],
                'completed_revenue' => (float) $serviceReportTotals['total'],
                'cash_total_payment' => $paymentTotals['cash_total_payment'],
                'card_total_payment' => $paymentTotals['card_total_payment'],
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

    /**
     * @return list<array{customer_name: string, invoice_count: int, revenue_total: float, amount_paid: float, outstanding_balance: float, last_invoice_date: string}>
     */
    private function clientRevenueRows(Carbon $dateFrom, Carbon $dateTo): array
    {
        return TaxInvoice::query()
            ->where('status', TaxInvoice::STATUS_FINALIZED)
            ->whereBetween('issued_at', [$dateFrom, $dateTo])
            ->with('payments:id,tax_invoice_id,amount')
            ->get()
            ->groupBy(fn (TaxInvoice $invoice) => $invoice->customer_display_name ?: 'Walk-in / Unnamed')
            ->map(function (Collection $group, string $customerName): array {
                $latest = $group->sortByDesc('issued_at')->first();
                $revenueTotal = (float) $group->sum('total');
                $amountPaid = (float) $group->sum(fn (TaxInvoice $invoice) => $invoice->payments->sum('amount'));

                return [
                    'customer_name' => $customerName,
                    'invoice_count' => $group->count(),
                    'revenue_total' => round($revenueTotal, 2),
                    'amount_paid' => round($amountPaid, 2),
                    'outstanding_balance' => round($revenueTotal - $amountPaid, 2),
                    'last_invoice_date' => optional($latest?->issued_at)->toDateString() ?: '',
                ];
            })
            ->sortByDesc('revenue_total')
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   summary: array{settlement_count: int, fixed_rent_total: float, commission_total: float, total_income: float},
     *   partners: list<array{partner_name: string, agreement_type: string, cost_center: string, cost_center_label: string, settlement_count: int, fixed_rent_total: float, commission_total: float, total_income: float}>
     * }
     */
    private function rentalAnalytics(Carbon $dateFrom, Carbon $dateTo): array
    {
        $rows = RentalSettlement::query()
            ->with('agreement:id,partner_name,agreement_type,cost_center')
            ->whereBetween('settlement_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->get();

        return [
            'summary' => [
                'settlement_count' => $rows->count(),
                'fixed_rent_total' => round((float) $rows->sum('fixed_rent_amount'), 2),
                'commission_total' => round((float) $rows->sum('commission_amount'), 2),
                'total_income' => round((float) $rows->sum('total_amount'), 2),
            ],
            'partners' => $rows
                ->groupBy(fn (RentalSettlement $settlement) => ($settlement->agreement?->partner_name ?: 'Unknown').'|'.($settlement->agreement?->agreement_type ?: 'unknown').'|'.($settlement->agreement?->cost_center ?: 'general_salon'))
                ->map(function (Collection $group, string $key): array {
                    [$partnerName, $agreementType, $costCenter] = array_pad(explode('|', $key), 3, '');

                    return [
                        'partner_name' => $partnerName,
                        'agreement_type' => $agreementType,
                        'cost_center' => $costCenter,
                        'cost_center_label' => \App\Support\FinanceStructure::costCenters()[$costCenter] ?? $costCenter,
                        'settlement_count' => $group->count(),
                        'fixed_rent_total' => round((float) $group->sum('fixed_rent_amount'), 2),
                        'commission_total' => round((float) $group->sum('commission_amount'), 2),
                        'total_income' => round((float) $group->sum('total_amount'), 2),
                    ];
                })
                ->sortByDesc('total_income')
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<array{campaign_name: string, expense_count: int, spend_total: float, last_expense_date: string}>
     */
    private function marketingSpendRows(Carbon $dateFrom, Carbon $dateTo): array
    {
        return ExpenseEntry::query()
            ->with('campaign:id,name')
            ->whereBetween('expense_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('campaign_id')
                    ->orWhere('category', 'marketing_branding')
                    ->orWhere('expense_subcategory', 'marketing');
            })
            ->get()
            ->groupBy(fn (ExpenseEntry $expense) => $expense->campaign?->name ?: 'Unlinked marketing spend')
            ->map(function (Collection $group, string $campaignName): array {
                $latest = $group->sortByDesc('expense_date')->first();

                return [
                    'campaign_name' => $campaignName,
                    'expense_count' => $group->count(),
                    'spend_total' => round((float) $group->sum('total_amount'), 2),
                    'last_expense_date' => optional($latest?->expense_date)->toDateString() ?: '',
                ];
            })
            ->sortByDesc('spend_total')
            ->values()
            ->all();
    }

    /**
     * @param  list<int>|null  $invoiceIds
     * @return array{cash_total_payment: float, card_total_payment: float}
     */
    private function paymentTotals(Carbon $dateFrom, Carbon $dateTo, ?array $invoiceIds = null): array
    {
        $query = InvoicePayment::query()
            ->whereBetween('paid_at', [$dateFrom, $dateTo])
            ->whereIn('method', [InvoicePayment::METHOD_CASH, InvoicePayment::METHOD_CARD]);

        if ($invoiceIds !== null) {
            $query->whereIn('tax_invoice_id', $invoiceIds);
        }

        $paymentTotals = $query
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method');

        return [
            'cash_total_payment' => round((float) ($paymentTotals[InvoicePayment::METHOD_CASH] ?? 0), 2),
            'card_total_payment' => round((float) ($paymentTotals[InvoicePayment::METHOD_CARD] ?? 0), 2),
        ];
    }

    /**
     * Service reports are service-date based. Once an invoice is included in the
     * report rows, show the cash/card collected for that invoice even if payment
     * was recorded after the service day.
     *
     * @param  list<int>  $invoiceIds
     * @return array{cash_total_payment: float, card_total_payment: float}
     */
    private function paymentTotalsForInvoices(array $invoiceIds): array
    {
        $paymentTotals = InvoicePayment::query()
            ->whereIn('tax_invoice_id', $invoiceIds)
            ->whereIn('method', [InvoicePayment::METHOD_CASH, InvoicePayment::METHOD_CARD])
            ->selectRaw('method, SUM(amount) as total')
            ->groupBy('method')
            ->pluck('total', 'method');

        return [
            'cash_total_payment' => round((float) ($paymentTotals[InvoicePayment::METHOD_CASH] ?? 0), 2),
            'card_total_payment' => round((float) ($paymentTotals[InvoicePayment::METHOD_CARD] ?? 0), 2),
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
