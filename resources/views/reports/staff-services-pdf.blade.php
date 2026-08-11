<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Services Report</title>
    <style>
        @page { margin: 18px 20px 28px; }
        body { font-family: DejaVu Sans, sans-serif; color: #000; font-size: 8px; }
        .report-header { margin-bottom: 12px; text-align: center; }
        h1 { margin: 0 0 6px; font-size: 18px; font-weight: 700; }
        .subtitle { font-size: 10px; }
        h2 { margin: 14px 0 0; font-size: 12px; font-weight: 700; }
        .grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .grid th, .grid td { border: 1px solid #000; padding: 4px 4px; vertical-align: top; word-wrap: break-word; overflow-wrap: anywhere; }
        .grid th { font-size: 7px; text-transform: uppercase; font-weight: 700; text-align: center; }
        .staff-total td,
        .grand-total td { font-weight: 700; }
        .right { text-align: right; white-space: nowrap; }
        .center { text-align: center; }
        .footer { position: fixed; bottom: -20px; left: 0; right: 0; text-align: center; font-size: 8px; }
        .page-break { page-break-before: always; }
        .row-type { width: 58px; }
        .staff { width: 100px; }
        .service { width: 142px; }
        .count { width: 56px; }
        .qty { width: 48px; }
        .money { width: 62px; }
        .avg { width: 66px; }
        .percent { width: 64px; }
    </style>
</head>
<body>
    <div class="report-header">
        <h1>Staff Services Report</h1>
        <div class="subtitle">Range: {{ $dateFrom->toDateString() }} to {{ $dateTo->toDateString() }}</div>
        <div class="subtitle">Generated: {{ now()->format('Y-m-d H:i:s') }}</div>
    </div>

    <h2>Staff Summary</h2>
    <table class="grid">
        <thead>
            <tr>
                <th class="row-type">Row Type</th>
                <th class="staff">Staff</th>
                <th class="service">Service</th>
                <th class="count">Completed Lines</th>
                <th class="qty">Quantity</th>
                <th class="money">Subtotal</th>
                <th class="money">Discount</th>
                <th class="money">VAT</th>
                <th class="money">Sales Total</th>
                <th class="avg">Avg Sale / Line</th>
                <th class="percent">% of Month Sales</th>
            </tr>
        </thead>
        <tbody>
            @forelse($staffTotalsRows as $row)
                <tr class="summary-row">
                    <td>Staff Summary</td>
                    <td>{{ $row['staff_name'] }}</td>
                    <td>All services</td>
                    <td class="right">{{ number_format((int) $row['service_count']) }}</td>
                    <td class="right">{{ rtrim(rtrim(number_format((float) $row['quantity'], 2), '0'), '.') }}</td>
                    <td class="right">{{ number_format((float) $row['subtotal'], 2) }}</td>
                    <td class="right">{{ number_format((float) $row['discount_amount'], 2) }}</td>
                    <td class="right">{{ number_format((float) $row['tax'], 2) }}</td>
                    <td class="right">{{ number_format((float) $row['total'], 2) }}</td>
                    <td class="right">{{ number_format((float) $row['avg_sale_per_line'], 2) }}</td>
                    <td class="right">{{ number_format((float) $row['sales_percent'], 2) }}%</td>
                </tr>
            @empty
                <tr><td colspan="11" class="center">No staff service rows found for the selected date range.</td></tr>
            @endforelse
            @if(count($staffTotalsRows) > 0)
                <tr class="grand-total">
                    <td>Grand Total</td>
                    <td>Grand Total</td>
                    <td>All staff services</td>
                    <td class="right">{{ number_format((int) $grandTotal['service_count']) }}</td>
                    <td class="right">{{ rtrim(rtrim(number_format((float) $grandTotal['quantity'], 2), '0'), '.') }}</td>
                    <td class="right">{{ number_format((float) $grandTotal['subtotal'], 2) }}</td>
                    <td class="right">{{ number_format((float) $grandTotal['discount_amount'], 2) }}</td>
                    <td class="right">{{ number_format((float) $grandTotal['tax'], 2) }}</td>
                    <td class="right">{{ number_format((float) $grandTotal['total'], 2) }}</td>
                    <td class="right">{{ number_format((float) $grandTotal['avg_sale_per_line'], 2) }}</td>
                    <td class="right">{{ number_format((float) $grandTotal['sales_percent'], 2) }}%</td>
                </tr>
            @endif
        </tbody>
    </table>

    <h2 class="page-break">Service Details</h2>
    <table class="grid">
        <thead>
            <tr>
                <th class="row-type">Row Type</th>
                <th class="staff">Staff</th>
                <th class="service">Service</th>
                <th class="count">Completed Lines</th>
                <th class="qty">Quantity</th>
                <th class="money">Subtotal</th>
                <th class="money">Discount</th>
                <th class="money">VAT</th>
                <th class="money">Sales Total</th>
                <th class="avg">Avg Sale / Line</th>
                <th class="percent">% of Month Sales</th>
            </tr>
        </thead>
        <tbody>
            @forelse(collect($serviceRows)->groupBy('staff_name') as $staffName => $rows)
                @foreach($rows as $row)
                    <tr>
                        <td>Detail</td>
                        <td>{{ $row['staff_name'] }}</td>
                        <td>{{ $row['service_name'] }}</td>
                        <td class="right">{{ number_format((int) $row['service_count']) }}</td>
                        <td class="right">{{ rtrim(rtrim(number_format((float) $row['quantity'], 2), '0'), '.') }}</td>
                        <td class="right">{{ number_format((float) $row['subtotal'], 2) }}</td>
                        <td class="right">{{ number_format((float) $row['discount_amount'], 2) }}</td>
                        <td class="right">{{ number_format((float) $row['tax'], 2) }}</td>
                        <td class="right">{{ number_format((float) $row['total'], 2) }}</td>
                        <td class="right">{{ number_format((float) $row['avg_sale_per_line'], 2) }}</td>
                        <td class="right">{{ number_format((float) $row['sales_percent'], 2) }}%</td>
                    </tr>
                @endforeach

                @php($staffTotal = collect($staffTotalsRows)->firstWhere('staff_name', (string) $staffName))
                @if($staffTotal)
                    <tr class="staff-total">
                        <td>Staff Total</td>
                        <td>{{ $staffTotal['staff_name'] }}</td>
                        <td>All services</td>
                        <td class="right">{{ number_format((int) $staffTotal['service_count']) }}</td>
                        <td class="right">{{ rtrim(rtrim(number_format((float) $staffTotal['quantity'], 2), '0'), '.') }}</td>
                        <td class="right">{{ number_format((float) $staffTotal['subtotal'], 2) }}</td>
                        <td class="right">{{ number_format((float) $staffTotal['discount_amount'], 2) }}</td>
                        <td class="right">{{ number_format((float) $staffTotal['tax'], 2) }}</td>
                        <td class="right">{{ number_format((float) $staffTotal['total'], 2) }}</td>
                        <td class="right">{{ number_format((float) $staffTotal['avg_sale_per_line'], 2) }}</td>
                        <td class="right">{{ number_format((float) $staffTotal['sales_percent'], 2) }}%</td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="11" class="center">No staff service details found for the selected date range.</td></tr>
            @endforelse
            @if(count($serviceRows) > 0)
                <tr class="grand-total">
                    <td>Grand Total</td>
                    <td>Grand Total</td>
                    <td>All staff services</td>
                    <td class="right">{{ number_format((int) $grandTotal['service_count']) }}</td>
                    <td class="right">{{ rtrim(rtrim(number_format((float) $grandTotal['quantity'], 2), '0'), '.') }}</td>
                    <td class="right">{{ number_format((float) $grandTotal['subtotal'], 2) }}</td>
                    <td class="right">{{ number_format((float) $grandTotal['discount_amount'], 2) }}</td>
                    <td class="right">{{ number_format((float) $grandTotal['tax'], 2) }}</td>
                    <td class="right">{{ number_format((float) $grandTotal['total'], 2) }}</td>
                    <td class="right">{{ number_format((float) $grandTotal['avg_sale_per_line'], 2) }}</td>
                    <td class="right">{{ number_format((float) $grandTotal['sales_percent'], 2) }}%</td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="footer">Staff Services Report | {{ $dateFrom->toDateString() }} to {{ $dateTo->toDateString() }}</div>
</body>
</html>
