import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;

export default function SchedulesIndex({ staffProfiles, schedules, defaultShiftStart = '09:00', defaultShiftEnd = '22:00', salonHoursLabel }) {
    const { flash } = usePage().props;
    const [editingId, setEditingId] = useState(null);
    const today = new Date().toISOString().slice(0, 10);

    const createForm = useForm({ staff_profile_id: '', schedule_date: today, start_time: defaultShiftStart, end_time: defaultShiftEnd, break_start: '', break_end: '', is_day_off: false, notes: '' });
    const editForm = useForm({ start_time: '', end_time: '', break_start: '', break_end: '', is_day_off: false, notes: '' });

    const startEdit = (schedule) => {
        setEditingId(schedule.id);
        editForm.setData({
            start_time: schedule.start_time || '',
            end_time: schedule.end_time || '',
            break_start: schedule.break_start || '',
            break_end: schedule.break_end || '',
            is_day_off: Boolean(schedule.is_day_off),
            notes: schedule.notes || '',
        });
        editForm.clearErrors();
    };

    return (
        <AuthenticatedLayout header="Schedules">
            <Head title="Schedules" />
            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Create or Assign Shift</h3>
                    <p className="mb-3 text-xs text-slate-500">Default shift matches salon operating hours ({salonHoursLabel || `${defaultShiftStart}–${defaultShiftEnd}`}). Shifts must stay within these hours unless you mark a day off.</p>
                    <form onSubmit={(e) => { e.preventDefault(); createForm.post(route('schedules.store'), { onSuccess: () => createForm.reset() }); }} className="grid gap-3 md:grid-cols-7">
                        <div><label className="ta-field-label">Staff Profile</label><select className="ta-input" value={createForm.data.staff_profile_id} onChange={(e) => createForm.setData('staff_profile_id', e.target.value)} required><option value="">Staff</option>{staffProfiles.map((s) => <option key={s.id} value={s.id}>{s.employee_code} {s.name}</option>)}</select>{fieldError(createForm, 'staff_profile_id')}</div>
                        <div><label className="ta-field-label">Schedule Date</label><input className="ta-input" type="date" value={createForm.data.schedule_date} onChange={(e) => createForm.setData('schedule_date', e.target.value)} required />{fieldError(createForm, 'schedule_date')}</div>
                        <div><label className="ta-field-label">Start Time</label><input className="ta-input" type="time" value={createForm.data.start_time} onChange={(e) => createForm.setData('start_time', e.target.value)} />{fieldError(createForm, 'start_time')}</div>
                        <div><label className="ta-field-label">End Time</label><input className="ta-input" type="time" value={createForm.data.end_time} onChange={(e) => createForm.setData('end_time', e.target.value)} />{fieldError(createForm, 'end_time')}</div>
                        <div><label className="ta-field-label">Break Start</label><input className="ta-input" type="time" value={createForm.data.break_start} onChange={(e) => createForm.setData('break_start', e.target.value)} />{fieldError(createForm, 'break_start')}</div>
                        <div><label className="ta-field-label">Break End</label><input className="ta-input" type="time" value={createForm.data.break_end} onChange={(e) => createForm.setData('break_end', e.target.value)} />{fieldError(createForm, 'break_end')}</div>
                        <button className="ta-btn-primary" disabled={createForm.processing}>Save</button>
                        <div className="md:col-span-7 flex items-center gap-3"><label className="text-sm text-slate-600"><input type="checkbox" checked={createForm.data.is_day_off} onChange={(e) => createForm.setData('is_day_off', e.target.checked)} className="mr-2" />Day off</label><input className="ta-input flex-1" placeholder="Notes" value={createForm.data.notes} onChange={(e) => createForm.setData('notes', e.target.value)} /></div>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Schedule Calendar Rows</h3></div>
                    <div className="overflow-x-auto"><table className="min-w-full text-sm"><thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Date</th><th className="px-5 py-3">Staff</th><th className="px-5 py-3">Shift</th><th className="px-5 py-3">Break</th><th className="px-5 py-3">Day Off</th><th className="px-5 py-3">Actions</th></tr></thead><tbody>{schedules.map((s) => <tr key={s.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-600">{s.schedule_date?.slice(0, 10)}</td><td className="px-5 py-3 font-medium text-slate-700">{s.staff_name}</td><td className="px-5 py-3 text-slate-600">{s.start_time || '-'} - {s.end_time || '-'}</td><td className="px-5 py-3 text-slate-600">{s.break_start || '-'} - {s.break_end || '-'}</td><td className="px-5 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${s.is_day_off ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'}`}>{s.is_day_off ? 'Yes' : 'No'}</span></td><td className="px-5 py-3"><div className="flex gap-2"><button className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700" onClick={() => startEdit(s)}>Edit</button><button className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700" onClick={() => router.delete(route('schedules.destroy', s.id))}>Delete</button></div></td></tr>)}</tbody></table></div>
                </section>

                {editingId && (
                    <section className="ta-card p-5">
                        <h3 className="mb-4 text-sm font-semibold text-slate-700">Edit Schedule #{editingId}</h3>
                        <form onSubmit={(e) => { e.preventDefault(); editForm.put(route('schedules.update', editingId), { onSuccess: () => setEditingId(null) }); }} className="grid gap-3 md:grid-cols-6">
                            <div><label className="ta-field-label">Start Time</label><input className="ta-input" type="time" value={editForm.data.start_time} onChange={(e) => editForm.setData('start_time', e.target.value)} />{fieldError(editForm, 'start_time')}</div>
                            <div><label className="ta-field-label">End Time</label><input className="ta-input" type="time" value={editForm.data.end_time} onChange={(e) => editForm.setData('end_time', e.target.value)} />{fieldError(editForm, 'end_time')}</div>
                            <div><label className="ta-field-label">Break Start</label><input className="ta-input" type="time" value={editForm.data.break_start} onChange={(e) => editForm.setData('break_start', e.target.value)} />{fieldError(editForm, 'break_start')}</div>
                            <div><label className="ta-field-label">Break End</label><input className="ta-input" type="time" value={editForm.data.break_end} onChange={(e) => editForm.setData('break_end', e.target.value)} />{fieldError(editForm, 'break_end')}</div>
                            <div className="md:col-span-2 flex items-center"><label className="text-sm text-slate-600"><input type="checkbox" checked={editForm.data.is_day_off} onChange={(e) => editForm.setData('is_day_off', e.target.checked)} className="mr-2" />Day off</label></div>
                            <div className="md:col-span-6"><input className="ta-input" placeholder="Notes" value={editForm.data.notes} onChange={(e) => editForm.setData('notes', e.target.value)} />{fieldError(editForm, 'notes')}</div>
                            <div className="md:col-span-6 flex gap-2"><button className="ta-btn-primary" disabled={editForm.processing}>Save</button><button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setEditingId(null)}>Cancel</button></div>
                        </form>
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}









