<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; margin: 0; padding: 8px; width: 72mm; }
        .center { text-align: center; }
        .muted { color: #444; font-size: 8px; }
        .title { font-size: 11px; font-weight: bold; margin: 6px 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th, td { text-align: left; padding: 2px 0; border-bottom: 1px dashed #ccc; }
        th { font-size: 7px; text-transform: uppercase; }
        .num { text-align: right; }
        .totals td { border: none; padding: 3px 0; font-size: 9px; }
        .grand { font-weight: bold; font-size: 10px; }
        hr { border: none; border-top: 1px solid #333; margin: 8px 0; }
    </style>
</head>
<body>
    <div class="center title">{{ $settings->business_name }}</div>
    @if($settings->address_line)
        <div class="center muted">{{ $settings->address_line }}</div>
    @endif
    @if($settings->phone)
        <div class="center muted">{{ $settings->phone }}</div>
    @endif
    @if($settings->email)
        <div class="center muted">{{ $settings->email }}</div>
    @endif

    <div class="center title" style="margin-top:10px;">Tax Receipt / إيصال ضريبي</div>

    <div class="muted" style="margin-top:8px;">Customer / العميل: <strong>{{ $invoice->customer_display_name }}</strong></div>
    @if($settings->tax_registration_number)
        <div class="muted">TRN: {{ $settings->tax_registration_number }}</div>
    @endif
    <div class="muted">Invoice / رقم الفاتورة: <strong>{{ $invoice->invoice_number }}</strong></div>
    @if($invoice->cashier_name)
        <div class="muted">Cashier: {{ $invoice->cashier_name }}</div>
    @endif
    <div class="muted">Date / التاريخ: {{ $invoice->issued_at?->format('Y-m-d H:i:s') }}</div>

    <table>
        <thead>
            <tr>
                <th>Item / البند</th>
                <th class="num">Qty</th>
                <th class="num">Price</th>
                <th class="num">Total</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ number_format((float) $item->quantity, 0) }}</td>
                    <td class="num">{{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="num">{{ number_format((float) $item->line_subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" style="margin-top:6px;">
        <tr>
            <td>Subtotal / إجمالي الفاتورة</td>
            <td class="num">{{ number_format((float) $invoice->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td>VAT ({{ number_format((float) $invoice->items->first()?->tax_rate_percent ?? 0, 2) }}%)</td>
            <td class="num">{{ number_format((float) $invoice->vat_amount, 2) }}</td>
        </tr>
        <tr class="grand">
            <td>Total / المجموع ({{ $settings->currency_code }})</td>
            <td class="num">{{ number_format((float) $invoice->total, 2) }}</td>
        </tr>
    </table>

    @php
        $payments = $invoice->payments ?? collect();
        $paidSum = $payments->sum('amount');
    @endphp
    @if($payments->isNotEmpty())
        <div class="muted" style="margin-top:8px;">Payments / المدفوعات:</div>
        @foreach($payments as $p)
            <div class="muted">{{ ucfirst(str_replace('_', ' ', $p->method)) }}: {{ number_format((float) $p->amount, 2) }} @ {{ $p->paid_at?->format('Y-m-d H:i') }}</div>
        @endforeach
    @endif

    <hr>
    <div class="center muted">Thank you! Please come again.</div>
    <div class="center muted">نتمنى زيارتكم لنا مرة أخرى.</div>
</body>
</html>
