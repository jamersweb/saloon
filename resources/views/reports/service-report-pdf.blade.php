<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Service Reports</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 11px; }
        h1 { margin: 0 0 8px; font-size: 20px; }
        .muted { color: #475569; }
        .filters { margin: 8px 0 14px; }
        .grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .grid th, .grid td { border: 1px solid #cbd5e1; padding: 6px 7px; text-align: left; vertical-align: top; }
        .grid th { background: #f8fafc; font-size: 10px; text-transform: uppercase; color: #475569; }
        .date { width: 78px; }
        .customer { width: 120px; }
        .invoice { width: 92px; }
        .service { width: 120px; }
        .staff { width: 100px; }
        .report { white-space: pre-line; word-wrap: break-word; }
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

    <table class="grid">
        <thead>
            <tr>
                <th class="date">Date</th>
                <th class="customer">Customer</th>
                <th class="invoice">Invoice No.</th>
                <th class="service">Service</th>
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
                    <td>{{ $row['staff_name'] ?: '-' }}</td>
                    <td class="report">{{ $row['service_report'] ?: '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="6">No service reports found for the selected filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
