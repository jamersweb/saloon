<?php

namespace App\Http\Controllers;

use App\Models\PayrollLine;
use App\Models\PayrollPeriod;
use App\Services\PayrollAttendanceService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            ->latest('period_end')
            ->paginate(20)
            ->through(fn (PayrollPeriod $p) => [
                'id' => $p->id,
                'period_start' => $p->period_start->toDateString(),
                'period_end' => $p->period_end->toDateString(),
                'status' => $p->status,
                'gross_total' => (float) ($p->lines_sum_gross_amount ?? 0),
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

        $payroll_period->load(['lines.staffProfile.user']);

        return Inertia::render('Finance/Payroll/Show', [
            'period' => [
                'id' => $payroll_period->id,
                'period_start' => $payroll_period->period_start->toDateString(),
                'period_end' => $payroll_period->period_end->toDateString(),
                'status' => $payroll_period->status,
                'notes' => $payroll_period->notes,
                'gross_total' => $payroll_period->totalGross(),
                'lines' => $payroll_period->lines->map(fn (PayrollLine $line) => [
                    'id' => $line->id,
                    'staff_profile_id' => $line->staff_profile_id,
                    'staff_name' => $line->staffProfile?->user?->name ?? 'Staff #'.$line->staff_profile_id,
                    'employee_code' => $line->staffProfile?->employee_code,
                    'hours_worked' => (float) $line->hours_worked,
                    'hourly_rate' => (float) $line->hourly_rate,
                    'gross_amount' => (float) $line->gross_amount,
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
            'hours_worked' => ['required', 'numeric', 'min:0', 'max:9999'],
            'hourly_rate' => ['required', 'numeric', 'min:0', 'max:99999'],
            'gross_amount' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $line->update($data);

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

        $payroll_period->update(['status' => PayrollPeriod::STATUS_PAID]);

        Audit::log($request->user()->id, 'finance.payroll.marked_paid', 'PayrollPeriod', $payroll_period->id, []);

        return back()->with('status', 'Payroll marked as paid.');
    }
}
