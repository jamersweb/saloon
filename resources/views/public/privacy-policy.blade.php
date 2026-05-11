<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy Policy | {{ $policy['business_name'] }}</title>
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
        .back-link {
            display: inline-block;
            margin-top: 2rem;
            color: #6b534d;
            text-decoration: none;
            font-weight: 600;
        }
        .back-link:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="shell">
    <div class="card">
        <div class="brand">
            <img src="{{ asset('images/vina-logo.png') }}" alt="{{ $policy['business_name'] }}">
        </div>

        <h1>Privacy Policy</h1>
        <p class="meta">Effective date: {{ $policy['effective_date'] }}</p>

        <p>
            This Privacy Policy explains how {{ $policy['business_name'] }} collects, uses, stores, and shares personal information when customers contact us, book appointments, or receive service updates through our website, Meta platforms, and the WhatsApp Business API.
        </p>
        <p>
            By using our booking forms or communicating with us, you consent to the collection and use of information as described here.
        </p>

        <h2>Information We Collect</h2>
        <p>We may collect the following information:</p>
        <ul>
            <li>Name</li>
            <li>Mobile phone number</li>
            <li>Email address</li>
            <li>Appointment details and service history</li>
            <li>Message delivery records and communication preferences</li>
            <li>Optional notes you provide during booking or support interactions</li>
        </ul>

        <h2>How We Use Information</h2>
        <p>We use customer information to:</p>
        <ul>
            <li>Schedule, confirm, and manage appointments</li>
            <li>Send reminders, rescheduling notices, and service follow-ups</li>
            <li>Respond to customer inquiries and support requests</li>
            <li>Send approved WhatsApp template messages through Meta when relevant to bookings or customer communication preferences</li>
            <li>Maintain audit logs, delivery logs, and operational records for service quality and security</li>
        </ul>

        <h2>WhatsApp and Meta Platforms</h2>
        <p>
            We use the WhatsApp Business API and Meta services to send customer communications. When you choose to communicate with us on WhatsApp, message metadata and delivery information may be processed by Meta in accordance with Meta&apos;s own platform policies and terms.
        </p>
        <p>
            We do not sell WhatsApp contact information. We only use customer data for legitimate business communication such as appointment reminders, booking updates, support responses, and customer-requested follow-ups.
        </p>
        <p>
            If marketing communication is enabled, it is sent only through approved Meta templates and must comply with applicable consent and opt-in requirements.
        </p>

        <h2>Data Sharing</h2>
        <p>We may share limited data with service providers that help us operate the business, including:</p>
        <ul>
            <li>Meta / WhatsApp Business Platform for message delivery</li>
            <li>Hosting, infrastructure, and email providers</li>
            <li>Internal staff who need access to manage bookings and customer service</li>
        </ul>
        <p>We do not sell or rent customer personal information to third parties.</p>

        <h2>Retention and Security</h2>
        <p>
            We retain customer and communication records only as long as necessary for operational, legal, accounting, support, and security purposes. We use reasonable technical and organizational measures to protect stored data from unauthorized access, disclosure, or misuse.
        </p>

        <h2>Your Choices</h2>
        <p>You may request to:</p>
        <ul>
            <li>Access or correct your stored information</li>
            <li>Stop receiving WhatsApp or other direct communications</li>
            <li>Request deletion of your information, subject to legal or operational retention requirements</li>
        </ul>
        <p>To opt out of WhatsApp messages, reply with a request to stop or contact us directly.</p>

        <h2>Contact</h2>
        <p>If you have questions about this Privacy Policy or your personal data, contact us at:</p>
        <p>Email: {{ $policy['contact_email'] ?: 'hello@example.com' }}</p>
        @if (!empty($policy['contact_phone']))
            <p>Phone / WhatsApp: {{ $policy['contact_phone'] }}</p>
        @endif
        @if (!empty($policy['app_url']))
            <p>Website: {{ $policy['app_url'] }}</p>
        @endif

        <div style="display:flex;flex-wrap:wrap;gap:1rem;margin-top:2rem;">
            <a href="{{ route('public.booking') }}" class="back-link" style="margin-top:0;">Back to Booking</a>
            <a href="{{ route('public.terms-of-service') }}" class="back-link" style="margin-top:0;">Terms of Service</a>
        </div>
    </div>
</div>
</body>
</html>
