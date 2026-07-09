import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export default function AttendanceIndex({ logs, staffProfiles, todayLog, todayLogsByStaffProfile = {}, appTimezone, filters }) {
    const { errors, auth, flash } = usePage().props;
    const isStaff = auth?.user?.role?.name === 'staff';
    const myProfileName = staffProfiles?.[0]?.name || 'My profile';
    const clockInForm = useForm({ staff_profile_id: '', clock_in_latitude: '', clock_in_longitude: '' });
    const clockOutForm = useForm({ staff_profile_id: '' });
    const filterForm = useForm({
        staff_profile_id: filters?.staff_profile_id || '',
        date_from: filters?.date_from || '',
        date_to: filters?.date_to || '',
        per_page: String(filters?.per_page || 10),
    });
    const [now, setNow] = useState(new Date());
    const [toast, setToast] = useState('');
    const [clockInSubmitting, setClockInSubmitting] = useState(false);
    const selectedClockInStaffProfileId = isStaff
        ? staffProfiles?.[0]?.id
        : clockInForm.data.staff_profile_id || filters?.staff_profile_id || '';
    const selectedClockOutStaffProfileId = isStaff
        ? staffProfiles?.[0]?.id
        : clockOutForm.data.staff_profile_id || filters?.staff_profile_id || '';
    const clockInTodayLog = selectedClockInStaffProfileId
        ? todayLogsByStaffProfile?.[selectedClockInStaffProfileId] || null
        : todayLog;
    const clockOutTodayLog = selectedClockOutStaffProfileId
        ? todayLogsByStaffProfile?.[selectedClockOutStaffProfileId] || null
        : todayLog;
    const hasClockOutClockedIn = Boolean(clockOutTodayLog?.clock_in);
    const hasClockOutClockedOut = Boolean(clockOutTodayLog?.clock_out);
    const hasOpenClockOut = hasClockOutClockedIn && !hasClockOutClockedOut;
    const hasClockInOpenForSelected = Boolean(clockInTodayLog?.clock_in) && !clockInTodayLog?.clock_out;
    const hasClockInCompleteForSelected = Boolean(clockInTodayLog?.clock_in && clockInTodayLog?.clock_out);
    const disableClockIn = clockInSubmitting || clockInForm.processing || Boolean(clockInTodayLog?.clock_in);
    const disableClockOut = clockOutForm.processing || !hasOpenClockOut;

    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    useEffect(() => {
        if (!flash?.status) return undefined;
        setToast(flash.status);
        const timer = window.setTimeout(() => setToast(''), 4200);
        return () => window.clearTimeout(timer);
    }, [flash?.status]);

    const currentTime = new Intl.DateTimeFormat('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
        timeZone: appTimezone || 'UTC',
    }).format(now);

    useEffect(() => {
        filterForm.setData({
            staff_profile_id: filters?.staff_profile_id || '',
            date_from: filters?.date_from || '',
            date_to: filters?.date_to || '',
            per_page: String(filters?.per_page || 10),
        });
    }, [filters?.staff_profile_id, filters?.date_from, filters?.date_to, filters?.per_page]);

    const applyFilters = () => {
        router.get(route('attendance.index'), {
            staff_profile_id: filterForm.data.staff_profile_id || undefined,
            date_from: filterForm.data.date_from || undefined,
            date_to: filterForm.data.date_to || undefined,
            per_page: filterForm.data.per_page,
        }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const resetFilters = () => {
        const defaults = {
            staff_profile_id: '',
            date_from: '',
            date_to: '',
            per_page: '10',
        };

        filterForm.setData(defaults);

        router.get(route('attendance.index'), defaults, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const requestLocation = () => new Promise((resolve) => {
        if (!navigator.geolocation) {
            resolve(null);
            return;
        }

        navigator.geolocation.getCurrentPosition(
            (position) => resolve(position),
            () => resolve(null),
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0,
            },
        );
    });

    const submitClockIn = async (e) => {
        e.preventDefault();
        if (disableClockIn) return;

        setClockInSubmitting(true);
        clockInForm.clearErrors('clock_in_latitude', 'clock_in_longitude');
        const position = await requestLocation();
        const latitude = position ? String(position.coords.latitude) : '';
        const longitude = position ? String(position.coords.longitude) : '';
        clockInForm.setData('clock_in_latitude', latitude);
        clockInForm.setData('clock_in_longitude', longitude);
        clockInForm.post(route('attendance.clock-in'), {
            data: {
                ...clockInForm.data,
                clock_in_latitude: latitude,
                clock_in_longitude: longitude,
            },
            onSuccess: () => setToast('Clock in recorded.'),
            onFinish: () => setClockInSubmitting(false),
        });
    };

    return (
        <AuthenticatedLayout header="Attendance">
            <Head title="Attendance" />
            <div className="space-y-6">
                {toast && (
                    <div className="pointer-events-none fixed right-4 top-4 z-[80]">
                        <div className="pointer-events-auto min-w-[280px] max-w-[460px] rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-700 shadow-lg">
                            <div className="flex items-start justify-between gap-3">
                                <p>{toast}</p>
                                <button type="button" className="text-xs font-semibold opacity-80 hover:opacity-100" onClick={() => setToast('')}>Close</button>
                            </div>
                        </div>
                    </div>
                )}
                {Object.keys(errors).length > 0 && <div className="ta-card border-red-200 bg-red-50 p-3 text-sm text-red-700">{Object.values(errors)[0]}</div>}

                <section className="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div className="ta-card p-4">
                        <p className="text-xs uppercase text-slate-500">Today</p>
                        <p className="mt-2 text-lg font-semibold text-slate-800">{todayLog?.attendance_date?.slice(0, 10) || 'No record yet'}</p>
                    </div>
                    <div className="ta-card p-4">
                        <p className="text-xs uppercase text-slate-500">Current Time</p>
                        <p className="mt-2 text-lg font-semibold text-slate-800">{currentTime}</p>
                    </div>
                    <div className="ta-card p-4">
                        <p className="text-xs uppercase text-slate-500">Clock In</p>
                        <p className="mt-2 text-lg font-semibold text-slate-800">{todayLog?.clock_in || '--:--'}</p>
                    </div>
                    <div className="ta-card p-4">
                        <p className="text-xs uppercase text-slate-500">Clock Out</p>
                        <p className="mt-2 text-lg font-semibold text-slate-800">{todayLog?.clock_out || '--:--'}</p>
                    </div>
                </section>

                <section className="grid gap-4 md:grid-cols-2">
                    <form onSubmit={submitClockIn} className="ta-card space-y-3 p-5">
                        <h3 className="text-sm font-semibold text-slate-700">Clock In</h3>
                        <label className="ta-field-label">Staff Profile</label>
                        {isStaff ? (
                            <div className="ta-input flex items-center bg-slate-50">{myProfileName}</div>
                        ) : (
                            <select className="ta-input" value={clockInForm.data.staff_profile_id} onChange={(e) => clockInForm.setData('staff_profile_id', e.target.value)}>
                                <option value="">My profile</option>
                                {staffProfiles.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </select>
                        )}
                        <p className="text-xs text-slate-500">Location is optional. If browser permission is blocked, clock in will still work.</p>
                        {clockInForm.errors.clock_in_latitude && <p className="text-xs text-red-600">{clockInForm.errors.clock_in_latitude}</p>}
                        {hasClockInOpenForSelected && <p className="text-xs text-amber-600">Already clocked in. Clock out before clocking in again.</p>}
                        {hasClockInCompleteForSelected && <p className="text-xs text-slate-500">Today&apos;s attendance is complete.</p>}
                        <button className="ta-btn-primary w-full disabled:cursor-not-allowed disabled:opacity-60" disabled={disableClockIn}>Clock In</button>
                    </form>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (disableClockOut) return;

                            clockOutForm.post(route('attendance.clock-out'), {
                                data: {
                                    ...clockOutForm.data,
                                },
                                onSuccess: () => setToast('Clock out recorded.'),
                            });
                        }}
                        className="ta-card space-y-3 p-5"
                    >
                        <h3 className="text-sm font-semibold text-slate-700">Clock Out</h3>
                        <label className="ta-field-label">Staff Profile</label>
                        {isStaff ? (
                            <div className="ta-input flex items-center bg-slate-50">{myProfileName}</div>
                        ) : (
                            <select className="ta-input" value={clockOutForm.data.staff_profile_id} onChange={(e) => clockOutForm.setData('staff_profile_id', e.target.value)}>
                                <option value="">My profile</option>
                                {staffProfiles.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </select>
                        )}
                        {!hasClockOutClockedIn && <p className="text-xs text-slate-500">Clock in before clocking out.</p>}
                        {hasClockOutClockedOut && <p className="text-xs text-slate-500">Already clocked out today.</p>}
                        <button className="ta-btn-secondary w-full disabled:cursor-not-allowed disabled:opacity-60" disabled={disableClockOut}>Clock Out</button>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h3 className="text-sm font-semibold text-slate-700">Attendance Log</h3>
                            <span className="text-xs text-slate-500">Showing {logs.from || 0}-{logs.to || 0} of {logs.total || 0}</span>
                        </div>
                    </div>
                    <form className="border-b border-slate-100 px-5 py-4" onSubmit={(e) => { e.preventDefault(); applyFilters(); }}>
                        <div className={`grid gap-3 ${isStaff ? 'sm:grid-cols-3' : 'sm:grid-cols-2 xl:grid-cols-4'}`}>
                            {!isStaff && (
                                <select className="ta-input w-full min-w-0" value={filterForm.data.staff_profile_id} onChange={(e) => filterForm.setData('staff_profile_id', e.target.value)}>
                                    <option value="">All staff profiles</option>
                                    {staffProfiles.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                </select>
                            )}
                            <input className="ta-input w-full min-w-0" type="date" value={filterForm.data.date_from} onChange={(e) => filterForm.setData('date_from', e.target.value)} />
                            <input className="ta-input w-full min-w-0" type="date" value={filterForm.data.date_to} onChange={(e) => filterForm.setData('date_to', e.target.value)} />
                            <select className="ta-input w-full min-w-0" value={filterForm.data.per_page} onChange={(e) => filterForm.setData('per_page', e.target.value)}>
                                <option value="10">10 / page</option>
                                <option value="25">25 / page</option>
                                <option value="50">50 / page</option>
                                <option value="100">100 / page</option>
                            </select>
                        </div>
                        <div className="mt-3 grid gap-3 sm:flex sm:flex-wrap">
                            <button className="ta-btn-primary w-full sm:w-auto" type="submit">Apply Filters</button>
                            <button type="button" className="w-full rounded-xl border border-slate-200 px-4 py-2 text-sm text-slate-700 sm:w-auto" onClick={resetFilters}>Reset</button>
                        </div>
                    </form>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-5 py-3">Date</th>
                                    <th className="px-5 py-3">Staff</th>
                                    <th className="px-5 py-3">In</th>
                                    <th className="px-5 py-3">Location</th>
                                    <th className="px-5 py-3">Out</th>
                                    <th className="px-5 py-3">Late</th>
                                </tr>
                            </thead>
                            <tbody>
                                {logs.data.length === 0 && (
                                    <tr>
                                        <td className="px-5 py-4 text-slate-500" colSpan="6">No attendance records available.</td>
                                    </tr>
                                )}
                                {logs.data.map((l) => (
                                    <tr key={l.id} className="border-t border-slate-100">
                                        <td className="px-5 py-3 text-slate-600">{l.attendance_date?.slice(0, 10)}</td>
                                        <td className="px-5 py-3 font-medium text-slate-700">{l.staff_name}</td>
                                        <td className="px-5 py-3 text-slate-600">{l.clock_in || '--:--'}</td>
                                        <td className="px-5 py-3 text-slate-600">
                                            {l.clock_in_location_url ? (
                                                <a className="text-indigo-600 underline" href={l.clock_in_location_url} target="_blank" rel="noreferrer">View map</a>
                                            ) : (
                                                '--'
                                            )}
                                        </td>
                                        <td className="px-5 py-3 text-slate-600">{l.clock_out || '--:--'}</td>
                                        <td className="px-5 py-3 text-slate-600">{l.late_minutes || 0} min</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {logs.links?.length > 3 && (
                        <div className="flex flex-wrap gap-2 border-t border-slate-100 px-5 py-3 text-sm">
                            {logs.links.map((link, i) =>
                                link.url ? (
                                    <Link key={i} href={link.url} className={`rounded-lg px-3 py-1 ${link.active ? 'bg-indigo-100 text-indigo-800' : 'bg-slate-100 text-slate-600'}`} preserveScroll preserveState dangerouslySetInnerHTML={{ __html: link.label }} />
                                ) : (
                                    <span key={i} className="px-3 py-1 text-slate-400" dangerouslySetInnerHTML={{ __html: link.label }} />
                                ),
                            )}
                        </div>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}



