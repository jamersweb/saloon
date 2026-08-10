<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Vina Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 12px; }
        .report-header { background: #111827; color: #fff; padding: 14px 16px; margin: -8px -8px 14px; }
        h1 { margin: 0 0 5px; font-size: 22px; font-weight: 800; }
        h2 { margin: 20px 0 0; font-size: 14px; background: #111827; color: #fff; padding: 8px 10px; font-weight: 800; }
        .muted { color: #475569; }
        .grid { width: 100%; border-collapse: collapse; }
        .grid th, .grid td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
        .grid th { background: #e2e8f0; color: #0f172a; font-size: 10px; font-weight: 800; text-transform: uppercase; }
        .grid tfoot td { background: #fef3c7; font-weight: 800; }
        .cards { width: 100%; border-collapse: separate; border-spacing: 8px; margin-top: 6px; }
        .card { border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px; background: #f8fafc; }
        .card-label { font-size: 10px; text-transform: uppercase; color: #64748b; font-weight: 800; }
        .card-value { font-size: 16px; font-weight: 800; margin-top: 4px; }
        .right { text-align: right; }
    </style>
</head>
<body>
    @php
        $statusTotal = array_sum($statusBreakdown);
        $serviceCountTotal = collect($servicePerformance)->sum('total');
        $serviceRevenueTotal = collect($servicePerformance)->sum('revenue');
        $staffCountTotal = collect($staffPerformance)->sum('total');
        $staffRevenueTotal = collect($staffPerformance)->sum('revenue');
        $dailyRevenueTotal = collect($dailyRevenue)->sum('revenue');
    @endphp

    <div class="report-header">
        <h1>Vina Operations Report</h1>
        <div>Range: {{ $dateFrom->toDateString() }} to {{ $dateTo->toDateString() }}</div>
    </div>

    <h2>Overview</h2>
    <table class="cards">
        <tr>
            @foreach($overview as $key => $value)
                <td class="card">
                    <div class="card-label">{{ str_replace('_', ' ', $key) }}</div>
                    <div class="card-value">{{ str_contains($key, 'revenue') || str_contains($key, 'payment') ? $currencyCode . ' ' . number_format((float) $value, 2) : $value }}</div>
                </td>
            @endforeach
        </tr>
    </table>

    <h2>Appointment Status</h2>
    <table class="grid">
        <thead>
            <tr><th>Status</th><th>Total</th></tr>
        </thead>
        <tbody>
            @forelse($statusBreakdown as $status => $total)
                <tr>
                    <td>{{ str_replace('_', ' ', $status) }}</td>
                    <td class="right">{{ $total }}</td>
                </tr>
            @empty
                <tr><td colspan="2">No data</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr><td>Total</td><td class="right">{{ $statusTotal }}</td></tr>
        </tfoot>
    </table>

    <h2>Top Services</h2>
    <table class="grid">
        <thead>
            <tr><th>Service</th><th>Appointments</th><th>Revenue</th></tr>
        </thead>
        <tbody>
            @forelse($servicePerformance as $row)
                <tr>
                    <td>{{ $row['service_name'] }}</td>
                    <td class="right">{{ $row['total'] }}</td>
                    <td class="right">{{ $currencyCode }} {{ number_format((float) $row['revenue'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="3">No data</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td class="right">{{ $serviceCountTotal }}</td>
                <td class="right">{{ $currencyCode }} {{ number_format((float) $serviceRevenueTotal, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <h2>Top Staff</h2>
    <table class="grid">
        <thead>
            <tr><th>Staff</th><th>Completed Lines</th><th>Sales</th></tr>
        </thead>
        <tbody>
            @forelse($staffPerformance as $row)
                <tr>
                    <td>{{ $row['staff_name'] }}</td>
                    <td class="right">{{ $row['total'] }}</td>
                    <td class="right">{{ $currencyCode }} {{ number_format((float) $row['revenue'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="3">No data</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td>Total</td>
                <td class="right">{{ $staffCountTotal }}</td>
                <td class="right">{{ $currencyCode }} {{ number_format((float) $staffRevenueTotal, 2) }}</td>
            </tr>
        </tfoot>
    </table>

    <h2>Daily Revenue</h2>
    <table class="grid">
        <thead>
            <tr><th>Date</th><th>Revenue</th></tr>
        </thead>
        <tbody>
            @forelse($dailyRevenue as $row)
                <tr>
                    <td>{{ $row['date'] }}</td>
                    <td class="right">{{ $currencyCode }} {{ number_format((float) $row['revenue'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="2">No data</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr><td>Total</td><td class="right">{{ $currencyCode }} {{ number_format((float) $dailyRevenueTotal, 2) }}</td></tr>
        </tfoot>
    </table>
</body>
</html>
