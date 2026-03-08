import ApplicationLogo from '@/Components/ApplicationLogo';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

export default function Booking({ services, staffProfiles, bookingRules }) {
    const { flash, errors } = usePage().props;
    const { data, setData, post, processing } = useForm({
        customer_name: '', customer_phone: '', customer_email: '', service_id: '', staff_profile_id: '', scheduled_start: '', notes: '',
    });

    const submit = (e) => {
        e.preventDefault();
        post(route('public.booking.store'));
    };

    return (
        <>
            <Head title="Book Appointment | Vina Management System" />
            <div className="min-h-screen bg-slate-100 p-6">
                <div className="ta-card mx-auto max-w-3xl space-y-4 p-6">
                    <div>
                        <ApplicationLogo className="h-auto w-72" />
                    </div>
                    <h1 className="text-2xl font-semibold text-slate-800">Book Your Appointment</h1>
                    <p className="text-sm text-slate-500">
                        Slot interval: every {bookingRules?.slot_interval_minutes ?? 15} minutes.
                        Minimum advance: {bookingRules?.min_advance_minutes ?? 30} minutes.
                        Maximum advance: {bookingRules?.max_advance_days ?? 60} days.
                    </p>
                    {flash?.status && <div className="rounded bg-green-100 p-3 text-green-700">{flash.status}</div>}
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
                            <input className="ta-input md:col-span-2" type="datetime-local" value={data.scheduled_start} onChange={(e) => setData('scheduled_start', e.target.value)} required />
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





