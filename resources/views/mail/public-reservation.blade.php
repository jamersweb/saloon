<h1>{{ $booking->status === 'confirmed' ? 'Your appointment is confirmed' : 'Your payment was received' }}</h1>
<p>Hello {{ $booking->customer_name }},</p>
@if($booking->status === 'payment_review')
<p>Your appointment needs management review. Please contact the salon; your payment will be retained for resolution.</p>
@endif
<p>{{ $booking->starts_at->format('l, j F Y · g:i A') }} – {{ $booking->ends_at->format('g:i A') }} ({{ config('app.timezone') }})</p>
<ul>@foreach($booking->services as $service)<li>@isset($service['guest']){{ $service['guest'] }} &middot; @endisset{{ $service['name'] }} · {{ $service['duration_minutes'] }} minutes · AED {{ number_format($service['price_minor']/100, 2) }}</li>@endforeach</ul>
<p>Total: AED {{ number_format($booking->total_minor/100, 2) }}<br>
15% deposit paid: AED {{ number_format($booking->deposit_minor/100, 2) }}<br>
Pay at the salon: AED {{ number_format(($booking->total_minor-$booking->deposit_minor)/100, 2) }}</p>
<p><a href="{{ route('public.reservation.show', $booking->token) }}">View or reschedule your appointment</a></p>
<p>Keep this private link safe. Changes within 24 hours require management approval.</p>
