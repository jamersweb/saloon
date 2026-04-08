<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: system-ui, sans-serif; line-height: 1.5; color: #1e293b;">
    <p>Hello,</p>
    <p>Please find your tax receipt <strong>{{ $invoice->invoice_number }}</strong> attached (PDF).</p>
    <p><strong>Total:</strong> {{ number_format((float) $invoice->total, 2) }} {{ $settings->currency_code }}</p>
    @if($settings->phone)
        <p>Questions? Contact us at {{ $settings->phone }}.</p>
    @endif
    <p>Thanks,<br>{{ $settings->business_name }}</p>
</body>
</html>
