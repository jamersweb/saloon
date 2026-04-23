import ConfirmActionModal from '@/Components/ConfirmActionModal';
import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;

/** `<input type="time">` expects HH:mm in most browsers; API often returns HH:mm:ss. */
const toTimeInputValue = (value) => {
    if (!value) return '';
    const s = String(value).trim();
    if (s.length >= 5 && s[2] === ':') return s.slice(0, 5);
    return s;
};

const localYmd = (d = new Date()) => {
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

const formatYmdForDisplay = (value) => {
    if (!value) return '-';
    const ymd = String(value).slice(0, 10);
    const parts = ymd.split('-');
    if (parts.length !== 3) return ymd;
    return `${parts[2]}/${parts[1]}/${parts[0]}`;
};

export default function SchedulesIndex({ staffProfiles, schedules, defaultShiftStart = '09:00', defaultShiftEnd = '22:00', salonHoursLabel }) {
    const ROWS_PER_PAGE = 10;
    const { flash } = usePage().props;
    const [editingId, setEditingId] = useState(null);
    const [deleteScheduleId, setDeleteScheduleId] = useState(null);
    const [deleteBusy, setDeleteBusy] = useState(false);
    const [fillBusy, setFillBusy] = useState(null);
    const [searchText, setSearchText] = useState('');
    const [staffFilter, setStaffFilter] = useState('');
    const [dateFromFilter, setDateFromFilter] = useState('');
    const [dateToFilter, setDateToFilter] = useState('');
    const [dayOffFilter, setDayOffFilter] = useState('all');
    const [currentPage, setCurrentPage] = useState(1);
    const today = localYmd();

    const postFillGaps = (horizon) => {
        setFillBusy(horizon);
        router.post(
            route('schedules.fill-gaps'),
            { horizon },
            {
                preserveScroll: true,
                onFinish: () => setFillBusy(null),
            },
        );
    };

    const createForm = useForm({ staff_profile_id: '', schedule_date: today, start_time: defaultShiftStart, end_time: defaultShiftEnd, break_start: '', break_end: '', is_day_off: false, notes: '' });
    const editForm = useForm({ start_time: '', end_time: '', break_start: '', break_end: '', is_day_off: false, notes: '' });

    const startEdit = (schedule) => {
        setEditingId(schedule.id);
        editForm.setData({
            start_time: toTimeInputValue(schedule.start_time),
            end_time: toTimeInputValue(schedule.end_time),
            break_start: toTimeInputValue(schedule.break_start),
            break_end: toTimeInputValue(schedule.break_end),
            is_day_off: Boolean(schedule.is_day_off),
            notes: schedule.notes || '',
        });
        editForm.clearErrors();
    };

    const closeEditModal = () => {
        setEditingId(null);
        editForm.clearErrors();
    };

    const filteredSchedules = useMemo(() => {
        const q = searchText.trim().toLowerCase();
        return (schedules || []).filter((s) => {
            if (q) {
                const haystack = `${s.staff_name || ''} ${s.schedule_date || ''}`.toLowerCase();
                if (!haystack.includes(q)) return false;
            }

            if (staffFilter && String(s.staff_profile_id) !== String(staffFilter)) return false;

            const scheduleDate = String(s.schedule_date || '').slice(0, 10);
            if (dateFromFilter && scheduleDate < dateFromFilter) return false;
            if (dateToFilter && scheduleDate > dateToFilter) return false;

            if (dayOffFilter === 'day_off' && !s.is_day_off) return false;
            if (dayOffFilter === 'working' && s.is_day_off) return false;

            return true;
        });
    }, [schedules, searchText, staffFilter, dateFromFilter, dateToFilter, dayOffFilter]);

    const totalPages = Math.max(1, Math.ceil(filteredSchedules.length / ROWS_PER_PAGE));
    const pagedSchedules = useMemo(
        () => filteredSchedules.slice((currentPage - 1) * ROWS_PER_PAGE, currentPage * ROWS_PER_PAGE),
        [filteredSchedules, currentPage],
    );

    useEffect(() => {
        setCurrentPage(1);
    }, [searchText, staffFilter, dateFromFilter, dateToFilter, dayOffFilter]);

    useEffect(() => {
        if (currentPage > totalPages) {
            setCurrentPage(totalPages);
        }
    }, [currentPage, totalPages]);

    const clearFilters = () => {
        setSearchText('');
        setStaffFilter('');
        setDateFromFilter('');
        setDateToFilter('');
        setDayOffFilter('all');
    };

    return (
        <AuthenticatedLayout header="Schedules">
            <Head title="Schedules" />
            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Create or Assign Shift</h3>
                    <p className="mb-3 text-xs text-slate-500">
                        Default shift matches salon operating hours ({salonHoursLabel || `${defaultShiftStart}–${defaultShiftEnd}`}). Shifts must stay within these hours unless you mark a day off. Missing days for the next week are created automatically when you open this page; the server also fills gaps daily for the next 31 days. When leave is approved, those dates are marked as day off on the schedule. Use the buttons below if you need to run the same gap fill without the command line (for example after adding staff or if the nightly job was skipped).
                    </p>
                    <div className="mb-4 flex flex-wrap gap-2">
                        <button
                            type="button"
                            className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-800 shadow-sm hover:bg-slate-50 disabled:opacity-50"
                            disabled={Boolean(fillBusy)}
                            onClick={() => postFillGaps('week')}
                        >
                            {fillBusy === 'week' ? 'Filling…' : 'Fill missing schedules — 7 days'}
                        </button>
                        <button
                            type="button"
                            className="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-xs font-semibold text-indigo-900 shadow-sm hover:bg-indigo-100 disabled:opacity-50"
                            disabled={Boolean(fillBusy)}
                            onClick={() => postFillGaps('month')}
                        >
                            {fillBusy === 'month' ? 'Filling…' : 'Fill missing schedules — 31 days'}
                        </button>
                    </div>
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
                    <div className="grid gap-3 border-b border-slate-200 bg-slate-50 px-5 py-4 md:grid-cols-6">
                        <div className="md:col-span-2">
                            <label className="ta-field-label">Search</label>
                            <input className="ta-input" placeholder="Staff name or date" value={searchText} onChange={(e) => setSearchText(e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Staff</label>
                            <select className="ta-input" value={staffFilter} onChange={(e) => setStaffFilter(e.target.value)}>
                                <option value="">All staff</option>
                                {staffProfiles.map((s) => <option key={s.id} value={s.id}>{s.employee_code} {s.name}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="ta-field-label">From date</label>
                            <input className="ta-input" type="date" value={dateFromFilter} onChange={(e) => setDateFromFilter(e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">To date</label>
                            <input className="ta-input" type="date" value={dateToFilter} onChange={(e) => setDateToFilter(e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Day off</label>
                            <select className="ta-input" value={dayOffFilter} onChange={(e) => setDayOffFilter(e.target.value)}>
                                <option value="all">All</option>
                                <option value="working">Working days</option>
                                <option value="day_off">Day off only</option>
                            </select>
                        </div>
                        <div className="md:col-span-6 flex items-center justify-between">
                            <p className="text-xs text-slate-500">Showing {filteredSchedules.length} of {(schedules || []).length} schedule rows</p>
                            <button type="button" className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700" onClick={clearFilters}>Reset filters</button>
                        </div>
                    </div>
                    <div className="overflow-x-auto"><table className="min-w-full text-sm"><thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Date</th><th className="px-5 py-3">Staff</th><th className="px-5 py-3">Shift</th><th className="px-5 py-3">Break</th><th className="px-5 py-3">Day Off</th><th className="px-5 py-3">Actions</th></tr></thead><tbody>{pagedSchedules.map((s) => <tr key={s.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-600">{formatYmdForDisplay(s.schedule_date)}</td><td className="px-5 py-3 font-medium text-slate-700">{s.staff_name}</td><td className="px-5 py-3 text-slate-600">{s.start_time || '-'} - {s.end_time || '-'}</td><td className="px-5 py-3 text-slate-600">{s.break_start || '-'} - {s.break_end || '-'}</td><td className="px-5 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${s.is_day_off ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'}`}>{s.is_day_off ? 'Yes' : 'No'}</span></td><td className="px-5 py-3"><div className="flex flex-wrap gap-2"><button type="button" className="rounded-lg border border-indigo-300 bg-white px-2.5 py-1 text-xs font-semibold text-indigo-800 shadow-sm hover:bg-indigo-50" onClick={() => startEdit(s)}>Edit</button><button type="button" className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-800 hover:bg-red-100" onClick={() => setDeleteScheduleId(s.id)}>Delete</button></div></td></tr>)}{filteredSchedules.length === 0 && <tr><td className="px-5 py-3 text-slate-500" colSpan="6">No schedule rows match the selected filters.</td></tr>}</tbody></table></div>
                    <div className="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-xs text-slate-600">
                        <span>Page {currentPage} of {totalPages}</span>
                        <div className="flex gap-2">
                            <button type="button" className="rounded-lg border border-slate-200 px-2 py-1 disabled:opacity-50" disabled={currentPage <= 1} onClick={() => setCurrentPage((prev) => Math.max(1, prev - 1))}>Previous</button>
                            <button type="button" className="rounded-lg border border-slate-200 px-2 py-1 disabled:opacity-50" disabled={currentPage >= totalPages} onClick={() => setCurrentPage((prev) => Math.min(totalPages, prev + 1))}>Next</button>
                        </div>
                    </div>
                </section>

                <Modal show={Boolean(editingId)} onClose={closeEditModal} maxWidth="2xl">
                    <div className="p-6">
                        <h3 className="mb-4 text-base font-semibold text-slate-800">Edit schedule #{editingId}</h3>
                        <form onSubmit={(e) => { e.preventDefault(); editForm.put(route('schedules.update', editingId), { onSuccess: () => closeEditModal() }); }} className="grid gap-3 md:grid-cols-6">
                            <div><label className="ta-field-label">Start Time</label><input className="ta-input" type="time" value={editForm.data.start_time} onChange={(e) => editForm.setData('start_time', e.target.value)} />{fieldError(editForm, 'start_time')}</div>
                            <div><label className="ta-field-label">End Time</label><input className="ta-input" type="time" value={editForm.data.end_time} onChange={(e) => editForm.setData('end_time', e.target.value)} />{fieldError(editForm, 'end_time')}</div>
                            <div><label className="ta-field-label">Break Start</label><input className="ta-input" type="time" value={editForm.data.break_start} onChange={(e) => editForm.setData('break_start', e.target.value)} />{fieldError(editForm, 'break_start')}</div>
                            <div><label className="ta-field-label">Break End</label><input className="ta-input" type="time" value={editForm.data.break_end} onChange={(e) => editForm.setData('break_end', e.target.value)} />{fieldError(editForm, 'break_end')}</div>
                            <div className="md:col-span-2 flex items-center"><label className="text-sm text-slate-600"><input type="checkbox" checked={editForm.data.is_day_off} onChange={(e) => editForm.setData('is_day_off', e.target.checked)} className="mr-2" />Day off</label></div>
                            <div className="md:col-span-6"><input className="ta-input" placeholder="Notes" value={editForm.data.notes} onChange={(e) => editForm.setData('notes', e.target.value)} />{fieldError(editForm, 'notes')}</div>
                            <div className="md:col-span-6 flex gap-2"><button className="ta-btn-primary" disabled={editForm.processing}>Save</button><button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={closeEditModal}>Close</button></div>
                        </form>
                    </div>
                </Modal>

                <ConfirmActionModal
                    show={Boolean(deleteScheduleId)}
                    title="Remove this schedule row?"
                    message="Are you sure? This removes the shift entry for that staff member and date. You can recreate it from the form above."
                    confirmText="Delete"
                    onClose={() => !deleteBusy && setDeleteScheduleId(null)}
                    processing={deleteBusy}
                    onConfirm={() => {
                        if (!deleteScheduleId) return;
                        setDeleteBusy(true);
                        router.delete(route('schedules.destroy', deleteScheduleId), {
                            onFinish: () => {
                                setDeleteBusy(false);
                                setDeleteScheduleId(null);
                            },
                        });
                    }}
                />
            </div>
        </AuthenticatedLayout>
    );
}







