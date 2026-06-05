<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Service Reports</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 10px; }
        h1 { margin: 0 0 8px; font-size: 20px; }
        .muted { color: #475569; }
        .filters { margin: 8px 0 14px; }
        .cards { width: 100%; border-collapse: separate; border-spacing: 8px; margin: 4px 0 14px; }
        .card { border: 1px solid #cbd5e1; padding: 8px; }
        .card-label { color: #64748b; font-size: 9px; text-transform: uppercase; }
        .card-value { font-size: 14px; font-weight: 700; margin-top: 3px; }
        .grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .grid th, .grid td { border: 1px solid #cbd5e1; padding: 5px 6px; text-align: left; vertical-align: top; }
        .grid th { background: #f8fafc; font-size: 9px; text-transform: uppercase; color: #475569; }
        .grid tfoot td { background: #f8fafc; font-weight: 700; }
        .date { width: 66px; }
        .customer { width: 102px; }
        .invoice { width: 78px; }
        .service { width: 110px; }
        .qty { width: 34px; text-align: right; }
        .money { width: 72px; text-align: right; }
        .staff { width: 80px; }
        .report { white-space: pre-line; word-wrap: break-word; }
        .right { text-align: right; }
    </style>
</head>
<body>
    <h1>Service Reports</h1>
    <p class="muted">Range: {{ $dateFrom->toDateString() }} to {{ $dateTo->toDateString() }}</p>
    <div class="filters muted">
        @if($filters['customer_name'] !== '')
            Customer: {{ $filters['customer_name'] }}
        @endif
        @if($filters['invoice_number'] !== '')
            @if($filters['customer_name'] !== '') | @endif
            Invoice No.: {{ $filters['invoice_number'] }}
        @endif
    </div>

    <table class="cards">
        <tr>
            <td class="card">
                <div class="card-label">Services</div>
                <div class="card-value">{{ number_format((int) $totals['service_count']) }}</div>
            </td>
            <td class="card">
                <div class="card-label">Service Qty</div>
                <div class="card-value">{{ rtrim(rtrim(number_format((float) $totals['service_quantity'], 2), '0'), '.') }}</div>
            </td>
            <td class="card">
                <div class="card-label">Subtotal</div>
                <div class="card-value">{{ $currencyCode }} {{ number_format((float) $totals['subtotal'], 2) }}</div>
            </td>
            <td class="card">
                <div class="card-label">Tax</div>
                <div class="card-value">{{ $currencyCode }} {{ number_format((float) $totals['tax'], 2) }}</div>
            </td>
            <td class="card">
                <div class="card-label">Final Earning</div>
                <div class="card-value">{{ $currencyCode }} {{ number_format((float) $totals['total'], 2) }}</div>
            </td>
            <td class="card">
                <div class="card-label">Cash Total Payment</div>
                <div class="card-value">{{ $currencyCode }} {{ number_format((float) $totals['cash_total_payment'], 2) }}</div>
            </td>
            <td class="card">
                <div class="card-label">Card Total Payment</div>
                <div class="card-value">{{ $currencyCode }} {{ number_format((float) $totals['card_total_payment'], 2) }}</div>
            </td>
        </tr>
    </table>

    <table class="grid">
        <thead>
            <tr>
                <th class="date">Date</th>
                <th class="customer">Customer</th>
                <th class="invoice">Invoice No.</th>
                <th class="service">Service</th>
                <th class="qty">Qty</th>
                <th class="money">Amount</th>
                <th class="money">Subtotal</th>
                <th class="money">Tax</th>
                <th class="money">Final Earning</th>
                <th class="staff">Staff</th>
                <th>Service Report</th>
            </tr>
        </thead>
        <tbody>
            @forelse($serviceReports as $row)
                <tr>
                    <td>{{ $row['date'] }}</td>
                    <td>
                        {{ $row['customer_name'] }}
                        @if(! empty($row['customer_phone']))
                            <br><span class="muted">{{ $row['customer_phone'] }}</span>
                        @endif
                    </td>
                    <td>{{ $row['invoice_number'] ?: '-' }}</td>
                    <td>{{ $row['service_name'] ?: '-' }}</td>
                    <td class="right">{{ rtrim(rtrim(number_format((float) $row['quantity'], 2), '0'), '.') }}</td>
                    <td class="right">{{ $currencyCode }} {{ number_format((float) $row['unit_price'], 2) }}</td>
                    <td class="right">{{ $currencyCode }} {{ number_format((float) $row['subtotal'], 2) }}</td>
                    <td class="right">{{ $currencyCode }} {{ number_format((float) $row['tax'], 2) }}</td>
                    <td class="right">{{ $currencyCode }} {{ number_format((float) $row['total'], 2) }}</td>
                    <td>{{ $row['staff_name'] ?: '-' }}</td>
                    <td class="report">{{ $row['service_report'] ?: '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="11">No service reports found for the selected filters.</td></tr>
            @endforelse
        </tbody>
        @if(count($serviceReports) > 0)
            <tfoot>
                <tr>
                    <td colspan="4">Report total</td>
                    <td class="right">{{ rtrim(rtrim(number_format((float) $totals['service_quantity'], 2), '0'), '.') }}</td>
                    <td></td>
                    <td class="right">{{ $currencyCode }} {{ number_format((float) $totals['subtotal'], 2) }}</td>
                    <td class="right">{{ $currencyCode }} {{ number_format((float) $totals['tax'], 2) }}</td>
                    <td class="right">{{ $currencyCode }} {{ number_format((float) $totals['total'], 2) }}</td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>
