import ApplicationLogo from '@/Components/ApplicationLogo';
import AppFlashPopup from '@/Components/AppFlashPopup';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

const pad2 = (value) => String(value).padStart(2, '0');

const dateTimeLocalMs = (value) => {
    if (!value) return Number.NaN;
    const ms = new Date(value).getTime();

    return Number.isNaN(ms) ? Number.NaN : ms;
};

const dateTimeLocalCompare = (a, b) => {
    const ta = dateTimeLocalMs(a);
    const tb = dateTimeLocalMs(b);
    if (Number.isNaN(ta) || Number.isNaN(tb)) return String(a).localeCompare(String(b));
    if (ta < tb) return -1;
    if (ta > tb) return 1;

    return 0;
};

const localYmd = (d) => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;

const salonClockBoundary = (bookingRules, key, fallback) => {
    const raw = String(bookingRules?.[key] || fallback);
    const m = raw.match(/^(\d{1,2}):(\d{2})/);
    if (!m) return { h: 9, m: 0 };

    return { h: Math.min(23, Math.max(0, parseInt(m[1], 10))), m: Math.min(59, Math.max(0, parseInt(m[2], 10))) };
};

const salonSelectableBoundsForYmd = (dateYmd, bookingRules, slotIntervalMinutes) => {
    const open = salonClockBoundary(bookingRules, 'opening_time', '09:00');
    const close = salonClockBoundary(bookingRules, 'closing_time', '22:00');
    let minM = open.h * 60 + open.m;
    const max = `${dateYmd}T${pad2(close.h)}:${pad2(close.m)}`;

    const todayYmd = localYmd(new Date());
    const step = Math.max(1, Number(slotIntervalMinutes || 30));
    const minAdv = Math.max(0, Number(bookingRules?.min_advance_minutes || 0));

    if (dateYmd === todayYmd) {
        const threshold = new Date(Date.now() + minAdv * 60000);
        threshold.setSeconds(0, 0);
        const thYmd = localYmd(threshold);
        if (thYmd > dateYmd) {
            return { min: max, max };
        }
        const [Y, M, D] = dateYmd.split('-').map((n) => parseInt(n, 10));
        const dayStart = new Date(Y, M - 1, D);
        const minsFloat = (threshold.getTime() - dayStart.getTime()) / 60000;
        const policyFloor = Math.ceil(minsFloat / step) * step;
        minM = Math.max(minM, policyFloor);
    }

    const minH = Math.floor(minM / 60);
    const minMin = minM % 60;
    let min = `${dateYmd}T${pad2(minH)}:${pad2(minMin)}`;
    if (dateTimeLocalCompare(min, max) > 0) min = max;

    return { min, max };
};

const clampDateTimeLocalToSalon = (value, bookingRules, slotIntervalMinutes = 30) => {
    if (!value || !bookingRules) return value;
    const [d] = value.split('T');
    if (!d) return value;
    const { min, max } = salonSelectableBoundsForYmd(d, bookingRules, slotIntervalMinutes);
    if (dateTimeLocalCompare(value, min) < 0) return min;
    if (dateTimeLocalCompare(value, max) > 0) return max;

    return value;
};

