<?php

namespace App\Http\Controllers;

use App\Models\ExpenseEntry;
use App\Models\InvoicePayment;
use App\Models\PayrollLine;
use App\Models\PurchaseOrder;
use App\Models\TaxInvoice;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinanceDashboardController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $dateFrom = $request->date('date_from')?->startOfDay() ?? now()->startOfMonth();
        $dateTo = $request->date('date_to')?->endOfDay() ?? now()->endOfDay();

        $invoicedTotal = (float) TaxInvoice::query()
            ->where('status', TaxInvoice::STATUS_FINALIZED)
            ->whereBetween('issued_at', [$dateFrom, $dateTo])
            ->sum('total');

        $vatCollected = (float) TaxInvoice::query()
            ->where('status', TaxInvoice::STATUS_FINALIZED)
            ->whereBetween('issued_at', [$dateFrom, $dateTo])
            ->sum('vat_amount');

        $paymentsCollected = (float) InvoicePayment::query()
            ->whereBetween('paid_at', [$dateFrom, $dateTo])
            ->sum('amount');

        $expenseTotal = (float) ExpenseEntry::query()
            ->whereBetween('expense_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->sum('total_amount');

        $expensesPaid = (float) ExpenseEntry::query()
            ->where('payment_status', ExpenseEntry::STATUS_PAID)
            ->whereBetween('expense_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->sum('total_amount');

        $payrollPaid = (float) PayrollLine::query()
            ->whereHas('payrollPeriod', function ($q) use ($dateFrom, $dateTo) {
                $q->where('status', 'paid')
                    ->whereBetween('period_end', [$dateFrom->toDateString(), $dateTo->toDateString()]);
            })
            ->sum('gross_amount');

        $accountsReceivable = TaxInvoice::query()
            ->where('status', TaxInvoice::STATUS_FINALIZED)
            ->with('payments:id,tax_invoice_id,amount')
            ->orderByDesc('issued_at')
            ->limit(50)
            ->get()
            ->map(function (TaxInvoice $invoice) {
                $paid = (float) $invoice->payments->sum('amount');
                $total = (float) $invoice->total;
                $balance = max(0, $total - $paid);

                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'customer_display_name' => $invoice->customer_display_name,
                    'issued_at' => optional($invoice->issued_at)?->toIso8601String(),
                    'total' => $total,
                    'amount_paid' => $paid,
                    'balance' => $balance,
                ];
            })
            ->filter(fn (array $row) => $row['balance'] > 0.009)
            ->values()
            ->all();

        $accountsPayableExpenses = ExpenseEntry::query()
            ->where('payment_status', ExpenseEntry::STATUS_UNPAID)
            ->orderBy('expense_date')
            ->limit(50)
            ->get()
            ->map(fn (ExpenseEntry $e) => [
                'id' => $e->id,
                'type' => 'expense',
                'reference' => 'EXP-'.$e->id,
                'vendor' => $e->vendor_name ?? $e->category,
                'due_date' => $e->expense_date->toDateString(),
                'amount' => (float) $e->total_amount,
            ])
            ->all();

        $accountsPayablePos = PurchaseOrder::query()
            ->whereIn('status', [PurchaseOrder::STATUS_APPROVED, PurchaseOrder::STATUS_RECEIVED])
            ->with('supplier:id,name')
            ->orderByDesc('order_date')
            ->limit(30)
            ->get()
            ->map(fn (PurchaseOrder $po) => [
                'id' => $po->id,
                'type' => 'purchase_order',
                'reference' => $po->po_number,
                'vendor' => $po->supplier?->name ?? 'Supplier',
                'due_date' => optional($po->expected_date)?->toDateString(),
                'amount' => (float) $po->total_cost,
                'status' => $po->status,
            ])
            ->all();

        $monthlyIncome = TaxInvoice::query()
            ->where('status', TaxInvoice::STATUS_FINALIZED)
            ->whereNotNull('issued_at')
            ->where('issued_at', '>=', now()->subMonths(11)->startOfMonth())
            ->get(['issued_at', 'total'])
            ->groupBy(fn (TaxInvoice $inv) => $inv->issued_at->format('Y-m'))
            ->map(fn ($group, $ym) => ['period' => $ym, 'total' => (float) $group->sum('total')])
            ->sortKeys()
            ->values()
            ->all();

        $monthlyExpenses = ExpenseEntry::query()
            ->where('expense_date', '>=', now()->subMonths(11)->startOfMonth())
            ->get(['expense_date', 'total_amount'])
            ->groupBy(fn (ExpenseEntry $e) => $e->expense_date->format('Y-m'))
            ->map(fn ($group, $ym) => ['period' => $ym, 'total' => (float) $group->sum('total_amount')])
            ->sortKeys()
            ->values()
            ->all();

        return Inertia::render('Finance/Dashboard', [
            'filters' => [
                'date_from' => $dateFrom->toDateString(),
                'date_to' => $dateTo->toDateString(),
            ],
            'summary' => [
                'invoiced_total' => $invoicedTotal,
                'vat_collected' => $vatCollected,
                'payments_collected' => $paymentsCollected,
                'expense_total' => $expenseTotal,
                'expenses_paid' => $expensesPaid,
                'payroll_paid_in_range' => $payrollPaid,
                'net_operating' => $paymentsCollected - $expensesPaid - $payrollPaid,
            ],
            'accountsReceivable' => $accountsReceivable,
            'accountsPayable' => [
                'expenses' => $accountsPayableExpenses,
                'purchase_orders' => $accountsPayablePos,
            ],
            'periodic' => [
                'income_by_month' => $monthlyIncome,
                'expenses_by_month' => $monthlyExpenses,
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date'],
        ]);

        $dateFrom = Carbon::parse($data['date_from'])->startOfDay();
        $dateTo = Carbon::parse($data['date_to'])->endOfDay();

        $filename = 'finance-period-'.$dateFrom->toDateString().'-to-'.$dateTo->toDateString().'.csv';

        $invoices = TaxInvoice::query()
            ->where('status', TaxInvoice::STATUS_FINALIZED)
            ->whereBetween('issued_at', [$dateFrom, $dateTo])
            ->with('customer:id,name')
            ->orderBy('issued_at')
            ->get();

        $payments = InvoicePayment::query()
            ->whereBetween('paid_at', [$dateFrom, $dateTo])
            ->with('taxInvoice:id,invoice_number,customer_display_name')
            ->orderBy('paid_at')
            ->get();

        $expenses = ExpenseEntry::query()
            ->whereBetween('expense_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->orderBy('expense_date')
            ->get();

        return response()->streamDownload(function () use ($invoices, $payments, $expenses) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Section', 'Date', 'Reference', 'Party', 'Subtotal', 'VAT', 'Total', 'Method / Status']);

            foreach ($invoices as $inv) {
                fputcsv($out, [
                    'Invoice',
                    optional($inv->issued_at)?->format('Y-m-d H:i:s'),
                    $inv->invoice_number,
                    $inv->customer_display_name,
                    $inv->subtotal,
                    $inv->vat_amount,
                    $inv->total,
                    $inv->status,
                ]);
            }

            foreach ($payments as $pay) {
                fputcsv($out, [
                    'Payment',
                    $pay->paid_at->format('Y-m-d H:i:s'),
                    $pay->taxInvoice?->invoice_number,
                    $pay->taxInvoice?->customer_display_name,
                    '',
                    '',
                    $pay->amount,
                    $pay->method,
                ]);
            }

            foreach ($expenses as $ex) {
                fputcsv($out, [
                    'Expense',
                    $ex->expense_date->format('Y-m-d'),
                    'EXP-'.$ex->id,
                    $ex->vendor_name ?? $ex->category,
                    $ex->amount_subtotal,
                    $ex->vat_amount,
                    $ex->total_amount,
                    $ex->payment_status,
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
