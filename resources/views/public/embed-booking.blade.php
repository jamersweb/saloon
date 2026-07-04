<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Book Your Appointment | Vina</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Georgia, 'Times New Roman', serif;
            background: #f7f4f2;
            color: #3d2b28;
        }
        .embed-shell {
            max-width: 36rem;
            margin: 0 auto;
            padding: 2.5rem 1rem;
        }
        .card {
            background: #fff;
            border-radius: 18px;
            padding: 1.65rem;
            box-shadow: 0 8px 28px rgba(61, 43, 40, 0.08);
        }
        h1 {
            font-size: 1.1rem;
            font-weight: 700;
            margin: 0 0 0.75rem;
            text-align: center;
        }
        .intro {
            font-family: system-ui, sans-serif;
            font-size: 0.7rem;
            line-height: 1.55;
            color: #6b534d;
            margin: 0 0 1.25rem;
            text-align: center;
        }
        .errors {
            font-family: system-ui, sans-serif;
            font-size: 0.85rem;
            background: #fde8e8;
            color: #9b1c1c;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        form {
            display: grid;
            gap: 0.9rem;
        }
        @media (min-width: 36rem) {
            form.cols-2 {
                grid-template-columns: 1fr 1fr;
            }
            .span-2 { grid-column: span 2; }
        }
        label {
            display: block;
            font-family: system-ui, sans-serif;
            font-size: 0.62rem;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #3d2b28;
            margin-bottom: 0.3rem;
        }
        .field { min-width: 0; }
        input, select, textarea {
            width: 100%;
            font-family: system-ui, sans-serif;
            font-size: 0.82rem;
            padding: 0.6rem 0.7rem;
            border: 1px solid #d4cfcb;
            border-radius: 8px;
            background: #fff;
            color: #3d2b28;
        }
        #service_ids { min-height: 7rem; }
        textarea { min-height: 3.9rem; resize: vertical; }
        input:focus, select:focus, textarea:focus {
            outline: 2px solid rgba(163, 104, 104, 0.35);
            outline-offset: 1px;
        }
        button[type="submit"] {
            font-family: system-ui, sans-serif;
            font-size: 0.95rem;
            font-weight: 600;
            width: 100%;
            border: none;
            border-radius: 999px;
            padding: 0.85rem 1.25rem;
            background: #a36868;
            color: #fff;
            cursor: pointer;
            margin-top: 0.2rem;
        }
        button[type="submit"]:hover { background: #8f5a5a; }
        button[type="submit"]:disabled { opacity: 0.65; cursor: not-allowed; }
        .footer-links {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 1.2rem;
            margin-top: 0.9rem;
            font-family: system-ui, sans-serif;
            font-size: 0.78rem;
        }
        .footer-links a {
            color: #6b534d;
            text-decoration: none;
        }
        .footer-links a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="embed-shell">
    <div class="card">
        <h1>Book Your Appointment</h1>
        <p class="intro">
            Salon hours: {{ $bookingRules->opening_time ?? '09:00' }} to {{ $bookingRules->closing_time ?? '22:00' }} (same day).
            Start times are booked in the next available salon slot.
            Minimum advance: {{ $bookingRules->min_advance_minutes ?? 30 }} minutes.
            Maximum advance: {{ $bookingRules->max_advance_days ?? 60 }} days.
        </p>

        @if ($errors->any())
            <div class="errors">{{ $errors->first() }}</div>
        @endif

        <form method="post" action="{{ route('embed.booking.store') }}" class="cols-2" id="embed-booking-form">
            @csrf
            <div class="field">
                <label for="customer_name">Customer Name</label>
                <input id="customer_name" name="customer_name" type="text" placeholder="Your name" value="{{ old('customer_name') }}" required>
            </div>
            <div class="field">
                <label for="customer_phone">Customer Phone</label>
                <input id="customer_phone" name="customer_phone" type="tel" placeholder="+971111111111" value="{{ old('customer_phone') }}" required>
            </div>
            <div class="field">
                <label for="service_ids">Services</label>
                <select id="service_ids" name="service_ids[]" multiple required size="6">
                    @foreach ($services as $service)
                        <option value="{{ $service->id }}" @selected(in_array((string) $service->id, array_map('strval', old('service_ids', [])), true))>
                            {{ $service->name }} ({{ $service->duration_minutes }} min) - {{ number_format((float) $service->price, 2) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="scheduled_start">Scheduled Start</label>
                <input
                    id="scheduled_start"
                    name="scheduled_start"
                    type="datetime-local"
                    value="{{ old('scheduled_start', $defaultStart) }}"
                    required
                >
            </div>
            <div class="field span-2">
                <label for="notes">Notes</label>
                <textarea id="notes" name="notes" placeholder="Notes">{{ old('notes') }}</textarea>
            </div>
            <div class="span-2">
                <button type="submit" id="embed-submit">Submit Booking</button>
            </div>
        </form>
        <div class="footer-links">
            <a href="{{ route('public.privacy-policy') }}" target="_blank" rel="noreferrer">Privacy Policy</a>
            <a href="{{ route('public.terms-of-service') }}" target="_blank" rel="noreferrer">Terms of Service</a>
        </div>
    </div>
</div>
@php
    $bookingRulesForJs = $bookingRules->only([
        'slot_interval_minutes',
        'opening_time',
        'closing_time',
        'min_advance_minutes',
        'max_advance_days',
    ]);
@endphp
<script>
(function () {
    const postHeightToParent = function () {
        if (window.parent === window) return;
        const body = document.body;
        const doc = document.documentElement;
        const height = Math.max(
            body ? body.scrollHeight : 0,
            body ? body.offsetHeight : 0,
            doc ? doc.clientHeight : 0,
            doc ? doc.scrollHeight : 0,
            doc ? doc.offsetHeight : 0
        );

        window.parent.postMessage({
            type: 'vina-booking-resize',
            height: height,
        }, '*');
    };

    const pad2 = function (value) { return String(value).padStart(2, '0'); };
    const localYmd = function (d) { return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); };
    const addDaysLocalYmd = function (dateYmd, days) {
        const parts = String(dateYmd).split('-').map(function (n) { return parseInt(n, 10); });
        const date = new Date(parts[0], (parts[1] || 1) - 1, parts[2] || 1);
        date.setDate(date.getDate() + Number(days || 0));
        return localYmd(date);
    };

    const dateTimeLocalMs = function (value) {
        if (!value) return NaN;
        const ms = new Date(value).getTime();
        return Number.isNaN(ms) ? NaN : ms;
    };
    const dateTimeLocalCompare = function (a, b) {
        const ta = dateTimeLocalMs(a);
        const tb = dateTimeLocalMs(b);
        if (Number.isNaN(ta) || Number.isNaN(tb)) return String(a).localeCompare(String(b));
        if (ta < tb) return -1;
        if (ta > tb) return 1;
        return 0;
    };

    const salonClockBoundary = function (bookingRules, key, fallback) {
        const raw = String((bookingRules && bookingRules[key]) || fallback);
        const m = raw.match(/^(\d{1,2}):(\d{2})/);
        if (!m) return { h: 9, m: 0 };
        return { h: Math.min(23, Math.max(0, parseInt(m[1], 10))), m: Math.min(59, Math.max(0, parseInt(m[2], 10))) };
    };

    const salonSelectableBoundsForYmd = function (dateYmd, bookingRules, slotIntervalMinutes) {
        const open = salonClockBoundary(bookingRules, 'opening_time', '09:00');
        const close = salonClockBoundary(bookingRules, 'closing_time', '22:00');
        let minM = open.h * 60 + open.m;
        const max = dateYmd + 'T' + pad2(close.h) + ':' + pad2(close.m);

        const todayYmd = localYmd(new Date());
        const step = Math.max(1, Number(slotIntervalMinutes || 30));
        const minAdv = Math.max(0, Number((bookingRules && bookingRules.min_advance_minutes) || 0));

        if (dateYmd === todayYmd) {
            const threshold = new Date(Date.now() + minAdv * 60000);
            threshold.setSeconds(0, 0);
            const thYmd = localYmd(threshold);
            if (thYmd > dateYmd) {
                return { min: max, max: max };
            }
            const parts = dateYmd.split('-').map(function (n) { return parseInt(n, 10); });
            const dayStart = new Date(parts[0], parts[1] - 1, parts[2]);
            const minsFloat = (threshold.getTime() - dayStart.getTime()) / 60000;
            const policyFloor = Math.ceil(minsFloat / step) * step;
            minM = Math.max(minM, policyFloor);
        }

        const minH = Math.floor(minM / 60);
        const minMin = minM % 60;
        let min = dateYmd + 'T' + pad2(minH) + ':' + pad2(minMin);
        if (dateTimeLocalCompare(min, max) > 0) min = max;

        return { min: min, max: max };
    };

    const salonAbsoluteMax = function (baseYmd, bookingRules) {
        const close = salonClockBoundary(bookingRules, 'closing_time', '22:00');
        const maxAdvanceDays = Math.max(1, Number((bookingRules && bookingRules.max_advance_days) || 60));
        const maxYmd = addDaysLocalYmd(baseYmd, maxAdvanceDays);
        return maxYmd + 'T' + pad2(close.h) + ':' + pad2(close.m);
    };

    const clampDateTimeLocalToSalon = function (value, bookingRules, slotIntervalMinutes) {
        if (!value || !bookingRules) return value;
        const d = value.split('T')[0];
        if (!d) return value;
        const bounds = salonSelectableBoundsForYmd(d, bookingRules, slotIntervalMinutes);
        if (dateTimeLocalCompare(value, bounds.min) < 0) return bounds.min;
        if (dateTimeLocalCompare(value, bounds.max) > 0) return bounds.max;
        return value;
    };

    const toDateTimeLocal = function (date) {
        return date.getFullYear() + '-' + pad2(date.getMonth() + 1) + '-' + pad2(date.getDate()) + 'T' + pad2(date.getHours()) + ':' + pad2(date.getMinutes());
    };

    const normalizeToInterval = function (value, intervalMinutes) {
        if (!value) return '';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return value;
        const safeInterval = Math.max(1, Number(intervalMinutes || 1));
        const snapped = Math.ceil(date.getMinutes() / safeInterval) * safeInterval;
        date.setMinutes(snapped, 0, 0);
        return toDateTimeLocal(date);
    };

    const bookingRules = @json($bookingRulesForJs);
    const defaultStart = @json($defaultStart);
    const input = document.getElementById('scheduled_start');
    if (!input) return;

    const slotIntervalMinutes = Math.max(1, Number(bookingRules.slot_interval_minutes || 30));

    function refreshBounds() {
        const value = input.value || defaultStart || '';
        const bookingStartYmd = (value.split('T')[0] || localYmd(new Date()));
        const bookingStartBounds = salonSelectableBoundsForYmd(bookingStartYmd, bookingRules, slotIntervalMinutes);
        input.min = bookingStartBounds.min;
        input.max = salonAbsoluteMax(localYmd(new Date()), bookingRules);
        input.removeAttribute('step');
        var snapped = normalizeToInterval(value, slotIntervalMinutes);
        input.value = clampDateTimeLocalToSalon(snapped, bookingRules, slotIntervalMinutes);
    }

    input.addEventListener('input', function () {
        var v = normalizeToInterval(input.value, slotIntervalMinutes);
        input.value = clampDateTimeLocalToSalon(v, bookingRules, slotIntervalMinutes);
        refreshBounds();
    });

    refreshBounds();

    document.getElementById('embed-booking-form').addEventListener('submit', function () {
        document.getElementById('embed-submit').disabled = true;
    });

    if ('ResizeObserver' in window) {
        const resizeObserver = new ResizeObserver(function () {
            postHeightToParent();
        });

        resizeObserver.observe(document.body);
    } else {
        window.addEventListener('resize', postHeightToParent);
        setInterval(postHeightToParent, 500);
    }

    window.addEventListener('load', postHeightToParent);
    window.addEventListener('pageshow', postHeightToParent);
    requestAnimationFrame(postHeightToParent);
    setTimeout(postHeightToParent, 150);
})();
</script>
</body>
</html>
