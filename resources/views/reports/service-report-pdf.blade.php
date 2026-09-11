<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Service Reports</title>
    <style>
        @page { margin: 24px; }
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 9px; }
        .report-header { background: #111827; color: #fff; padding: 12px 14px; margin: -8px -8px 12px; }
        h1 { margin: 0 0 5px; font-size: 20px; font-weight: 800; }
        .muted { color: #475569; }
        .filters { margin: 8px 0 14px; }
        .cards { width: 100%; border-collapse: separate; border-spacing: 8px; margin: 4px 0 14px; table-layout: fixed; }
        .card { width: 25%; border: 1px solid #cbd5e1; padding: 8px; background: #f8fafc; }
        .card-label { color: #64748b; font-size: 9px; line-height: 1.25; text-transform: uppercase; font-weight: 800; }
        .card-value { font-size: 13px; line-height: 1.2; font-weight: 800; margin-top: 3px; white-space: normal; word-break: break-word; }
        .card-spacer { width: 25%; }
        .grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .grid th, .grid td { border: 1px solid #cbd5e1; padding: 4px; text-align: left; vertical-align: top; word-wrap: break-word; }
        .grid th { background: #e2e8f0; font-size: 8px; text-transform: uppercase; color: #0f172a; font-weight: 800; }
        .grid tr { page-break-inside: avoid; }
        .grid tfoot td { background: #fef3c7; font-weight: 800; }
        .date { width: 7%; }
        .customer { width: 11%; }
        .invoice { width: 7%; }
        .payment { width: 7%; }
        .service { width: 15%; }
        .qty { width: 4%; }
        .money { width: 6%; }
        .staff { width: 9%; }
        .report { white-space: pre-line; word-wrap: break-word; }
        .grid .right { text-align: right; }
    </style>
</head>
<body>
    <div class="report-header">
        <h1>Service Reports</h1>
        <div>Range: {{ $dateFrom->toDateString() }} to {{ $dateTo->toDateString() }}</div>
    </div>
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
                <div class="card-label">Appointments</div>
                <div class="card-value">{{ number_format((int) $totals['service_count']) }}</div>
            </td>
            <td class="card">
                <div class="card-label">Item Qty</div>
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
        </tr>
        <tr>
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
            <td class="card">
                <div class="card-label">Gift Voucher Payment</div>
                <div class="card-value">{{ $currencyCode }} {{ number_format((float) $totals['gift_card_total_payment'], 2) }}</div>
            </td>
        </tr>
        <tr>
            <td class="card">
                <div class="card-label">Package Credit Payment</div>
                <div class="card-value">{{ $currencyCode }} {{ number_format((float) $totals['package_credit_total_payment'], 2) }}</div>
            </td>
            <td class="card">
                <div class="card-label">Other Payments</div>
                <div class="card-value">{{ $currencyCode }} {{ number_format((float) $totals['other_total_payment'], 2) }}</div>
            </td>
            <td class="card">
                <div class="card-label">Total Payments</div>
                <div class="card-value">{{ $currencyCode }} {{ number_format((float) $totals['total_payment'], 2) }}</div>
            </td>
            <td class="card-spacer"></td>
        </tr>
    </table>

    <p class="muted">Amounts in {{ $currencyCode }}. Each billed item is shown separately; subtotal is quantity &times; unit price less discount. Payments include all recorded payments for the listed invoices, including payments made after the service date.</p>

    <table class="grid">
        <thead>
            <tr>
                <th class="date">Date</th>
                <th class="customer">Customer</th>
                <th class="invoice">Invoice No.</th>
                <th class="payment">Payment</th>
                <th class="service">Items</th>
                <th class="qty">Qty</th>
                <th class="money">Unit Price</th>
                <th class="money">Discount</th>
                <th class="money">Subtotal</th>
                <th class="money">Tax</th>
                <th class="money">Final Earning</th>
                <th class="staff">Staff</th>
                <th>Service Report</th>
            </tr>
        </thead>
        <tbody>
            @php
                $reportGroups = collect($serviceReports)
                    ->groupBy(function (array $appointmentRow): string {
                        $invoiceNumber = trim((string) ($appointmentRow['invoice_number'] ?? ''));
                        $invoiceKey = $invoiceNumber !== ''
                            ? 'invoice-'.$invoiceNumber
                            : 'appointment-'.($appointmentRow['appointment_id'] ?? $appointmentRow['id']);

                        return implode('|', [
                            $appointmentRow['date'] ?? '',
                            $appointmentRow['customer_name'] ?? '',
                            $appointmentRow['customer_phone'] ?? '',
                            $invoiceKey,
                        ]);
                    })
                    ->values();
            @endphp
            @forelse($reportGroups as $reportGroup)
                @php
                    $groupRow = $reportGroup->first();
                    $groupItems = $reportGroup
                        ->flatMap(fn (array $appointmentRow): array => $appointmentRow['items'] ?? [$appointmentRow])
                        ->values();
                    $groupServiceReport = $reportGroup
                        ->pluck('service_report')
                        ->filter(fn ($value): bool => trim((string) $value) !== '')
                        ->unique()
                        ->implode("\n");
                @endphp
                @foreach($groupItems as $rowIndex => $row)
                <tr>
                    @if($rowIndex === 0)
                        <td rowspan="{{ count($groupItems) }}">{{ $groupRow['date'] }}</td>
                        <td rowspan="{{ count($groupItems) }}">
                            {{ $groupRow['customer_name'] }}
                            @if(! empty($groupRow['customer_phone']))
                                <br><span class="muted">{{ $groupRow['customer_phone'] }}</span>
                            @endif
                        </td>
                        <td rowspan="{{ count($groupItems) }}">{{ $groupRow['invoice_number'] ?: '-' }}</td>
                        <td rowspan="{{ count($groupItems) }}">{{ $groupRow['payment_method'] ?: '-' }}</td>
                    @endif
                    <td>{{ $row['service_name'] ?: '-' }}</td>
                    <td class="right">{{ rtrim(rtrim(number_format((float) $row['quantity'], 2), '0'), '.') }}</td>
                    <td class="right">{{ number_format((float) $row['unit_price'], 2) }}</td>
                    <td class="right">{{ number_format((float) $row['discount_amount'], 2) }}</td>
                    <td class="right">{{ number_format((float) $row['subtotal'], 2) }}</td>
                    <td class="right">{{ number_format((float) $row['tax'], 2) }}</td>
                    <td class="right">{{ number_format((float) $row['total'], 2) }}</td>
                    <td>{{ $row['staff_name'] ?: '-' }}</td>
                    @if($rowIndex === 0)
                        <td rowspan="{{ count($groupItems) }}" class="report">{{ $groupServiceReport ?: '-' }}</td>
                    @endif
                </tr>
                @endforeach
            @empty
                <tr><td colspan="13">No report rows found for the selected filters.</td></tr>
            @endforelse
        </tbody>
        @if(count($serviceReports) > 0)
            <tfoot>
                <tr>
                    <td colspan="5">Report total</td>
                    <td class="right">{{ rtrim(rtrim(number_format((float) $totals['service_quantity'], 2), '0'), '.') }}</td>
                    <td></td>
                    <td></td>
                    <td class="right">{{ number_format((float) $totals['subtotal'], 2) }}</td>
                    <td class="right">{{ number_format((float) $totals['tax'], 2) }}</td>
                    <td class="right">{{ number_format((float) $totals['total'], 2) }}</td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        @endif
    </table>
</body>
</html>
