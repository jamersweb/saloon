import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const statusLabels = { pending: 'Pending', confirmed: 'Confirm', in_progress: 'Start', completed: 'Complete', cancelled: 'Cancel', no_show: 'No-show' };
const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;
const toDateTimeLocal = (value) => value ? new Date(value).toISOString().slice(0, 16) : '';

export default function AppointmentsIndex({ appointments, services, staffProfiles, statusFilter, bookingRules }) {
    const { flash, auth } = usePage().props;
    const canManageRules = Boolean(auth?.permissions?.can_manage_schedules);
    const [editingId, setEditingId] = useState(null);

    const createForm = useForm({ customer_name: '', customer_phone: '', customer_email: '', service_id: '', staff_profile_id: '', scheduled_start: '', scheduled_end: '', status: 'confirmed', notes: '' });
    const editForm = useForm({ customer_name: '', customer_phone: '', customer_email: '', service_id: '', staff_profile_id: '', scheduled_start: '', scheduled_end: '', status: 'confirmed', notes: '' });
    const rulesForm = useForm({
        slot_interval_minutes: bookingRules?.slot_interval_minutes ?? 15,
        min_advance_minutes: bookingRules?.min_advance_minutes ?? 30,
        max_advance_days: bookingRules?.max_advance_days ?? 60,
        public_requires_approval: Boolean(bookingRules?.public_requires_approval ?? true),
        allow_customer_cancellation: Boolean(bookingRules?.allow_customer_cancellation ?? true),
        cancellation_cutoff_hours: bookingRules?.cancellation_cutoff_hours ?? 12,
    });

    const startEdit = (appt) => {
        setEditingId(appt.id);
        editForm.setData({
            customer_name: appt.customer_name || '',
            customer_phone: appt.customer_phone || '',
            customer_email: appt.customer_email || '',
            service_id: appt.service_id || '',
            staff_profile_id: appt.staff_profile_id || '',
            scheduled_start: toDateTimeLocal(appt.scheduled_start),
            scheduled_end: toDateTimeLocal(appt.scheduled_end),
            status: appt.status || 'confirmed',
            notes: appt.notes || '',
        });
        editForm.clearErrors();
    };

    const changeFilter = (value) => router.get(route('appointments.index'), { status: value || undefined }, { preserveState: true, replace: true });
    const transition = (id, nextStatus) => router.patch(route('appointments.transition', id), { status: nextStatus });

    return (
        <AuthenticatedLayout header="Appointments">
            <Head title="Appointments" />
            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Booking Rules</h3>
                    <form onSubmit={(e) => { e.preventDefault(); rulesForm.patch(route('booking-rules.update')); }} className="grid gap-3 md:grid-cols-3">
                        <div><input className="ta-input" type="number" min="5" max="120" value={rulesForm.data.slot_interval_minutes} onChange={(e) => rulesForm.setData('slot_interval_minutes', e.target.value)} placeholder="Slot interval (minutes)" required />{fieldError(rulesForm, 'slot_interval_minutes')}</div>
                        <div><input className="ta-input" type="number" min="0" max="10080" value={rulesForm.data.min_advance_minutes} onChange={(e) => rulesForm.setData('min_advance_minutes', e.target.value)} placeholder="Min advance (minutes)" required />{fieldError(rulesForm, 'min_advance_minutes')}</div>
                        <div><input className="ta-input" type="number" min="1" max="365" value={rulesForm.data.max_advance_days} onChange={(e) => rulesForm.setData('max_advance_days', e.target.value)} placeholder="Max advance (days)" required />{fieldError(rulesForm, 'max_advance_days')}</div>
                        <div><input className="ta-input" type="number" min="0" max="168" value={rulesForm.data.cancellation_cutoff_hours} onChange={(e) => rulesForm.setData('cancellation_cutoff_hours', e.target.value)} placeholder="Cancellation cutoff (hours)" required />{fieldError(rulesForm, 'cancellation_cutoff_hours')}</div>
                        <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={rulesForm.data.public_requires_approval} onChange={(e) => rulesForm.setData('public_requires_approval', e.target.checked)} />Public booking requires approval</label>
                        <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={rulesForm.data.allow_customer_cancellation} onChange={(e) => rulesForm.setData('allow_customer_cancellation', e.target.checked)} />Allow customer cancellation</label>
                        <button className="ta-btn-primary md:col-span-3" disabled={rulesForm.processing || !canManageRules}>Save Booking Rules</button>
                    </form>
                </section>

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Create Appointment</h3>
                    <form onSubmit={(e) => { e.preventDefault(); createForm.post(route('appointments.store'), { onSuccess: () => createForm.reset() }); }} className="grid gap-3 md:grid-cols-4">
                        <div><input className="ta-input" placeholder="Customer name" value={createForm.data.customer_name} onChange={(e) => createForm.setData('customer_name', e.target.value)} required />{fieldError(createForm, 'customer_name')}</div>
                        <div><input className="ta-input" placeholder="Phone" value={createForm.data.customer_phone} onChange={(e) => createForm.setData('customer_phone', e.target.value)} required />{fieldError(createForm, 'customer_phone')}</div>
                        <div><input className="ta-input" placeholder="Email" value={createForm.data.customer_email} onChange={(e) => createForm.setData('customer_email', e.target.value)} />{fieldError(createForm, 'customer_email')}</div>
                        <div><select className="ta-input" value={createForm.data.service_id} onChange={(e) => createForm.setData('service_id', e.target.value)} required><option value="">Service</option>{services.map((s) => <option key={s.id} value={s.id}>{s.name} ({s.duration_minutes}m)</option>)}</select>{fieldError(createForm, 'service_id')}</div>
                        <div><select className="ta-input" value={createForm.data.staff_profile_id} onChange={(e) => createForm.setData('staff_profile_id', e.target.value)}><option value="">Unassigned Staff</option>{staffProfiles.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>{fieldError(createForm, 'staff_profile_id')}</div>
                        <div><input className="ta-input" type="datetime-local" value={createForm.data.scheduled_start} onChange={(e) => createForm.setData('scheduled_start', e.target.value)} required />{fieldError(createForm, 'scheduled_start')}</div>
                        <div><input className="ta-input" type="datetime-local" value={createForm.data.scheduled_end} onChange={(e) => createForm.setData('scheduled_end', e.target.value)} />{fieldError(createForm, 'scheduled_end')}</div>
                        <div><select className="ta-input" value={createForm.data.status} onChange={(e) => createForm.setData('status', e.target.value)}><option value="confirmed">confirmed</option><option value="pending">pending</option></select>{fieldError(createForm, 'status')}</div>
                        <div className="md:col-span-4"><input className="ta-input" placeholder="Notes" value={createForm.data.notes} onChange={(e) => createForm.setData('notes', e.target.value)} />{fieldError(createForm, 'notes')}</div>
                        <button className="ta-btn-primary" disabled={createForm.processing}>Create</button>
                    </form>
                </section>

                <section className="ta-card p-4">
                    <label className="mr-2 text-sm font-medium text-slate-600">Filter status:</label>
                    <select className="ta-input inline-block w-auto min-w-[180px]" value={statusFilter || ''} onChange={(e) => changeFilter(e.target.value)}>
                        <option value="">All</option>
                        {Object.keys(statusLabels).map((status) => <option key={status} value={status}>{status}</option>)}
                    </select>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Appointment Queue</h3></div>
                    <div className="overflow-x-auto"><table className="min-w-full text-sm"><thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Time</th><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Service</th><th className="px-5 py-3">Staff</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Actions</th></tr></thead><tbody>{appointments.map((a) => <tr key={a.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-600">{new Date(a.scheduled_start).toLocaleString()}</td><td className="px-5 py-3"><div className="font-medium text-slate-700">{a.customer_name}</div><div className="text-xs text-slate-500">{a.customer_phone}</div></td><td className="px-5 py-3 text-slate-600">{a.service_name}</td><td className="px-5 py-3 text-slate-600">{a.staff_name || 'Unassigned'}</td><td className="px-5 py-3"><span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{a.status}</span></td><td className="px-5 py-3"><div className="flex flex-wrap gap-2"><button className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700" onClick={() => startEdit(a)}>Edit</button>{(a.next_statuses || []).map((next) => <button key={next} className="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50" onClick={() => transition(a.id, next)}>{statusLabels[next] || next}</button>)}{a.next_statuses?.includes('cancelled') && <button className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700" onClick={() => router.delete(route('appointments.destroy', a.id))}>Cancel</button>}</div></td></tr>)}</tbody></table></div>
                </section>

                {editingId && (
                    <section className="ta-card p-5">
                        <h3 className="mb-4 text-sm font-semibold text-slate-700">Edit Appointment #{editingId}</h3>
                        <form onSubmit={(e) => { e.preventDefault(); editForm.put(route('appointments.update', editingId), { onSuccess: () => setEditingId(null) }); }} className="grid gap-3 md:grid-cols-4">
                            <div><input className="ta-input" value={editForm.data.customer_name} onChange={(e) => editForm.setData('customer_name', e.target.value)} required />{fieldError(editForm, 'customer_name')}</div>
                            <div><input className="ta-input" value={editForm.data.customer_phone} onChange={(e) => editForm.setData('customer_phone', e.target.value)} required />{fieldError(editForm, 'customer_phone')}</div>
                            <div><input className="ta-input" value={editForm.data.customer_email} onChange={(e) => editForm.setData('customer_email', e.target.value)} />{fieldError(editForm, 'customer_email')}</div>
                            <div><select className="ta-input" value={editForm.data.service_id} onChange={(e) => editForm.setData('service_id', e.target.value)} required><option value="">Service</option>{services.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>{fieldError(editForm, 'service_id')}</div>
                            <div><select className="ta-input" value={editForm.data.staff_profile_id} onChange={(e) => editForm.setData('staff_profile_id', e.target.value)}><option value="">Unassigned Staff</option>{staffProfiles.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>{fieldError(editForm, 'staff_profile_id')}</div>
                            <div><input className="ta-input" type="datetime-local" value={editForm.data.scheduled_start} onChange={(e) => editForm.setData('scheduled_start', e.target.value)} required />{fieldError(editForm, 'scheduled_start')}</div>
                            <div><input className="ta-input" type="datetime-local" value={editForm.data.scheduled_end} onChange={(e) => editForm.setData('scheduled_end', e.target.value)} />{fieldError(editForm, 'scheduled_end')}</div>
                            <div><select className="ta-input" value={editForm.data.status} onChange={(e) => editForm.setData('status', e.target.value)}><option value="pending">pending</option><option value="confirmed">confirmed</option><option value="in_progress">in_progress</option><option value="completed">completed</option><option value="cancelled">cancelled</option><option value="no_show">no_show</option></select>{fieldError(editForm, 'status')}</div>
                            <div className="md:col-span-4"><input className="ta-input" value={editForm.data.notes} onChange={(e) => editForm.setData('notes', e.target.value)} placeholder="Notes" />{fieldError(editForm, 'notes')}</div>
                            <div className="md:col-span-4 flex gap-2"><button className="ta-btn-primary" disabled={editForm.processing}>Save</button><button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setEditingId(null)}>Cancel</button></div>
                        </form>
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
