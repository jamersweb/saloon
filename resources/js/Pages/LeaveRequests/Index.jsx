import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect } from 'react';

const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;

export default function LeaveRequestsIndex({ leaveRequests, staffProfiles, filters }) {
    const { flash, auth } = usePage().props;
    const canReview = ['owner', 'manager'].includes(auth?.user?.role?.name);
    const isStaff = auth?.user?.role?.name === 'staff';
    const myProfileName = staffProfiles?.[0]?.name || 'My profile';

    const createForm = useForm({ staff_profile_id: '', start_date: '', end_date: '', reason: '' });
    const reviewForm = useForm({ status: '' });
    const filterForm = useForm({
        staff_profile_id: filters?.staff_profile_id ? String(filters.staff_profile_id) : '',
        status: filters?.status || 'all',
        date_from: filters?.date_from || '',
        date_to: filters?.date_to || '',
        per_page: String(filters?.per_page || 10),
    });

    useEffect(() => {
        filterForm.setData({
            staff_profile_id: filters?.staff_profile_id ? String(filters.staff_profile_id) : '',
            status: filters?.status || 'all',
            date_from: filters?.date_from || '',
            date_to: filters?.date_to || '',
            per_page: String(filters?.per_page || 10),
        });
    }, [filters?.staff_profile_id, filters?.status, filters?.date_from, filters?.date_to, filters?.per_page]);

    const review = (id, status) => {
        reviewForm.setData('status', status);
        reviewForm.patch(route('leave-requests.review', id), { preserveScroll: true });
    };

    const badgeClass = (status) => {
        if (status === 'approved') return 'bg-emerald-100 text-emerald-700';
        if (status === 'rejected') return 'bg-red-100 text-red-700';
        if (status === 'cancelled') return 'bg-slate-200 text-slate-700';
        return 'bg-amber-100 text-amber-700';
    };

    const applyFilters = () => {
        router.get(route('leave-requests.index'), {
            staff_profile_id: !isStaff ? (filterForm.data.staff_profile_id || undefined) : undefined,
            status: filterForm.data.status,
            date_from: filterForm.data.date_from || undefined,
            date_to: filterForm.data.date_to || undefined,
            per_page: filterForm.data.per_page,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const resetFilters = () => {
        const defaults = {
            staff_profile_id: isStaff ? (filters?.staff_profile_id ? String(filters.staff_profile_id) : '') : '',
            status: 'all',
            date_from: '',
            date_to: '',
            per_page: '10',
        };

        filterForm.setData(defaults);

        router.get(route('leave-requests.index'), {
            staff_profile_id: !isStaff ? undefined : defaults.staff_profile_id || undefined,
            status: defaults.status,
            per_page: defaults.per_page,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    return (
        <AuthenticatedLayout header="Leave Requests">
            <Head title="Leave Requests" />

            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Submit Leave Request</h3>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            createForm.post(route('leave-requests.store'), { onSuccess: () => createForm.reset() });
                        }}
                        className="grid gap-3 md:grid-cols-5"
                    >
                        <div>
                            <label className="ta-field-label">Staff Profile</label>
                            {isStaff ? (
                                <div className="ta-input flex items-center bg-slate-50">{myProfileName}</div>
                            ) : (
                                <select className="ta-input" value={createForm.data.staff_profile_id} onChange={(e) => createForm.setData('staff_profile_id', e.target.value)}>
                                    <option value="">My profile</option>
                                    {staffProfiles.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                </select>
                            )}
                            {fieldError(createForm, 'staff_profile_id')}
                        </div>
                        <div>
                            <label className="ta-field-label">Start Date</label>
                            <input className="ta-input" type="date" value={createForm.data.start_date} onChange={(e) => createForm.setData('start_date', e.target.value)} required />
                            {fieldError(createForm, 'start_date')}
                        </div>
                        <div>
                            <label className="ta-field-label">End Date</label>
                            <input className="ta-input" type="date" value={createForm.data.end_date} onChange={(e) => createForm.setData('end_date', e.target.value)} required />
                            {fieldError(createForm, 'end_date')}
                        </div>
                        <div>
                            <label className="ta-field-label">Reason</label>
                            <input className="ta-input" placeholder="Reason" value={createForm.data.reason} onChange={(e) => createForm.setData('reason', e.target.value)} required />
                            {fieldError(createForm, 'reason')}
                        </div>
                        <button className="ta-btn-primary" disabled={createForm.processing}>Submit</button>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4">
                        <h3 className="text-sm font-semibold text-slate-700">Request Queue</h3>
                        <p className="mt-1 text-xs text-slate-500">Showing {leaveRequests?.from || 0}-{leaveRequests?.to || 0} of {leaveRequests?.total || 0} leave requests</p>
                    </div>
                    <div className={`grid gap-3 border-b border-slate-200 bg-slate-50 px-5 py-4 ${isStaff ? 'sm:grid-cols-2 xl:grid-cols-4' : 'sm:grid-cols-2 xl:grid-cols-5'}`}>
                        {!isStaff ? (
                            <select className="ta-input w-full min-w-0" value={filterForm.data.staff_profile_id} onChange={(e) => filterForm.setData('staff_profile_id', e.target.value)}>
                                <option value="">All staff</option>
                                {staffProfiles.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                            </select>
                        ) : (
                            <div className="ta-input min-w-0 bg-white">{myProfileName}</div>
                        )}
                        <select className="ta-input w-full min-w-0" value={filterForm.data.status} onChange={(e) => filterForm.setData('status', e.target.value)}>
                            <option value="all">All statuses</option>
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                        <input className="ta-input w-full min-w-0" type="date" value={filterForm.data.date_from} onChange={(e) => filterForm.setData('date_from', e.target.value)} />
                        <input className="ta-input w-full min-w-0" type="date" value={filterForm.data.date_to} onChange={(e) => filterForm.setData('date_to', e.target.value)} />
                        <div className="grid gap-3 sm:flex sm:flex-wrap sm:items-center">
                            <select className="ta-input w-full min-w-0 sm:max-w-[140px]" value={filterForm.data.per_page} onChange={(e) => filterForm.setData('per_page', e.target.value)}>
                                {[10, 25, 50, 100].map((size) => <option key={size} value={size}>{size} / page</option>)}
                            </select>
                            <button type="button" className="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 sm:w-auto" onClick={applyFilters}>Apply Filters</button>
                            <button type="button" className="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-500 sm:w-auto" onClick={resetFilters}>Reset</button>
                        </div>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-5 py-3">Staff</th>
                                    <th className="px-5 py-3">Date Range</th>
                                    <th className="px-5 py-3">Reason</th>
                                    <th className="px-5 py-3">Status</th>
                                    <th className="px-5 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {(leaveRequests?.data || []).map((l) => (
                                    <tr key={l.id} className="border-t border-slate-100 align-top">
                                        <td className="px-5 py-3 font-medium text-slate-700">{l.staff_name || '-'}</td>
                                        <td className="px-5 py-3 text-slate-600">{l.start_date?.slice(0, 10)} to {l.end_date?.slice(0, 10)}</td>
                                        <td className="px-5 py-3 text-slate-600">{l.reason}</td>
                                        <td className="px-5 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${badgeClass(l.status)}`}>{l.status}</span></td>
                                        <td className="px-5 py-3">
                                            {canReview && l.status === 'pending' ? (
                                                <div className="flex gap-2">
                                                    <button className="rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700" onClick={() => review(l.id, 'approved')}>Approve</button>
                                                    <button className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700" onClick={() => review(l.id, 'rejected')}>Reject</button>
                                                    <button className="rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-medium text-slate-700" onClick={() => review(l.id, 'cancelled')}>Cancel</button>
                                                </div>
                                            ) : (
                                                <span className="text-xs text-slate-400">No action</span>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {(leaveRequests?.data || []).length === 0 && (
                                    <tr>
                                        <td colSpan="5" className="px-5 py-8 text-center text-sm text-slate-500">No leave requests match the current filters.</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-3 text-xs text-slate-600">
                        <span>Page {leaveRequests?.current_page || 1} of {leaveRequests?.last_page || 1}</span>
                        <div className="flex flex-wrap gap-2">
                            {(leaveRequests?.links || []).map((link) => (
                                <Link
                                    key={`${link.label}-${link.url || 'null'}`}
                                    href={link.url || '#'}
                                    preserveState
                                    className={`rounded-lg border px-3 py-1 ${link.active ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-600'} ${!link.url ? 'pointer-events-none opacity-50' : 'hover:bg-slate-50'}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}









