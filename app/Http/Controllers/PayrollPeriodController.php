<?php

namespace App\Http\Controllers;

use App\Models\ExpenseEntry;
use App\Models\PayrollLine;
use App\Models\PayrollPeriod;
use App\Models\FinanceSetting;
use App\Services\PayrollAttendanceService;
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PayrollPeriodController extends Controller
{
    public function __construct(
        private PayrollAttendanceService $payrollAttendanceService
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $periods = PayrollPeriod::query()
            ->withSum('lines', 'gross_amount')
            ->withSum('lines', 'net_amount')
            ->latest('period_end')
            ->paginate(20)
            ->through(fn (PayrollPeriod $p) => [
                'id' => $p->id,
                'period_start' => $p->period_start->toDateString(),
                'period_end' => $p->period_end->toDateString(),
                'status' => $p->status,
                'gross_total' => (float) ($p->lines_sum_gross_amount ?? 0),
                'net_total' => (float) ($p->lines_sum_net_amount ?? 0),
                'notes' => $p->notes,
            ]);

        return Inertia::render('Finance/Payroll/Index', [
            'periods' => $periods,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $period = PayrollPeriod::query()->create([
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'status' => PayrollPeriod::STATUS_DRAFT,
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        Audit::log($request->user()->id, 'finance.payroll.period_created', 'PayrollPeriod', $period->id, []);

        return redirect()->route('finance.payroll.show', $period)->with('status', 'Payroll period created.');
    }

    public function show(Request $request, PayrollPeriod $payroll_period): Response
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $payroll_period->load(['lines.staffProfile.user', 'lines.financeExpenseEntry']);

        return Inertia::render('Finance/Payroll/Show', [
            'period' => [
                'id' => $payroll_period->id,
                'period_start' => $payroll_period->period_start->toDateString(),
                'period_end' => $payroll_period->period_end->toDateString(),
                'status' => $payroll_period->status,
                'notes' => $payroll_period->notes,
                'gross_total' => $payroll_period->totalGross(),
                'net_total' => $payroll_period->totalNet(),
                'lines' => $payroll_period->lines->map(fn (PayrollLine $line) => [
                    'id' => $line->id,
                    'staff_profile_id' => $line->staff_profile_id,
                    'staff_name' => $line->staffProfile?->user?->name ?? 'Staff #'.$line->staff_profile_id,
                    'employee_code' => $line->staffProfile?->employee_code,
                    'pay_basis' => $line->pay_basis,
                    'hours_worked' => (float) $line->hours_worked,
                    'hourly_rate' => (float) $line->hourly_rate,
                    'basic_salary' => (float) $line->basic_salary,
                    'gross_amount' => (float) $line->gross_amount,
                    'bonus_amount' => (float) $line->bonus_amount,
                    'deduction_amount' => (float) $line->deduction_amount,
                    'net_amount' => (float) $line->net_amount,
                    'payment_method' => $line->payment_method,
                    'paid_at' => $line->paid_at?->toIso8601String(),
                    'finance_expense_entry_id' => $line->finance_expense_entry_id,
                    'notes' => $line->notes,
                ]),
            ],
        ]);
    }

    public function generate(Request $request, PayrollPeriod $payroll_period): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        if ($payroll_period->status !== PayrollPeriod::STATUS_DRAFT) {
            return back()->withErrors(['payroll' => 'Only draft periods can be regenerated from attendance.']);
        }

        $this->payrollAttendanceService->syncLinesForPeriod($payroll_period);

        Audit::log($request->user()->id, 'finance.payroll.generated', 'PayrollPeriod', $payroll_period->id, []);

        return back()->with('status', 'Payroll lines updated from attendance hours.');
    }

    public function updateLine(Request $request, PayrollPeriod $payroll_period, PayrollLine $line): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        if ($line->payroll_period_id !== $payroll_period->id) {
            abort(404);
        }

        if ($payroll_period->status !== PayrollPeriod::STATUS_DRAFT) {
            return back()->withErrors(['payroll' => 'Lines are read-only after the period is locked or paid.']);
        }

        $data = $request->validate([
            'pay_basis' => ['required', Rule::in([PayrollLine::PAY_BASIS_HOURLY, PayrollLine::PAY_BASIS_FIXED])],
            'hours_worked' => ['required', 'numeric', 'min:0', 'max:9999'],
            'hourly_rate' => ['required', 'numeric', 'min:0', 'max:99999'],
            'basic_salary' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'bonus_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'deduction_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'payment_method' => ['required', Rule::in(array_keys(ExpenseEntryController::paymentMethods()))],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $basicSalary = $data['pay_basis'] === PayrollLine::PAY_BASIS_FIXED
            ? round((float) ($data['basic_salary'] ?? 0), 2)
            : round((float) $data['hours_worked'] * (float) $data['hourly_rate'], 2);
        $bonusAmount = round((float) ($data['bonus_amount'] ?? 0), 2);
        $deductionAmount = round((float) ($data['deduction_amount'] ?? 0), 2);
        $grossAmount = round($basicSalary + $bonusAmount, 2);
        $netAmount = round(max(0, $grossAmount - $deductionAmount), 2);

        $line->update([
            'pay_basis' => $data['pay_basis'],
            'hours_worked' => $data['hours_worked'],
            'hourly_rate' => $data['hourly_rate'],
            'basic_salary' => $basicSalary,
            'gross_amount' => $grossAmount,
            'bonus_amount' => $bonusAmount,
            'deduction_amount' => $deductionAmount,
            'net_amount' => $netAmount,
            'payment_method' => $data['payment_method'],
            'notes' => $data['notes'] ?? null,
        ]);

        Audit::log($request->user()->id, 'finance.payroll.line_updated', 'PayrollLine', $line->id, []);

        return back()->with('status', 'Payroll line saved.');
    }

    public function lock(Request $request, PayrollPeriod $payroll_period): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        if ($payroll_period->status !== PayrollPeriod::STATUS_DRAFT) {
            return back()->withErrors(['payroll' => 'Period is not in draft status.']);
        }

        $payroll_period->update(['status' => PayrollPeriod::STATUS_LOCKED]);

        Audit::log($request->user()->id, 'finance.payroll.locked', 'PayrollPeriod', $payroll_period->id, []);

        return back()->with('status', 'Payroll period locked for approval.');
    }

    public function markPaid(Request $request, PayrollPeriod $payroll_period): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        if (! in_array($payroll_period->status, [PayrollPeriod::STATUS_DRAFT, PayrollPeriod::STATUS_LOCKED], true)) {
            return back()->withErrors(['payroll' => 'Period is already marked paid.']);
        }

        DB::transaction(function () use ($request, $payroll_period): void {
            $payroll_period->loadMissing(['lines.staffProfile.user', 'lines.financeExpenseEntry']);

            $paidAt = now();
            foreach ($payroll_period->lines as $line) {
                $expense = $line->financeExpenseEntry ?: ExpenseEntry::query()->create([
                    'category' => 'payroll',
                    'expense_type' => 'operational',
                    'expense_subcategory' => 'payroll_salary',
                    'vendor_name' => $line->staffProfile?->user?->name ?? 'Payroll',
                    'expense_date' => $payroll_period->period_end->toDateString(),
                    'amount_subtotal' => (float) $line->net_amount,
                    'vat_amount' => 0,
                    'total_amount' => (float) $line->net_amount,
                    'payment_status' => ExpenseEntry::STATUS_PAID,
                    'payment_method' => $line->payment_method ?: 'bank_transfer',
                    'approval_status' => ExpenseEntry::APPROVAL_APPROVED,
                    'paid_at' => $paidAt,
                    'staff_profile_id' => $line->staff_profile_id,
                    'approved_by' => $request->user()->id,
                    'approved_at' => $paidAt,
                    'notes' => sprintf(
                        'Payroll for %s to %s',
                        $payroll_period->period_start->toDateString(),
                        $payroll_period->period_end->toDateString()
                    ),
                    'created_by' => $request->user()->id,
                ]);

                if ($line->financeExpenseEntry) {
                    $expense->update([
                        'vendor_name' => $line->staffProfile?->user?->name ?? 'Payroll',
                        'expense_date' => $payroll_period->period_end->toDateString(),
                        'amount_subtotal' => (float) $line->net_amount,
                        'total_amount' => (float) $line->net_amount,
                        'payment_method' => $line->payment_method ?: 'bank_transfer',
                        'payment_status' => ExpenseEntry::STATUS_PAID,
                        'approval_status' => ExpenseEntry::APPROVAL_APPROVED,
                        'paid_at' => $paidAt,
                        'staff_profile_id' => $line->staff_profile_id,
                        'approved_by' => $request->user()->id,
                        'approved_at' => $paidAt,
                    ]);
                }

                $line->update([
                    'paid_at' => $paidAt,
                    'finance_expense_entry_id' => $expense->id,
                ]);
            }

            $payroll_period->update(['status' => PayrollPeriod::STATUS_PAID]);
        });

        Audit::log($request->user()->id, 'finance.payroll.marked_paid', 'PayrollPeriod', $payroll_period->id, []);

        return back()->with('status', 'Payroll marked as paid.');
    }

    public function payslipPdf(Request $request, PayrollPeriod $payroll_period, PayrollLine $line)
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        if ($line->payroll_period_id !== $payroll_period->id) {
            abort(404);
        }

        $line->loadMissing('staffProfile.user');
        $currencyCode = FinanceSetting::current()->currency_code ?: 'AED';

        return Pdf::loadView('finance.payroll-payslip-pdf', [
            'period' => $payroll_period,
            'line' => $line,
            'currencyCode' => $currencyCode,
        ])->setPaper('a4', 'portrait')->download(sprintf(
            'payslip-%s-%s.pdf',
            $line->staffProfile?->employee_code ?? $line->id,
            $payroll_period->period_end->toDateString()
        ));
    }
}
