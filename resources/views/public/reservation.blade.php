<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Book your appointment · {{ config('public_booking.brand') }}</title>
    <link rel="stylesheet" href="{{ asset('css/public-booking.css') }}">
    <link rel="stylesheet" href="{{ asset('css/booking-studio.css') }}">
</head>
<body>
<div class="booking-shell">
    <header class="booking-header"><a class="wordmark" href="{{ route('public.booking') }}"><img class="booking-logo" src="{{ asset('images/vina-site-logo.png') }}" alt="Vina Luxury Beauty Salon"></a>
        <span id="step-label" aria-live="polite"></span>
    </header>
    <main>
        <div class="studio-intro"><div><h1>Book your <em>appointment.</em></h1><p>Choose your treatments and find a time that suits you.</p></div><div class="studio-photo" aria-hidden="true"></div></div>
        <section id="visit-options" class="visit-panel"><h2>Who are you booking for?</h2>
            <button type="button" data-visit="individual">Book for myself <small>Choose my services</small></button>
            <button type="button" data-visit="group">Book a group appointment <small>Choose services for me and my guests</small></button>
        </section>
        <button id="previous-step" type="button" class="wizard-back" hidden>&larr; Back</button>
        <section id="guest-roster" class="visit-panel" hidden><h2>Guests and services</h2><p>Up to 15 people. Treatments take place one after another with one professional.</p><div id="guest-list"></div><button id="add-guest" type="button">+ Add guest</button></section>
        <h2 id="editing-guest" hidden></h2>
        <nav id="categories" class="categories" aria-label="Service categories"></nav>
        <div class="booking-grid">
            <div class="booking-workspace">
                <section id="services" aria-label="Choose services">
                    <div class="catalogue-title"><h2 id="category-title">Services</h2><span id="category-count"></span></div>
                    <div id="service-list" class="service-list"></div>
                    <button id="more-services" class="more-services" type="button" hidden>Show more services</button>
                    <noscript>Please enable JavaScript to see services and available appointment times.</noscript>
                </section>
                <section id="schedule" class="booking-section">
                    <h2>Choose date & time</h2>
                    <p class="muted">All times are in {{ config('app.timezone') }}. Preparation time is included.</p>
                    <label class="date-field">Date<input id="booking-date" type="date" min="{{ $today }}" max="{{ $lastDate }}" value="{{ $today }}"></label>
                    <p id="slot-status" class="muted" role="status">Select your services to see available times.</p>
                    <div id="slots" class="slots" aria-label="Available appointment times"></div>
                    <p id="selected-time" class="selected-time" aria-live="polite"></p>
                </section>
                <section id="details" class="booking-section">
                    <h2>Your details</h2>
                    <form id="booking-form">
                        <div class="details-grid">
                            <label>Name<input name="customer_name" required autocomplete="name" maxlength="150" placeholder="Your full name"></label>
                            <label>Phone<input name="customer_phone" type="tel" required autocomplete="tel" maxlength="30" placeholder="+971 50 123 4567"></label>
                            <label class="email-field">Email<input name="customer_email" type="email" required autocomplete="email" maxlength="255" placeholder="you@example.com"></label>
                        </div>
                        <p class="muted">Your confirmation and private rescheduling link will be sent by email.</p>
                        <p class="policy">Move your appointment within 14 days of its original date. Changes within 24 hours require management approval. Your deposit transfers with your booking.</p>
                        <div id="booking-error" class="error" role="alert" hidden></div>
                        <button id="pay-button" type="submit" class="primary" disabled>Pay 15% deposit</button>
                        <p class="muted small">Your appointment is confirmed after successful payment. The remaining 85% is payable at the salon.</p>
                    </form>
                </section>
            </div>
            <aside class="summary" aria-label="Appointment summary">
                <h2>Appointment summary</h2>
                <div id="summary-services"><p class="muted">Select a service to begin.</p></div>
                <div class="summary-duration"><span>Total appointment time</span><strong id="total-duration">0 min</strong></div>
                <dl class="totals"><div class="total-row"><dt>Total</dt><dd id="total-price">AED 0.00</dd></div><div><dt>Deposit today (15%)</dt><dd id="deposit-price">AED 0.00</dd></div><div><dt>At the salon</dt><dd id="balance-price">AED 0.00</dd></div></dl>
                <button type="button" id="continue-button" class="primary" disabled>Choose date & time</button>
            </aside>
        </div>
    </main>
    <footer><span>{{ config('public_booking.brand') }}</span><a href="{{ route('public.privacy-policy') }}" target="_blank" rel="noopener">Privacy</a><a href="{{ route('public.terms-of-service') }}" target="_blank" rel="noopener">Terms</a><a href="{{ route('public.booking') }}" target="_blank" rel="noopener">Open booking in a new tab</a></footer>
</div>
<script id="booking-data" type="application/json">{!! json_encode(['services' => $services, 'availabilityUrl' => route('public.reservation.availability'), 'checkoutUrl' => route('public.booking.store')], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
<script src="{{ asset('js/public-booking.js') }}" defer></script>
</body>
</html>
