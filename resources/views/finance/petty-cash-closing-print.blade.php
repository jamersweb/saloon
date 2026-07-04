<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Petty Cash Closing</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #0f172a; margin: 24px; }
        h1 { margin: 0 0 8px; font-size: 24px; }
        h2 { margin: 24px 0 10px; font-size: 15px; border-bottom: 1px solid #cbd5e1; padding-bottom: 4px; }
        .muted { color: #475569; }
        .cards { width: 100%; border-collapse: separate; border-spacing: 10px; margin-top: 10px; }
        .card { border: 1px solid #cbd5e1; border-radius: 10px; padding: 10px; }
        .card-label { font-size: 10px; text-transform: uppercase; color: #64748b; }
        .card-value { font-size: 18px; font-weight: 700; margin-top: 4px; }
        .grid { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .grid th, .grid td { border: 1px solid #cbd5e1; padding: 7px 8px; text-align: left; }
        .grid th { background: #f8fafc; }
        .right { text-align: right; }
        @media print {
            body { margin: 10mm; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    @if(empty($forPdf))
    <div class="no-print" style="margin-bottom: 14px;">
        <button onclick="window.print()">Print</button>
    </div>
    @endif

    <h1>Petty Cash Closing Report</h1>
    <p class="muted">Range: {{ $report['filters']['date_from'] }} to {{ $report['filters']['date_to'] }}</p>

    <table class="cards">
        <tr>
            <td class="card">
                <div class="card-label">Opening Balance</div>
                <div class="card-value">{{ $currencyCode }} {{ number_format((float) $report['summary']['opening_balance'], 2) }}</div>
            </td>
            <td class="card">
                <div class="card-label">Issued</div>
                <div class="card-value">{{ $currencyCode }} {{ number_format((float) $report['summary']['issued_total'], 2) }}</div>
            </td>
            <td class="card">
                <div class="card-label">Spent</div>
                <div class="card-value">{{ $currencyCode }} {{ number_format((float) $report['summary']['spent_total'], 2) }}</div>
            </td>
            <td class="card">
                <div class="card-label">Closing Balance</div>
                <div class="card-value">{{ $currencyCode }} {{ number_format((float) $report['summary']['closing_balance'], 2) }}</div>
            </td>
        </tr>
    </table>

    <h2>Custodian Settlement</h2>
    <table class="grid">
        <thead>
            <tr>
                <th>Custodian</th>
                <th class="right">Opening</th>
                <th class="right">Issued</th>
                <th class="right">Spent</th>
                <th class="right">Closing</th>
            </tr>
        </thead>
        <tbody>
            @forelse($report['custodians'] as $row)
                <tr>
                    <td>{{ $row['custodian'] }}</td>
                    <td class="right">{{ number_format((float) $row['opening_balance'], 2) }}</td>
                    <td class="right">{{ number_format((float) $row['issued_total'], 2) }}</td>
                    <td class="right">{{ number_format((float) $row['spent_total'], 2) }}</td>
                    <td class="right">{{ number_format((float) $row['closing_balance'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="5">No petty cash custodians found for this range.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Transaction Log</h2>
    <table class="grid">
        <thead>
            <tr>
                <th>Date</th>
                <th>Custodian</th>
                <th>Type</th>
                <th>Direction</th>
                <th class="right">Amount</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            @forelse($report['transactions'] as $row)
                <tr>
                    <td>{{ $row['transaction_date'] }}</td>
                    <td>{{ $row['custodian'] }}</td>
                    <td>{{ ucfirst($row['transaction_type']) }}</td>
                    <td>{{ strtoupper($row['direction']) }}</td>
                    <td class="right">{{ number_format((float) $row['amount'], 2) }}</td>
                    <td>{{ $row['notes'] ?: '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="6">No petty cash transactions found for this range.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Sign Offs</h2>
    <table class="grid">
        <thead>
            <tr>
                <th>Date</th>
                <th>Custodian</th>
                <th class="right">Expected</th>
                <th class="right">Counted</th>
                <th class="right">Variance</th>
                <th>Signed Off By</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            @forelse($report['closings'] as $row)
                <tr>
                    <td>{{ $row['closing_date'] }}</td>
                    <td>{{ $row['custodian'] }}</td>
                    <td class="right">{{ number_format((float) $row['expected_closing_balance'], 2) }}</td>
                    <td class="right">{{ number_format((float) $row['counted_closing_balance'], 2) }}</td>
                    <td class="right">{{ number_format((float) $row['variance_amount'], 2) }}</td>
                    <td>{{ $row['signed_off_name'] ?: '-' }}</td>
                    <td>{{ $row['notes'] ?: '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="7">No sign-off records found for this range.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
