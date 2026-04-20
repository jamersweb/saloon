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
            max-width: 40rem;
            margin: 0 auto;
            padding: 1.75rem clamp(1.25rem, 4vw, 2.5rem) 2.5rem;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            padding: clamp(1.25rem, 3vw, 2rem) clamp(1.25rem, 3vw, 2rem);
            box-shadow: 0 1px 3px rgba(61, 43, 40, 0.08);
        }
        .brand {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .brand img {
            display: block;
            margin: 0 auto;
            max-width: min(18rem, 100%);
            height: auto;
        }
        .tagline {
            font-family: system-ui, sans-serif;
            font-size: 0.65rem;
            letter-spacing: 0.08em;
            color: #6b534d;
            margin: 0.75rem 0 0;
            line-height: 1.45;
            max-width: 28rem;
            margin-left: auto;
            margin-right: auto;
        }
        h1 {
            font-size: 1.65rem;
            font-weight: 700;
            margin: 0 0 0.75rem;
            text-align: center;
        }
        .intro {
            font-family: system-ui, sans-serif;
            font-size: 0.8rem;
            line-height: 1.55;
            color: #6b534d;
            margin: 0 0 1.5rem;
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
            gap: 1rem;
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
            font-size: 0.65rem;
            font-weight: 600;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #3d2b28;
            margin-bottom: 0.35rem;
        }
        .field { min-width: 0; }
        input, select, textarea {
            width: 100%;
            font-family: system-ui, sans-serif;
            font-size: 0.95rem;
            padding: 0.65rem 0.75rem;
            border: 1px solid #d4cfcb;
            border-radius: 8px;
            background: #fff;
            color: #3d2b28;
        }
        textarea { min-height: 5rem; resize: vertical; }
        input:focus, select:focus, textarea:focus {
            outline: 2px solid rgba(163, 104, 104, 0.35);
            outline-offset: 1px;
        }
        button[type="submit"] {
            font-family: system-ui, sans-serif;
            font-size: 1rem;
            font-weight: 600;
            width: 100%;
            border: none;
            border-radius: 999px;
            padding: 0.9rem 1.25rem;
            background: #a36868;
            color: #fff;
            cursor: pointer;
            margin-top: 0.25rem;
        }
        button[type="submit"]:hover { background: #8f5a5a; }
        button[type="submit"]:disabled { opacity: 0.65; cursor: not-allowed; }
    </style>
</head>
<body>
<div class="embed-shell">
    <div class="card">
        <header class="brand">
            <img src="{{ asset('images/vina-logo.png') }}" alt="Vina">
            <p class="tagline">A world of endless possibilities in luxury care &amp; beauty services.</p>
        </header>

        <h1>Book Your Appointment</h1>
        <p class="intro">
            Salon hours: {{ $bookingRules->opening_time ?? '09:00' }} to {{ $bookingRules->closing_time ?? '22:00' }} (same day).
            Slot interval: every {{ $bookingRules->slot_interval_minutes ?? 30 }} minutes.
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
                <input id="customer_phone" name="customer_phone" type="tel" placeholder="Phone" value="{{ old('customer_phone') }}" required>
            </div>
            <div class="field span-2">
                <label for="customer_email">Customer Email</label>
                <input id="customer_email" name="customer_email" type="email" placeholder="Email" value="{{ old('customer_email') }}">
            </div>
            <div class="field">
                <label for="service_id">Service</label>
                <select id="service_id" name="service_id" required>
                    <option value="">Select service</option>
                    @foreach ($services as $service)
                        <option value="{{ $service->id }}" @selected(old('service_id') == $service->id)>
                            {{ $service->name }} ({{ $service->duration_minutes }} min)
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label for="staff_profile_id">Staff Profile</label>
                <select id="staff_profile_id" name="staff_profile_id">
                    <option value="">Any available staff</option>
                    @foreach ($staffProfiles as $staff)
                        <option value="{{ $staff->id }}" @selected(old('staff_profile_id') == $staff->id)>
                            {{ $staff->user?->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="field span-2">
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
    const pad2 = function (value) { return String(value).padStart(2, '0'); };
    const localYmd = function (d) { return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); };

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
        const snapped = Math.round(date.getMinutes() / safeInterval) * safeInterval;
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
        input.max = bookingStartBounds.max;
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
})();
</script>
</body>
</html>
