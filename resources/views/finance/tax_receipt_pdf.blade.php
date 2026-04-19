<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <style type="text/css">
        @page {
            margin: 0 !important;
        }
        @media print {
            @page {
                margin: 0 !important;
            }
        }
        * {
            box-sizing: border-box;
        }
        html {
            direction: ltr;
            margin: 0;
            padding: 0;
        }
        body {
            direction: ltr;
            font-family: DejaVu Sans, sans-serif;
            font-size: 7.5px;
            color: #111;
            margin: 0 !important;
            /* Left gutter unchanged; extra right inset stops DomPDF clipping decimals on narrow thermal. */
            padding: 4pt 14pt 5pt 8pt !important;
            width: 100%;
            max-width: 100%;
            text-align: left;
        }
        .logo-wrap {
            text-align: center;
            margin: 0 0 5pt;
        }
        .receipt-logo {
            display: block;
            margin: 0 auto;
            height: 76px;
            width: auto;
            max-width: 96%;
            object-fit: contain;
        }
        .center {
            text-align: center;
        }
        .muted {
            color: #444;
            font-size: 6.5px;
        }
        .title {
            font-size: 9px;
            font-weight: bold;
            margin: 3px 0;
        }
        table.items {
            width: 97%;
            max-width: 97%;
            margin-left: 0;
            margin-right: auto;
            table-layout: fixed;
            border-collapse: collapse;
            margin-top: 4px;
        }
        table.items th,
        table.items td {
            text-align: left;
            padding: 1px 0;
            border-bottom: 1px dashed #ccc;
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: anywhere;
        }
        table.items th {
            font-size: 5.5px;
            text-transform: uppercase;
        }
        table.items th.num,
        table.items td.num {
            padding-left: 3px;
            padding-right: 8px;
        }
        table.items .col-item {
            width: 44%;
        }
        table.items .col-qty {
            width: 8%;
        }
        table.items .col-line {
            width: 46%;
        }
        table.items .num {
            text-align: right;
            white-space: nowrap;
        }
        table.totals {
            width: 97%;
            max-width: 97%;
            margin-left: 0;
            margin-right: auto;
            table-layout: fixed;
            border-collapse: collapse;
            margin-top: 4px;
        }
        table.totals td {
            border: none;
            padding: 1px 0;
            font-size: 7.5px;
        }
        table.totals .num {
            text-align: right;
            white-space: nowrap;
            width: 38%;
            padding-left: 3px;
            padding-right: 8px;
        }
        table.totals .lbl {
            width: 62%;
            word-wrap: break-word;
            overflow-wrap: anywhere;
            padding-right: 2px;
        }
        .grand {
            font-weight: bold;
            font-size: 8.5px;
        }
        hr {
            border: none;
            border-top: 1px solid #333;
            margin: 5px 0;
            width: 97%;
            margin-left: 0;
            margin-right: auto;
        }
        .ar {
            font-family: DejaVu Sans, sans-serif;
        }
    </style>
</head>
<body>
    &#x200E;
    <div class="logo-wrap">{{ $logo_placeholder }}</div>

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

    <div class="center title" style="margin-top:5px;">Tax Receipt / <span class="ar">إيصال ضريبي</span></div>

    <div class="muted" style="margin-top:5px;">Customer / <span class="ar">العميل</span>: <strong>{{ $invoice->customer_display_name }}</strong></div>
    @if($settings->tax_registration_number)
        <div class="muted">TRN: {{ $settings->tax_registration_number }}</div>
    @endif
    <div class="muted">Invoice / <span class="ar">رقم الفاتورة</span>: <strong>{{ $invoice->invoice_number }}</strong></div>
    @if($invoice->cashier_name)
        <div class="muted">Cashier: {{ $invoice->cashier_name }}</div>
    @endif
    <div class="muted">Date / <span class="ar">التاريخ</span>: {{ $invoice->issued_at?->format('Y-m-d H:i:s') }}</div>

    <table class="items">
        <colgroup>
            <col class="col-item" />
            <col class="col-qty" />
            <col class="col-line" />
        </colgroup>
        <thead>
            <tr>
                <th>Item<br/><span class="ar" style="font-size:5.5px;">البند</span></th>
                <th class="num">Qty</th>
                <th class="num">Amt<br/><span class="ar" style="font-size:5.5px;">المبلغ</span></th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
                <tr>
                    <td>{{ $item->description }}</td>
                    <td class="num">{{ number_format((float) $item->quantity, 0) }}</td>
                    <td class="num">{{ number_format((float) $item->line_total, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <colgroup>
            <col class="lbl" />
            <col style="width:38%;" />
        </colgroup>
        <tr>
            <td class="lbl">Subtotal / <span class="ar">إجمالي الفاتورة</span></td>
            <td class="num">{{ number_format((float) $invoice->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td class="lbl">VAT ({{ number_format((float) $invoice->items->first()?->tax_rate_percent ?? 0, 2) }}%)</td>
            <td class="num">{{ number_format((float) $invoice->vat_amount, 2) }}</td>
        </tr>
        <tr class="grand">
            <td class="lbl">Total / <span class="ar">المجموع</span> ({{ $settings->currency_code }})</td>
            <td class="num">{{ number_format((float) $invoice->total, 2) }}</td>
        </tr>
    </table>

    @php
        $payments = $invoice->payments ?? collect();
    @endphp
    @if($payments->isNotEmpty())
        <div class="muted" style="margin-top:5px;">Payments / <span class="ar">المدفوعات</span>:</div>
        @foreach($payments as $p)
            <div class="muted">{{ ucfirst(str_replace('_', ' ', $p->method)) }}: {{ number_format((float) $p->amount, 2) }} @ {{ $p->paid_at?->format('Y-m-d H:i') }}</div>
        @endforeach
    @endif

    <hr>
    <div class="center muted">Thank you! Please come again.</div>
    <div class="center muted ar">نتمنى زيارتكم لنا مرة أخرى.</div>

    <style type="text/css">
        @page { margin: 0 !important; }
    </style>
</body>
</html>
