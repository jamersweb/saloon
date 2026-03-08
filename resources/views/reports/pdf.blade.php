<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Vina Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 12px; }
        h1 { margin: 0 0 8px; font-size: 20px; }
        h2 { margin: 18px 0 8px; font-size: 14px; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; }
        .muted { color: #475569; }
        .grid { width: 100%; border-collapse: collapse; }
        .grid th, .grid td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
        .grid th { background: #f8fafc; }
        .cards { width: 100%; border-collapse: separate; border-spacing: 8px; margin-top: 6px; }
        .card { border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px; }
        .card-label { font-size: 10px; text-transform: uppercase; color: #64748b; }
        .card-value { font-size: 16px; font-weight: 700; margin-top: 4px; }
    </style>
</head>
<body>
    <h1>Vina Operations Report</h1>
    <p class="muted">Range: {{ $dateFrom->toDateString() }} to {{ $dateTo->toDateString() }}</p>

    <h2>Overview</h2>
    <table class="cards">
        <tr>
            @foreach($overview as $key => $value)
                <td class="card">
                    <div class="card-label">{{ str_replace('_', ' ', $key) }}</div>
                    <div class="card-value">{{ $key === 'completed_revenue' ? '$' . number_format((float) $value, 2) : $value }}</div>
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
                    <td>{{ $total }}</td>
                </tr>
            @empty
                <tr><td colspan="2">No data</td></tr>
            @endforelse
        </tbody>
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
                    <td>{{ $row['total'] }}</td>
                    <td>${{ number_format((float) $row['revenue'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="3">No data</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Top Staff</h2>
    <table class="grid">
        <thead>
            <tr><th>Staff</th><th>Appointments</th></tr>
        </thead>
        <tbody>
            @forelse($staffPerformance as $row)
                <tr>
                    <td>{{ $row['staff_name'] }}</td>
                    <td>{{ $row['total'] }}</td>
                </tr>
            @empty
                <tr><td colspan="2">No data</td></tr>
            @endforelse
        </tbody>
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
                    <td>${{ number_format((float) $row['revenue'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="2">No data</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
