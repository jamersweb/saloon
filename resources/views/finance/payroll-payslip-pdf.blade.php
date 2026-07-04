<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payslip</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #1e293b; font-size: 12px; margin: 24px; }
        h1, h2, p { margin: 0; }
        .header { margin-bottom: 24px; }
        .muted { color: #64748b; }
        .grid { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .grid td, .grid th { border: 1px solid #e2e8f0; padding: 10px; }
        .grid th { background: #f8fafc; text-align: left; }
        .right { text-align: right; }
        .pill { display: inline-block; padding: 4px 10px; border-radius: 999px; background: #e2e8f0; font-size: 11px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Payslip</h1>
        <p class="muted">Payroll period: {{ $period->period_start->toDateString() }} to {{ $period->period_end->toDateString() }}</p>
        <p class="muted">Generated on: {{ now()->format('Y-m-d H:i') }}</p>
    </div>

    <table class="grid">
        <tr>
            <th>Staff</th>
            <td>{{ $line->staffProfile?->user?->name ?? 'Staff #'.$line->staff_profile_id }}</td>
            <th>Employee code</th>
            <td>{{ $line->staffProfile?->employee_code ?? '-' }}</td>
        </tr>
        <tr>
            <th>Pay basis</th>
            <td>{{ $line->pay_basis === \App\Models\PayrollLine::PAY_BASIS_FIXED ? 'Fixed salary' : 'Hourly' }}</td>
            <th>Status</th>
            <td><span class="pill">{{ ucfirst($period->status) }}</span></td>
        </tr>
        <tr>
            <th>Hours worked</th>
            <td>{{ number_format((float) $line->hours_worked, 2) }}</td>
            <th>Hourly rate</th>
            <td>{{ $currencyCode }} {{ number_format((float) $line->hourly_rate, 2) }}</td>
        </tr>
    </table>

    <table class="grid">
        <thead>
            <tr>
                <th>Description</th>
                <th class="right">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Basic salary</td>
                <td class="right">{{ $currencyCode }} {{ number_format((float) $line->basic_salary, 2) }}</td>
            </tr>
            <tr>
                <td>Bonuses / additions</td>
                <td class="right">{{ $currencyCode }} {{ number_format((float) $line->bonus_amount, 2) }}</td>
            </tr>
            <tr>
                <td>Gross pay</td>
                <td class="right">{{ $currencyCode }} {{ number_format((float) $line->gross_amount, 2) }}</td>
            </tr>
            <tr>
                <td>Deductions</td>
                <td class="right">- {{ $currencyCode }} {{ number_format((float) $line->deduction_amount, 2) }}</td>
            </tr>
            <tr>
                <th>Net pay</th>
                <th class="right">{{ $currencyCode }} {{ number_format((float) $line->net_amount, 2) }}</th>
            </tr>
        </tbody>
    </table>

    <table class="grid">
        <tr>
            <th>Payment method</th>
            <td>{{ ucfirst(str_replace('_', ' ', $line->payment_method)) }}</td>
            <th>Paid at</th>
            <td>{{ $line->paid_at?->format('Y-m-d H:i') ?? 'Pending payment' }}</td>
        </tr>
        <tr>
            <th>Notes</th>
            <td colspan="3">{{ $line->notes ?: '-' }}</td>
        </tr>
    </table>
</body>
</html>