const toDateTimeLocal = (value) => {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';

    return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}T${pad2(date.getHours())}:${pad2(date.getMinutes())}`;
};

/** Snap minutes to booking slot interval (matches server BookingAvailabilityService). */
const normalizeToInterval = (value, intervalMinutes) => {
    if (!value) return '';

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;

    const safeInterval = Math.max(1, Number(intervalMinutes || 1));
    const snappedMinutes = Math.round(date.getMinutes() / safeInterval) * safeInterval;
    date.setMinutes(snappedMinutes, 0, 0);

    return toDateTimeLocal(date);
};

export default function Booking({ services, staffProfiles, bookingRules, defaultStart }) {
    const { errors } = usePage().props;
    const { data, setData, post, processing } = useForm({
        customer_name: '', customer_phone: '', customer_email: '', service_id: '', staff_profile_id: '', scheduled_start: defaultStart || '', notes: '',
    });

    const slotIntervalMinutes = Math.max(1, Number(bookingRules?.slot_interval_minutes || 30));
    const bookingStartYmd = (data.scheduled_start || defaultStart || '').split('T')[0] || localYmd(new Date());
    const bookingStartBounds = salonSelectableBoundsForYmd(bookingStartYmd, bookingRules, slotIntervalMinutes);

    const submit = (e) => {
        e.preventDefault();
        post(route('public.booking.store'));
    };

    return (
        <>
            <Head title="Book Appointment | Vina Management System" />
            <div className="min-h-screen bg-slate-100 p-6">
                <AppFlashPopup />
                <div className="ta-card mx-auto max-w-3xl space-y-4 p-6">
                    <div>
                        <ApplicationLogo className="h-auto w-72" />
                    </div>
                    <h1 className="text-2xl font-semibold text-slate-800">Book Your Appointment</h1>
                    <p className="text-sm text-slate-500">
                        Salon hours: {bookingRules?.opening_time || '09:00'} to {bookingRules?.closing_time || '22:00'} (same day). For today, the earliest slot is the next available time from now (including minimum advance).
                        Slot interval: every {bookingRules?.slot_interval_minutes ?? 30} minutes.
                        Minimum advance: {bookingRules?.min_advance_minutes ?? 30} minutes.
                        Maximum advance: {bookingRules?.max_advance_days ?? 60} days.
                    </p>
                    {Object.keys(errors).length > 0 && <div className="rounded bg-red-100 p-3 text-red-700">{Object.values(errors)[0]}</div>}
                    <form onSubmit={submit} className="grid gap-3 md:grid-cols-2">
                        <div>
                            <label className="ta-field-label">Customer Name</label>
                            <input className="ta-input" placeholder="Your name" value={data.customer_name} onChange={(e) => setData('customer_name', e.target.value)} required />
                        </div>
                        <div>
                            <label className="ta-field-label">Customer Phone</label>
                            <input className="ta-input" placeholder="Phone" value={data.customer_phone} onChange={(e) => setData('customer_phone', e.target.value)} required />
                        </div>
                        <div className="md:col-span-2">
                            <label className="ta-field-label">Customer Email</label>
                            <input className="ta-input md:col-span-2" placeholder="Email" value={data.customer_email} onChange={(e) => setData('customer_email', e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Service</label>
                            <select className="ta-input" value={data.service_id} onChange={(e) => setData('service_id', e.target.value)} required>
                                <option value="">Select service</option>
                                {services.map((s) => <option key={s.id} value={s.id}>{s.name} ({s.duration_minutes} min)</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="ta-field-label">Staff Profile</label>
                            <select className="ta-input" value={data.staff_profile_id} onChange={(e) => setData('staff_profile_id', e.target.value)}>
                                <option value="">Any available staff</option>
                                {staffProfiles.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </select>
                        </div>
                        <div className="md:col-span-2">
                            <label className="ta-field-label">Scheduled Start</label>
                            <input
                                className="ta-input md:col-span-2"
                                type="datetime-local"
                                value={data.scheduled_start}
                                onInput={(e) => {
                                    let v = normalizeToInterval(e.currentTarget.value, slotIntervalMinutes);
                                    v = clampDateTimeLocalToSalon(v, bookingRules, slotIntervalMinutes);
                                    setData('scheduled_start', v);
                                }}
                                min={bookingStartBounds.min}
                                max={bookingStartBounds.max}
                                required
                            />
                        </div>
                        <div className="md:col-span-2">
                            <label className="ta-field-label">Notes</label>
                            <textarea className="ta-input md:col-span-2" placeholder="Notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                        </div>
                        <button className="ta-btn-primary md:col-span-2" disabled={processing}>Submit Booking</button>
                    </form>
                    <Link href={route('login')} className="text-sm text-indigo-600">Staff login</Link>
                </div>
            </div>
        </>
    );
}





