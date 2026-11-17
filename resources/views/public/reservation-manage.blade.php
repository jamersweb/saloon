<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="referrer" content="no-referrer"><title>Your appointment · VINA</title><link rel="stylesheet" href="{{ asset('css/public-booking.css') }}"></head>
<body><div class="booking-shell"><header class="booking-header"><a class="wordmark" href="{{ route('public.booking') }}"><img class="booking-logo" src="{{ asset('images/vina-site-logo.png') }}" alt="Vina Luxury Beauty Salon"></a></header><main class="manage">
<h1>{{ match($booking->status) { 'confirmed' => 'Your appointment is confirmed', 'cancelled' => 'Payment was not completed', 'payment_review' => 'Your payment was received', default => 'Your appointment is awaiting payment' } }}</h1>
@if($booking->status === 'awaiting_payment')
<p class="notice">We are checking your deposit with Nomod. Your appointment is confirmed only after payment is verified. You can refresh this page to check again.</p>
@if($booking->checkout_url)<p><a href="{{ $booking->checkout_url }}">Continue secure payment</a></p>@endif
@elseif($booking->status === 'payment_review')<p class="notice">This time needs management review. Please contact the salon to arrange your appointment or resolve your deposit.</p>
@elseif($booking->status === 'cancelled')<p>Please <a href="{{ route('public.booking') }}">choose a new appointment</a> when you are ready.</p>@endif
<p>{{ $booking->customer_name }}, your appointment details:</p>
<h2>{{ $booking->starts_at->format('l, j F Y') }}</h2><p>{{ $booking->starts_at->format('g:i A') }} – {{ $booking->ends_at->format('g:i A') }} · {{ config('app.timezone') }}<br>{{ $booking->duration_minutes }} minutes including preparation</p>
<ul class="manage-services">@foreach($booking->services as $service)<li>@isset($service['guest']){{ $service['guest'] }} &middot; @endisset{{ $service['name'] }} · {{ $service['duration_minutes'] }} min · AED {{ number_format($service['price_minor']/100, 2) }}</li>@endforeach</ul>
<dl class="totals"><div class="total-row"><dt>Total</dt><dd>AED {{ number_format($booking->total_minor/100, 2) }}</dd></div><div><dt>15% deposit {{ $booking->paid_at ? 'paid' : 'due' }}</dt><dd>AED {{ number_format($booking->deposit_minor/100, 2) }}</dd></div><div><dt>At the salon</dt><dd>AED {{ number_format(($booking->total_minor-$booking->deposit_minor)/100, 2) }}</dd></div></dl>
@if($booking->status === 'confirmed' && $booking->starts_at->isFuture())
<section class="booking-section"><h2>Reschedule your appointment</h2><p class="muted">Choose a new date within 14 days of the original appointment. Your deposit transfers automatically. Changes within 24 hours require management approval.</p>
@if($booking->requested_start)<p class="notice">Requested: {{ $booking->requested_start->format('j F Y, g:i A') }}. Awaiting management approval. Your original appointment is still reserved.</p>@endif
<label class="date-field">New date<input id="move-date" type="date" min="{{ max(today()->toDateString(), $booking->original_starts_at->copy()->subDays(14)->toDateString()) }}" max="{{ $booking->original_starts_at->copy()->addDays(14)->toDateString() }}"></label>
<div id="move-slots" class="slots"></div><p id="move-status" role="status" class="muted"></p><button class="primary" id="move-button" disabled>{{ $booking->starts_at->lt(now()->addHours(24)) ? 'Request management approval' : 'Confirm new time' }}</button></section>
<script id="reschedule-data" type="application/json">{!! json_encode(['token' => $booking->token, 'availabilityUrl' => route('public.reservation.availability'), 'rescheduleUrl' => route('public.reservation.reschedule', $booking->token)], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
<script src="{{ asset('js/public-reschedule.js') }}" defer></script>
@endif
<p class="muted small">Keep this link private. Anyone with this link can view and request changes to your appointment.</p>
</main></div></body></html>
