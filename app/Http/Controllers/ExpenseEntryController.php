<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use App\Models\Campaign;
use App\Models\ExpenseEntry;
use App\Models\FinanceSetting;
use App\Models\PettyCashClosing;
use App\Models\PettyCashEntry;
use App\Models\PurchaseOrder;
use App\Models\StaffProfile;
use App\Support\FinanceStructure;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseEntryController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $filters = [
            'date_from' => (string) $request->input('date_from', now()->startOfMonth()->toDateString()),
            'date_to' => (string) $request->input('date_to', now()->toDateString()),
            'expense_type' => (string) $request->input('expense_type', ''),
            'approval_status' => (string) $request->input('approval_status', 'all'),
            'payment_status' => (string) $request->input('payment_status', 'all'),
            'campaign_id' => (string) $request->input('campaign_id', ''),
            'staff_profile_id' => (string) $request->input('staff_profile_id', ''),
            'search' => trim((string) $request->input('search', '')),
        ];

        if (! in_array($filters['approval_status'], ['all', ExpenseEntry::APPROVAL_PENDING, ExpenseEntry::APPROVAL_APPROVED, ExpenseEntry::APPROVAL_REJECTED], true)) {
            $filters['approval_status'] = 'all';
        }

        if (! in_array($filters['payment_status'], ['all', ExpenseEntry::STATUS_PAID, ExpenseEntry::STATUS_UNPAID], true)) {
            $filters['payment_status'] = 'all';
        }

        $baseQuery = ExpenseEntry::query()
            ->with(['purchaseOrder:id,po_number', 'campaign:id,name', 'createdBy:id,name', 'staffProfile.user:id,name', 'approvedBy:id,name'])
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('expense_date', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('expense_date', '<=', $filters['date_to']))
            ->when($filters['expense_type'] !== '', fn ($query) => $query->where('expense_type', $filters['expense_type']))
            ->when($filters['approval_status'] !== 'all', fn ($query) => $query->where('approval_status', $filters['approval_status']))
            ->when($filters['payment_status'] !== 'all', fn ($query) => $query->where('payment_status', $filters['payment_status']))
            ->when($filters['campaign_id'] !== '', fn ($query) => $query->where('campaign_id', $filters['campaign_id']))
            ->when($filters['staff_profile_id'] !== '', fn ($query) => $query->where('staff_profile_id', $filters['staff_profile_id']))
            ->when($filters['search'] !== '', function ($query) use ($filters): void {
                $needle = '%'.$filters['search'].'%';
                $query->where(function ($nested) use ($needle): void {
                    $nested
                        ->where('vendor_name', 'like', $needle)
                        ->orWhere('notes', 'like', $needle)
                        ->orWhere('receipt_number', 'like', $needle)
                        ->orWhere('expense_subcategory', 'like', $needle)
                        ->orWhereHas('campaign', fn ($campaignQuery) => $campaignQuery->where('name', 'like', $needle));
                });
            });

        $expenses = (clone $baseQuery)
            ->latest('expense_date')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (ExpenseEntry $e) => [
                'id' => $e->id,
                'category' => $e->category,
                'cost_center' => $e->cost_center,
                'expense_type' => $e->expense_type,
                'expense_subcategory' => $e->expense_subcategory,
                'vendor_name' => $e->vendor_name,
                'expense_date' => $e->expense_date->toDateString(),
                'amount_subtotal' => (float) $e->amount_subtotal,
                'vat_amount' => (float) $e->vat_amount,
                'total_amount' => (float) $e->total_amount,
                'payment_status' => $e->payment_status,
                'payment_method' => $e->payment_method,
                'approval_status' => $e->approval_status,
                'receipt_number' => $e->receipt_number,
                'receipt_image_url' => $e->receipt_image_url,
                'paid_at' => optional($e->paid_at)?->toIso8601String(),
                'approved_at' => optional($e->approved_at)?->toIso8601String(),
                'purchase_order' => $e->purchaseOrder ? ['id' => $e->purchaseOrder->id, 'po_number' => $e->purchaseOrder->po_number] : null,
                'campaign' => $e->campaign ? ['id' => $e->campaign->id, 'name' => $e->campaign->name] : null,
                'staff_profile' => $e->staffProfile ? [
                    'id' => $e->staffProfile->id,
                    'name' => $e->staffProfile->user?->name ?? $e->staffProfile->employee_code,
                ] : null,
                'notes' => $e->notes,
                'created_by_name' => $e->createdBy?->name,
                'approved_by_name' => $e->approvedBy?->name,
            ]);

        $summaryRows = (clone $baseQuery)->get(['expense_type', 'category', 'cost_center', 'campaign_id', 'approval_status', 'payment_status', 'total_amount', 'expense_date']);

        $summary = [
            'period_total' => (float) $summaryRows->sum('total_amount'),
            'pending_approval_total' => (float) $summaryRows->where('approval_status', ExpenseEntry::APPROVAL_PENDING)->sum('total_amount'),
            'approved_total' => (float) $summaryRows->where('approval_status', ExpenseEntry::APPROVAL_APPROVED)->sum('total_amount'),
            'cash_total' => (float) $summaryRows->where('payment_status', ExpenseEntry::STATUS_PAID)->sum('total_amount'),
            'today_total' => (float) $summaryRows->filter(fn (ExpenseEntry $entry) => $entry->expense_date->isToday())->sum('total_amount'),
        ];

        $byType = $summaryRows
            ->groupBy('expense_type')
            ->map(fn ($group, $type) => [
                'key' => $type,
                'total' => (float) $group->sum('total_amount'),
                'count' => $group->count(),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();

        $byCategory = $summaryRows
            ->groupBy('category')
            ->map(fn ($group, $category) => [
                'key' => $category,
                'total' => (float) $group->sum('total_amount'),
                'count' => $group->count(),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();

        $byCostCenter = $summaryRows
            ->groupBy('cost_center')
            ->map(fn ($group, $costCenter) => [
                'key' => $costCenter,
                'total' => (float) $group->sum('total_amount'),
                'count' => $group->count(),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();

        $daily = $summaryRows
            ->groupBy(fn (ExpenseEntry $entry) => $entry->expense_date->format('Y-m-d'))
            ->map(fn ($group, $date) => [
                'date' => $date,
                'total' => (float) $group->sum('total_amount'),
                'count' => $group->count(),
            ])
            ->sortKeysDesc()
            ->take(7)
            ->values()
            ->all();

        $purchaseOrders = PurchaseOrder::query()
            ->whereIn('status', [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_RECEIVED])
            ->with('supplier:id,name')
            ->latest('order_date')
            ->limit(100)
            ->get()
            ->map(fn (PurchaseOrder $po) => [
                'id' => $po->id,
                'label' => $po->po_number.' | '.($po->supplier?->name ?? 'Supplier').' | '.number_format((float) $po->total_cost, 2),
            ]);

        $staffProfiles = StaffProfile::query()
            ->where('is_active', true)
            ->with('user:id,name')
            ->orderBy('employee_code')
            ->get()
            ->map(fn (StaffProfile $staff) => [
                'id' => $staff->id,
                'label' => ($staff->user?->name ?? 'Staff').' | '.$staff->employee_code,
            ]);

        $campaigns = Campaign::query()
            ->latest()
            ->limit(120)
            ->get(['id', 'name'])
            ->map(fn (Campaign $campaign) => [
                'id' => $campaign->id,
                'label' => $campaign->name,
            ]);

        $pettyCashEntries = PettyCashEntry::query()
            ->with(['staffProfile.user:id,name', 'expenseEntry:id,expense_subcategory'])
            ->latest('transaction_date')
            ->latest('id')
            ->limit(20)
            ->get();

        $pettyCashBalanceRows = PettyCashEntry::query()
            ->with('staffProfile.user:id,name')
            ->get();

        $pettyCash = [
            'total_balance' => (float) $pettyCashBalanceRows->sum(fn (PettyCashEntry $entry) => $entry->direction === PettyCashEntry::DIRECTION_IN ? (float) $entry->amount : -(float) $entry->amount),
            'balances' => $pettyCashBalanceRows
                ->groupBy(fn (PettyCashEntry $entry) => $entry->staff_profile_id ?: 'unassigned')
                ->map(function ($group, $key) {
                    $first = $group->first();
                    $balance = (float) $group->sum(fn (PettyCashEntry $entry) => $entry->direction === PettyCashEntry::DIRECTION_IN ? (float) $entry->amount : -(float) $entry->amount);

                    return [
                        'staff_profile_id' => $key === 'unassigned' ? null : (int) $key,
                        'custodian' => $first?->staffProfile?->user?->name ?? 'General fund',
                        'balance' => $balance,
                    ];
                })
                ->sortByDesc('balance')
                ->values()
                ->all(),
            'recent_entries' => $pettyCashEntries->map(fn (PettyCashEntry $entry) => [
                'id' => $entry->id,
                'transaction_type' => $entry->transaction_type,
                'direction' => $entry->direction,
                'amount' => (float) $entry->amount,
                'transaction_date' => $entry->transaction_date->toDateString(),
                'custodian' => $entry->staffProfile?->user?->name ?? 'General fund',
                'expense_subcategory' => $entry->expenseEntry?->expense_subcategory,
                'notes' => $entry->notes,
            ])->all(),
        ];

        return Inertia::render('Finance/Expenses/Index', [
            'filters' => $filters,
            'summary' => $summary,
            'breakdown' => [
                'byType' => $byType,
                'byCategory' => $byCategory,
                'byCostCenter' => $byCostCenter,
                'daily' => $daily,
            ],
            'pettyCash' => $pettyCash,
            'expenses' => $expenses,
            'purchaseOrders' => $purchaseOrders,
            'campaigns' => $campaigns,
            'staffProfiles' => $staffProfiles,
            'categories' => self::categories(),
            'costCenters' => self::costCenters(),
            'expenseTypes' => self::expenseTypes(),
            'expenseSubcategories' => self::expenseSubcategories(),
            'paymentMethods' => self::paymentMethods(),
            'approvalStatuses' => self::approvalStatuses(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $this->validatedExpense($request);
        $this->ensurePettyCashDateIsOpen($data['expense_type'], $data['expense_date'], $data['staff_profile_id'] ?? null);
        $data['created_by'] = $request->user()->id;
        $data['approval_status'] = ExpenseEntry::APPROVAL_PENDING;
        $data['approved_by'] = null;
        $data['approved_at'] = null;
        $data['total_amount'] = round($data['amount_subtotal'] + $data['vat_amount'], 2);
        $data['receipt_image_path'] = $this->storeReceiptImage($request);

        if ($data['payment_status'] === ExpenseEntry::STATUS_PAID && empty($data['paid_at'])) {
            $data['paid_at'] = now();
        }

        $expense = ExpenseEntry::query()->create($data);

        Audit::log($request->user()->id, 'finance.expense.created', 'ExpenseEntry', $expense->id, []);

        return back()->with('status', 'Expense recorded and sent for approval.');
    }

    public function update(Request $request, ExpenseEntry $expense): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $this->ensurePettyCashDateIsOpen($expense->expense_type, $expense->expense_date->toDateString(), $expense->staff_profile_id);
        $this->deletePettyCashEntry($expense);

        $data = $this->validatedExpense($request);
        $this->ensurePettyCashDateIsOpen($data['expense_type'], $data['expense_date'], $data['staff_profile_id'] ?? null);
        $data['total_amount'] = round($data['amount_subtotal'] + $data['vat_amount'], 2);

        if ($request->boolean('remove_receipt_image')) {
            $this->deleteReceiptImage($expense->receipt_image_path);
            $data['receipt_image_path'] = null;
        } elseif ($request->hasFile('receipt_image')) {
            $this->deleteReceiptImage($expense->receipt_image_path);
            $data['receipt_image_path'] = $this->storeReceiptImage($request);
        }

        if ($data['payment_status'] === ExpenseEntry::STATUS_PAID && empty($data['paid_at'])) {
            $data['paid_at'] = now();
        }

        $data['approval_status'] = ExpenseEntry::APPROVAL_PENDING;
        $data['approved_by'] = null;
        $data['approved_at'] = null;

        $expense->update($data);

        Audit::log($request->user()->id, 'finance.expense.updated', 'ExpenseEntry', $expense->id, []);

        return back()->with('status', 'Expense updated and returned to pending approval.');
    }

    public function destroy(Request $request, ExpenseEntry $expense): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $this->ensurePettyCashDateIsOpen($expense->expense_type, $expense->expense_date->toDateString(), $expense->staff_profile_id);
        $id = $expense->id;
        $this->deletePettyCashEntry($expense);
        $this->deleteReceiptImage($expense->receipt_image_path);
        $expense->delete();

        Audit::log($request->user()->id, 'finance.expense.deleted', 'ExpenseEntry', $id, []);

        return back()->with('status', 'Expense removed.');
    }

    public function markPaid(Request $request, ExpenseEntry $expense): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'paid_at' => ['nullable', 'date'],
        ]);

        $expense->update([
            'payment_status' => ExpenseEntry::STATUS_PAID,
            'paid_at' => isset($data['paid_at']) ? $data['paid_at'] : now(),
        ]);

        Audit::log($request->user()->id, 'finance.expense.marked_paid', 'ExpenseEntry', $expense->id, []);

        return back()->with('status', 'Expense marked as paid.');
    }

    public function updateApproval(Request $request, ExpenseEntry $expense): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'approval_status' => ['required', Rule::in([ExpenseEntry::APPROVAL_APPROVED, ExpenseEntry::APPROVAL_REJECTED])],
        ]);

        if ($data['approval_status'] !== ExpenseEntry::APPROVAL_APPROVED) {
            $this->deletePettyCashEntry($expense);
        }

        if ($data['approval_status'] === ExpenseEntry::APPROVAL_APPROVED && $expense->expense_type === 'petty_cash') {
            $this->ensurePettyCashDateIsOpen($expense->expense_type, $expense->expense_date->toDateString(), $expense->staff_profile_id);
            $balance = $this->pettyCashBalanceForStaff($expense->staff_profile_id);

            if ($balance + 0.0001 < (float) $expense->total_amount) {
                return back()->withErrors([
                    'approval_status' => 'Petty cash balance is not enough for this approval.',
                ]);
            }
        }

        $expense->update([
            'approval_status' => $data['approval_status'],
            'approved_by' => $request->user()->id,
            'approved_at' => now(),
        ]);

        if ($data['approval_status'] === ExpenseEntry::APPROVAL_APPROVED) {
            $this->syncPettyCashExpense($expense, $request);
        }

        Audit::log($request->user()->id, 'finance.expense.approval_updated', 'ExpenseEntry', $expense->id, [
            'approval_status' => $data['approval_status'],
        ]);

        return back()->with('status', 'Expense approval updated.');
    }

    public function issuePettyCash(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'staff_profile_id' => ['nullable', 'exists:staff_profiles,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'transaction_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->ensurePettyCashDateIsOpen('petty_cash', $data['transaction_date'], $data['staff_profile_id'] ?? null);

        $entry = PettyCashEntry::query()->create([
            'staff_profile_id' => $data['staff_profile_id'] ?? null,
            'transaction_type' => PettyCashEntry::TYPE_ISSUE,
            'direction' => PettyCashEntry::DIRECTION_IN,
            'amount' => round((float) $data['amount'], 2),
            'transaction_date' => $data['transaction_date'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        Audit::log($request->user()->id, 'finance.petty_cash.issued', 'PettyCashEntry', $entry->id, []);

        return back()->with('status', 'Petty cash issued.');
    }

    public function closePettyCash(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'staff_profile_id' => ['nullable', 'exists:staff_profiles,id'],
            'closing_date' => ['required', 'date'],
            'counted_closing_balance' => ['required', 'numeric', 'min:0'],
            'signed_off_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $staffProfileId = isset($data['staff_profile_id']) && $data['staff_profile_id'] !== '' ? (int) $data['staff_profile_id'] : null;
        $closingDate = Carbon::parse($data['closing_date'])->toDateString();

        $existing = PettyCashClosing::query()
            ->when($staffProfileId, fn ($query) => $query->where('staff_profile_id', $staffProfileId), fn ($query) => $query->whereNull('staff_profile_id'))
            ->whereDate('closing_date', $closingDate)
            ->first();

        if ($existing) {
            return back()->withErrors([
                'closing_date' => 'This petty cash fund is already closed for the selected date.',
            ]);
        }

        $report = $this->pettyCashReportPayload(new Request([
            'date_from' => $closingDate,
            'date_to' => $closingDate,
            'staff_profile_id' => $staffProfileId,
        ]));

        $variance = round((float) $data['counted_closing_balance'] - (float) $report['summary']['closing_balance'], 2);
        $varianceEntry = null;

        if (abs($variance) > 0.0001) {
            $varianceEntry = PettyCashEntry::query()->create([
                'staff_profile_id' => $staffProfileId,
                'transaction_type' => PettyCashEntry::TYPE_ADJUSTMENT,
                'direction' => $variance > 0 ? PettyCashEntry::DIRECTION_IN : PettyCashEntry::DIRECTION_OUT,
                'amount' => abs($variance),
                'transaction_date' => $closingDate,
                'notes' => 'Closing variance adjustment',
                'created_by' => $request->user()->id,
            ]);
        }

        $closing = PettyCashClosing::query()->create([
            'staff_profile_id' => $staffProfileId,
            'closing_date' => $closingDate,
            'opening_balance' => $report['summary']['opening_balance'],
            'issued_total' => $report['summary']['issued_total'],
            'spent_total' => $report['summary']['spent_total'],
            'expected_closing_balance' => $report['summary']['closing_balance'],
            'counted_closing_balance' => round((float) $data['counted_closing_balance'], 2),
            'variance_amount' => $variance,
            'signed_off_name' => $data['signed_off_name'] ?: $request->user()->name,
            'notes' => $data['notes'] ?? null,
            'variance_entry_id' => $varianceEntry?->id,
            'closed_by' => $request->user()->id,
            'closed_at' => now(),
        ]);

        Audit::log($request->user()->id, 'finance.petty_cash.closed', 'PettyCashClosing', $closing->id, [
            'variance_amount' => $variance,
        ]);

        return back()->with('status', 'Petty cash closed and signed off.');
    }

    public function exportPettyCash(Request $request): StreamedResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $report = $this->pettyCashReportPayload($request);
        $filename = 'petty-cash-closing-'.$report['filters']['date_from'].'-to-'.$report['filters']['date_to'].'.csv';

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Petty Cash Closing Report']);
            fputcsv($out, ['From', $report['filters']['date_from'], 'To', $report['filters']['date_to']]);
            fputcsv($out, []);
            fputcsv($out, ['Custodian', 'Opening Balance', 'Issued', 'Spent', 'Closing Balance']);

            foreach ($report['custodians'] as $row) {
                fputcsv($out, [
                    $row['custodian'],
                    $row['opening_balance'],
                    $row['issued_total'],
                    $row['spent_total'],
                    $row['closing_balance'],
                ]);
            }

            fputcsv($out, []);
            fputcsv($out, ['Date', 'Custodian', 'Type', 'Direction', 'Amount', 'Notes']);

            foreach ($report['transactions'] as $row) {
                fputcsv($out, [
                    $row['transaction_date'],
                    $row['custodian'],
                    $row['transaction_type'],
                    $row['direction'],
                    $row['amount'],
                    $row['notes'],
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function printPettyCash(Request $request)
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $report = $this->pettyCashReportPayload($request);

        return response()->view('finance.petty-cash-closing-print', [
            'report' => $report,
            'currencyCode' => FinanceSetting::current()->currency_code ?: 'AED',
            'forPdf' => false,
        ]);
    }

    public function pdfPettyCash(Request $request)
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $report = $this->pettyCashReportPayload($request);
        $currencyCode = FinanceSetting::current()->currency_code ?: 'AED';

        return Pdf::loadView('finance.petty-cash-closing-print', [
            'report' => $report,
            'currencyCode' => $currencyCode,
            'forPdf' => true,
        ])->setPaper('a4', 'portrait')->download(sprintf(
            'petty-cash-closing-%s-to-%s.pdf',
            $report['filters']['date_from'],
            $report['filters']['date_to']
        ));
    }

    public static function categories(): array
    {
        return FinanceStructure::expenseCategories();
    }

    public static function costCenters(): array
    {
        return FinanceStructure::costCenters();
    }

    public static function expenseTypes(): array
    {
        return [
            'operational' => 'Operational expense',
            'staff_welfare' => 'Staff welfare',
            'petty_cash' => 'Petty cash',
            'staff_reimbursement' => 'Staff reimbursement',
            'inventory_related' => 'Inventory related',
        ];
    }

    public static function expenseSubcategories(): array
    {
        return [
            'operational' => [
                'rent' => 'Rent / premises',
                'utilities' => 'Utilities',
                'marketing' => 'Marketing',
                'maintenance' => 'Maintenance',
                'professional_fees' => 'Professional fees',
                'payroll_salary' => 'Payroll salary',
                'transport' => 'Transport / courier',
                'other_ops' => 'Other operational',
            ],
            'staff_welfare' => [
                'staff_meal' => 'Staff meal',
                'staff_drinks' => 'Tea / coffee / drinks',
                'staff_water' => 'Drinking water',
                'staff_snacks' => 'Snacks',
                'staff_event' => 'Team event',
            ],
            'petty_cash' => [
                'cleaning_supplies' => 'Cleaning supplies',
                'bathroom_supplies' => 'Bathroom supplies',
                'office_supplies' => 'Office supplies',
                'small_tools' => 'Small tools',
                'misc_cash' => 'Misc petty cash',
            ],
            'staff_reimbursement' => [
                'staff_transport' => 'Staff transport',
                'staff_purchase_refund' => 'Staff purchase refund',
                'staff_advance' => 'Staff advance',
                'medical_support' => 'Medical support',
                'other_reimbursement' => 'Other reimbursement',
            ],
            'inventory_related' => [
                'retail_stock' => 'Retail stock',
                'service_consumables' => 'Service consumables',
                'packaging' => 'Packaging',
                'supplier_cash_buy' => 'Supplier cash purchase',
            ],
        ];
    }

    public static function paymentMethods(): array
    {
        return FinanceStructure::paymentMethods();
    }

    public static function approvalStatuses(): array
    {
        return [
            ExpenseEntry::APPROVAL_PENDING => 'Pending approval',
            ExpenseEntry::APPROVAL_APPROVED => 'Approved',
            ExpenseEntry::APPROVAL_REJECTED => 'Rejected',
        ];
    }

    private function validatedExpense(Request $request): array
    {
        $costCenters = array_keys(self::costCenters());
        $types = array_keys(self::expenseTypes());
        $paymentMethods = array_keys(self::paymentMethods());
        $subcategories = collect(self::expenseSubcategories())
            ->flatMap(fn (array $items) => array_keys($items))
            ->values()
            ->all();

        $data = $request->validate([
            'category' => ['required', 'string', 'max:64'],
            'cost_center' => ['nullable', Rule::in($costCenters)],
            'expense_type' => ['required', Rule::in($types)],
            'expense_subcategory' => ['required', Rule::in($subcategories)],
            'vendor_name' => ['nullable', 'string', 'max:255'],
            'expense_date' => ['required', 'date'],
            'amount_subtotal' => ['required', 'numeric', 'min:0'],
            'vat_amount' => ['required', 'numeric', 'min:0'],
            'payment_status' => ['required', Rule::in([ExpenseEntry::STATUS_UNPAID, ExpenseEntry::STATUS_PAID])],
            'payment_method' => ['required', Rule::in($paymentMethods)],
            'receipt_number' => ['nullable', 'string', 'max:255'],
            'receipt_image' => ['nullable', 'image', 'max:5120'],
            'paid_at' => ['nullable', 'date'],
            'purchase_order_id' => ['nullable', 'exists:purchase_orders,id'],
            'campaign_id' => ['nullable', 'exists:campaigns,id'],
            'staff_profile_id' => ['nullable', 'exists:staff_profiles,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['category'] = FinanceStructure::normalizeExpenseCategory($data['category'] ?? null);
        $data['payment_method'] = FinanceStructure::normalizePaymentMethod($data['payment_method'] ?? null);
        $data['cost_center'] = FinanceStructure::normalizeCostCenter($data['cost_center'] ?? null)
            ?? FinanceStructure::defaultExpenseCostCenter($data['category'] ?? null, $data['expense_subcategory'] ?? null);

        if ($data['category'] === 'miscellaneous' && trim((string) ($data['notes'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'notes' => 'Miscellaneous expenses require a written explanation.',
            ]);
        }

        return $data;
    }

    private function storeReceiptImage(Request $request): ?string
    {
        if (! $request->hasFile('receipt_image')) {
            return null;
        }

        return $request->file('receipt_image')->store('expense-receipts', 'public');
    }

    private function deleteReceiptImage(?string $path): void
    {
        if (is_string($path) && $path !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    private function syncPettyCashExpense(ExpenseEntry $expense, Request $request): void
    {
        if ($expense->expense_type !== 'petty_cash') {
            return;
        }

        $existing = $expense->pettyCashEntry;
        if ($existing) {
            return;
        }

        $entry = PettyCashEntry::query()->create([
            'staff_profile_id' => $expense->staff_profile_id,
            'expense_entry_id' => $expense->id,
            'transaction_type' => PettyCashEntry::TYPE_EXPENSE,
            'direction' => PettyCashEntry::DIRECTION_OUT,
            'amount' => (float) $expense->total_amount,
            'transaction_date' => $expense->expense_date->toDateString(),
            'notes' => $expense->notes ?: ($expense->vendor_name ?: 'Petty cash expense'),
            'created_by' => $request->user()->id,
        ]);

        Audit::log($request->user()->id, 'finance.petty_cash.expense_applied', 'PettyCashEntry', $entry->id, [
            'expense_entry_id' => $expense->id,
        ]);
    }

    private function deletePettyCashEntry(ExpenseEntry $expense): void
    {
        if (! $expense->relationLoaded('pettyCashEntry')) {
            $expense->load('pettyCashEntry');
        }

        if ($expense->pettyCashEntry) {
            $expense->pettyCashEntry->delete();
        }
    }

    private function pettyCashBalanceForStaff(?int $staffProfileId): float
    {
        return (float) PettyCashEntry::query()
            ->when($staffProfileId, fn ($query) => $query->where('staff_profile_id', $staffProfileId), fn ($query) => $query->whereNull('staff_profile_id'))
            ->get()
            ->sum(fn (PettyCashEntry $entry) => $entry->direction === PettyCashEntry::DIRECTION_IN ? (float) $entry->amount : -(float) $entry->amount);
    }

    private function ensurePettyCashDateIsOpen(string $expenseType, string $date, mixed $staffProfileId): void
    {
        if ($expenseType !== 'petty_cash') {
            return;
        }

        $normalizedStaffProfileId = $staffProfileId !== null && $staffProfileId !== '' ? (int) $staffProfileId : null;
        $closing = PettyCashClosing::query()
            ->when($normalizedStaffProfileId, fn ($query) => $query->where('staff_profile_id', $normalizedStaffProfileId), fn ($query) => $query->whereNull('staff_profile_id'))
            ->whereDate('closing_date', $date)
            ->exists();

        if ($closing) {
            throw ValidationException::withMessages([
                'expense_date' => 'This petty cash date is already closed.',
            ]);
        }
    }

    /**
     * @return array{
     *   filters: array{date_from: string, date_to: string, staff_profile_id: ?int},
     *   summary: array{opening_balance: float, issued_total: float, spent_total: float, closing_balance: float},
     *   custodians: list<array{custodian: string, opening_balance: float, issued_total: float, spent_total: float, closing_balance: float}>,
     *   transactions: list<array{transaction_date: string, custodian: string, transaction_type: string, direction: string, amount: float, notes: string}>,
     *   closings: list<array{closing_date: string, custodian: string, expected_closing_balance: float, counted_closing_balance: float, variance_amount: float, signed_off_name: string, notes: string}>
     * }
     */
    private function pettyCashReportPayload(Request $request): array
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'staff_profile_id' => ['nullable', 'exists:staff_profiles,id'],
        ]);

        $dateFrom = isset($data['date_from']) ? Carbon::parse($data['date_from'])->startOfDay() : now()->startOfDay();
        $dateTo = isset($data['date_to']) ? Carbon::parse($data['date_to'])->endOfDay() : now()->endOfDay();
        $staffProfileId = isset($data['staff_profile_id']) && $data['staff_profile_id'] !== '' ? (int) $data['staff_profile_id'] : null;

        $baseQuery = PettyCashEntry::query()
            ->with(['staffProfile.user:id,name'])
            ->when($staffProfileId, fn ($query) => $query->where('staff_profile_id', $staffProfileId));

        $openingRows = (clone $baseQuery)
            ->whereDate('transaction_date', '<', $dateFrom->toDateString())
            ->get();

        $periodRows = (clone $baseQuery)
            ->whereDate('transaction_date', '>=', $dateFrom->toDateString())
            ->whereDate('transaction_date', '<=', $dateTo->toDateString())
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $closings = PettyCashClosing::query()
            ->with('staffProfile.user:id,name')
            ->when($staffProfileId, fn ($query) => $query->where('staff_profile_id', $staffProfileId))
            ->whereDate('closing_date', '>=', $dateFrom->toDateString())
            ->whereDate('closing_date', '<=', $dateTo->toDateString())
            ->orderBy('closing_date')
            ->get();

        $groupKey = fn (PettyCashEntry $entry) => $entry->staff_profile_id ?: 'unassigned';
        $custodianName = fn (?PettyCashEntry $entry) => $entry?->staffProfile?->user?->name ?? 'General fund';

        $openingByCustodian = $openingRows->groupBy($groupKey);
        $periodByCustodian = $periodRows->groupBy($groupKey);
        $custodianKeys = collect($openingByCustodian->keys())->merge($periodByCustodian->keys())->unique()->values();

        $custodians = $custodianKeys->map(function ($key) use ($openingByCustodian, $periodByCustodian, $custodianName) {
            $openingGroup = $openingByCustodian->get($key, collect());
            $periodGroup = $periodByCustodian->get($key, collect());
            $openingBalance = (float) $openingGroup->sum(fn (PettyCashEntry $entry) => $entry->direction === PettyCashEntry::DIRECTION_IN ? (float) $entry->amount : -(float) $entry->amount);
            $issuedTotal = (float) $periodGroup->where('direction', PettyCashEntry::DIRECTION_IN)->sum('amount');
            $spentTotal = (float) $periodGroup->where('direction', PettyCashEntry::DIRECTION_OUT)->sum('amount');

            return [
                'custodian' => $custodianName($periodGroup->first() ?: $openingGroup->first()),
                'opening_balance' => $openingBalance,
                'issued_total' => $issuedTotal,
                'spent_total' => $spentTotal,
                'closing_balance' => $openingBalance + $issuedTotal - $spentTotal,
            ];
        })->sortBy('custodian')->values()->all();

        $openingBalance = (float) array_sum(array_map(fn (array $row) => $row['opening_balance'], $custodians));
        $issuedTotal = (float) array_sum(array_map(fn (array $row) => $row['issued_total'], $custodians));
        $spentTotal = (float) array_sum(array_map(fn (array $row) => $row['spent_total'], $custodians));

        return [
            'filters' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
                'staff_profile_id' => $staffProfileId,
            ],
            'summary' => [
                'opening_balance' => $openingBalance,
                'issued_total' => $issuedTotal,
                'spent_total' => $spentTotal,
                'closing_balance' => $openingBalance + $issuedTotal - $spentTotal,
            ],
            'custodians' => $custodians,
            'transactions' => $periodRows->map(fn (PettyCashEntry $entry) => [
                'transaction_date' => $entry->transaction_date->toDateString(),
                'custodian' => $custodianName($entry),
                'transaction_type' => $entry->transaction_type,
                'direction' => $entry->direction,
                'amount' => (float) $entry->amount,
                'notes' => (string) ($entry->notes ?? ''),
            ])->all(),
            'closings' => $closings->map(fn (PettyCashClosing $closing) => [
                'closing_date' => $closing->closing_date->toDateString(),
                'custodian' => $closing->staffProfile?->user?->name ?? 'General fund',
                'expected_closing_balance' => (float) $closing->expected_closing_balance,
                'counted_closing_balance' => (float) $closing->counted_closing_balance,
                'variance_amount' => (float) $closing->variance_amount,
                'signed_off_name' => (string) ($closing->signed_off_name ?? ''),
                'notes' => (string) ($closing->notes ?? ''),
            ])->all(),
        ];
    }
}
