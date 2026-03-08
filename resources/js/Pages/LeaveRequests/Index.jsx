import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';

const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;

export default function LeaveRequestsIndex({ leaveRequests, staffProfiles }) {
    const { flash, auth } = usePage().props;
    const canReview = ['owner', 'manager'].includes(auth?.user?.role?.name);
    const isStaff = auth?.user?.role?.name === 'staff';
    const myProfileName = staffProfiles?.[0]?.name || 'My profile';

    const createForm = useForm({ staff_profile_id: '', start_date: '', end_date: '', reason: '' });
    const reviewForm = useForm({ status: '' });

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
                            <input className="ta-input" type="date" value={createForm.data.start_date} onChange={(e) => createForm.setData('start_date', e.target.value)} required />
                            {fieldError(createForm, 'start_date')}
                        </div>
                        <div>
                            <input className="ta-input" type="date" value={createForm.data.end_date} onChange={(e) => createForm.setData('end_date', e.target.value)} required />
                            {fieldError(createForm, 'end_date')}
                        </div>
                        <div>
                            <input className="ta-input" placeholder="Reason" value={createForm.data.reason} onChange={(e) => createForm.setData('reason', e.target.value)} required />
                            {fieldError(createForm, 'reason')}
                        </div>
                        <button className="ta-btn-primary" disabled={createForm.processing}>Submit</button>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4">
                        <h3 className="text-sm font-semibold text-slate-700">Request Queue</h3>
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
                                {leaveRequests.map((l) => (
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
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
