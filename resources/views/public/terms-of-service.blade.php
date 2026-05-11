<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Terms of Service | {{ $terms['business_name'] }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Georgia, 'Times New Roman', serif;
            background: #f7f4f2;
            color: #3d2b28;
        }
        .shell {
            max-width: 56rem;
            margin: 0 auto;
            padding: 2rem clamp(1.25rem, 4vw, 2.5rem) 3rem;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            padding: clamp(1.5rem, 3vw, 2.5rem);
            box-shadow: 0 1px 3px rgba(61, 43, 40, 0.08);
        }
        .brand img {
            display: block;
            max-width: min(18rem, 100%);
            height: auto;
            margin-bottom: 1.5rem;
        }
        h1 {
            font-size: 2rem;
            margin: 0 0 0.5rem;
        }
        h2 {
            font-size: 1.15rem;
            margin: 2rem 0 0.75rem;
        }
        p, li, a {
            font-family: system-ui, sans-serif;
            font-size: 0.95rem;
            line-height: 1.7;
            color: #6b534d;
        }
        ul {
            margin: 0.5rem 0 0;
            padding-left: 1.25rem;
        }
        .meta {
            margin: 0 0 1.75rem;
            font-family: system-ui, sans-serif;
            font-size: 0.85rem;
            color: #8a726c;
        }
        .footer-links {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 2rem;
        }
        .footer-links a {
            color: #6b534d;
            text-decoration: none;
            font-weight: 600;
        }
        .footer-links a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="shell">
    <div class="card">
        <div class="brand">
            <img src="{{ asset('images/vina-logo.png') }}" alt="{{ $terms['business_name'] }}">
        </div>

        <h1>Terms of Service</h1>
        <p class="meta">Effective date: {{ $terms['effective_date'] }}</p>

        <p>
            These Terms of Service govern the use of the booking, communication, and customer service features offered by {{ $terms['business_name'] }} through its website, embedded booking forms, and connected messaging channels including WhatsApp Business.
        </p>

        <h2>Bookings and Customer Information</h2>
        <p>
            By submitting a booking request, you confirm that the information you provide is accurate and that you are authorized to use the provided contact details. We use this information to manage appointments, confirmations, reminders, and related service communication.
        </p>

        <h2>Appointments and Availability</h2>
        <ul>
            <li>Appointments are subject to service and staff availability.</li>
            <li>Submitting a booking request does not always guarantee final confirmation.</li>
            <li>We may contact you to adjust timing, staff assignment, or service details where necessary.</li>
        </ul>

        <h2>WhatsApp and Messaging</h2>
        <p>
            If you contact us through WhatsApp or provide a mobile number for service communication, you agree that we may send operational messages such as confirmations, reminders, support replies, and approved template messages where applicable.
        </p>
        <p>
            Use of WhatsApp communications is also subject to Meta and WhatsApp platform terms, policies, and messaging rules.
        </p>

        <h2>Acceptable Use</h2>
        <ul>
            <li>You must not misuse the booking form, spam our communication channels, or submit false or misleading information.</li>
            <li>You must not attempt to interfere with the website, booking workflow, or service availability.</li>
        </ul>

        <h2>Cancellation and Changes</h2>
        <p>
            Appointment cancellation, approval, and rescheduling rules may depend on salon policy, booking timing, and operational constraints. Where enabled, customer cancellation is subject to configured cutoff windows.
        </p>

        <h2>Limitation of Service</h2>
        <p>
            We aim to provide continuous availability of booking and messaging services, but we do not guarantee uninterrupted access. Temporary downtime, maintenance, third-party delivery issues, or provider restrictions may affect message delivery or booking access.
        </p>

        <h2>Privacy</h2>
        <p>
            Your use of our website and messaging channels is also governed by our Privacy Policy, which explains how customer data is collected and used.
        </p>

        <h2>Contact</h2>
        <p>
            If you have questions about these Terms of Service, contact us at {{ $terms['contact_email'] ?: 'hello@example.com' }}.
        </p>
        @if (!empty($terms['app_url']))
            <p>Website: {{ $terms['app_url'] }}</p>
        @endif

        <div class="footer-links">
            <a href="{{ route('public.booking') }}">Back to Booking</a>
            <a href="{{ route('public.privacy-policy') }}">Privacy Policy</a>
        </div>
    </div>
</div>
</body>
</html>
