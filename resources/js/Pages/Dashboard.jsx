import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const periodButtons = [
    { key: 'today', label: 'Today' },
    { key: 'week', label: 'This Week' },
    { key: 'month', label: 'This Month' },
];

const staffPriorityKeys = [
    'appointments_today',
    'completed_appointments',
    'pending_leave_requests',
    'attendance_days',
    'late_minutes',
    'total_appointments',
];

const metricLabel = (key) => key.replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase());

const renderStars = (rating) => {
    const safe = Number(rating || 0);
    return '★'.repeat(Math.max(0, Math.min(5, safe))) + '☆'.repeat(Math.max(0, 5 - safe));
};

export default function Dashboard({
    stats,
    upcomingAppointments,
    awaitingCheckoutVisits = [],
    selectedPeriod,
    periodLabel,
    range,
    staffFeedbackOptions,
    staffToCustomerFeedback,
    customerToStaffReviews,
}) {
    const { flash, auth, app_timezone: appTimezoneProp } = usePage().props;
    const appTimezone = appTimezoneProp || 'Asia/Dubai';
    const canDailyBackup = Boolean(auth?.permissions?.can_run_daily_backup);
    const roleName = auth?.user?.role?.name;
    const isStaff = roleName === 'staff';
    const isCustomer = roleName === 'customer';
    const isManagerOrOwner = ['manager', 'owner'].includes(roleName);
    const [nfcBridgeStatus, setNfcBridgeStatus] = useState('');
    const [nfcBridgeOnline, setNfcBridgeOnline] = useState(null);
    const [nfcBridgeChecking, setNfcBridgeChecking] = useState(false);
    const [now, setNow] = useState(new Date());

    const staffToCustomerForm = useForm({ customer_id: '', comment: '' });
    const customerToStaffForm = useForm({ staff_profile_id: '', rating: '5', comment: '' });

    const switchPeriod = (period) => {
        router.get(route('dashboard'), { period }, { preserveState: true, replace: true });
    };

    const statEntries = Object.entries(stats || {});
    const orderedStaffStats = [
        ...statEntries.filter(([key]) => staffPriorityKeys.includes(key)),
        ...statEntries.filter(([key]) => !staffPriorityKeys.includes(key)),
    ];
    const visibleStats = isStaff ? orderedStaffStats.slice(0, 4) : statEntries;

    const checkNfcBridge = async () => {
        setNfcBridgeChecking(true);
        setNfcBridgeStatus('Checking NFC bridge connection...');
        try {
            await fetch('http://127.0.0.1:35791/uid?consume=0');
            setNfcBridgeOnline(true);
            setNfcBridgeStatus('NFC bridge connected. You can scan a card now.');
        } catch (error) {
            setNfcBridgeOnline(false);
            setNfcBridgeStatus('NFC bridge is offline. Start local bridge on http://127.0.0.1:35791.');
        } finally {
            setNfcBridgeChecking(false);
        }
    };

    useEffect(() => {
        checkNfcBridge();
    }, []);

    useEffect(() => {
        const timer = window.setInterval(() => setNow(new Date()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    const dashboardCurrentTime = useMemo(
        () =>
            new Intl.DateTimeFormat('en-US', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit',
                hour12: true,
                timeZone: appTimezone,
            }).format(now),
        [now, appTimezone],
    );

    return (
        <AuthenticatedLayout
            header={isStaff ? 'My Workspace' : 'Vina Operations Dashboard'}
            headerActions={
                <>
                    <button
                        type="button"
                        onClick={checkNfcBridge}
                        disabled={nfcBridgeChecking}
                        className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {nfcBridgeChecking ? 'Connecting...' : 'Connect NFC'}
                    </button>
                    <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-right">
                        <p className="text-[10px] uppercase tracking-[0.16em] text-slate-500">Current Time</p>
                        <p className="text-base font-semibold text-slate-700">{dashboardCurrentTime}</p>
                    </div>
                </>
            }
        >
            <Head title="Dashboard" />
            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}
                {awaitingCheckoutVisits?.length > 0 && (
                    <section id="checkout-alerts" className="ta-card border-amber-200 bg-amber-50/90 p-4">
                        <h3 className="mb-2 text-sm font-semibold text-amber-950">Completed visits — payment or receipt pending</h3>
                        <p className="mb-3 text-xs text-amber-900/90">
                            These services are finished but still need a tax receipt and/or payment. Open the invoice to issue the receipt and record how the client paid (including gift cards).
                        </p>
                        <ul className="space-y-2 text-sm">
                            {awaitingCheckoutVisits.map((row) => (
                                <li key={row.id} className="flex flex-wrap items-center justify-between gap-2 border-b border-amber-200/60 py-2 last:border-0">
                                    <span className="text-amber-950">
                                        #{row.id} · {row.customer_name} · {row.service_name || 'Service'}
                                        {row.scheduled_start && (
                                            <span className="ml-1 text-xs text-amber-900/80">({new Date(row.scheduled_start).toLocaleString()})</span>
                                        )}
                                    </span>
                                    {row.invoice_id ? (
                                        <Link
                                            href={route('finance.invoices.show', row.invoice_id)}
                                            className="shrink-0 rounded-lg bg-amber-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-800"
                                        >
                                            Open invoice
                                        </Link>
                                    ) : (
                                        <Link
                                            href={route('appointments.index', { status: 'completed' })}
                                            className="shrink-0 text-xs font-semibold text-amber-900 underline"
                                        >
                                            Go to appointments
                                        </Link>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
                {nfcBridgeOnline === false && (
                    <div className="ta-card border-amber-200 bg-amber-50 p-3 text-sm text-amber-700">
                        NFC bridge is offline. Keep the bridge running on the same device where the card reader is connected.
                    </div>
                )}
                {nfcBridgeStatus && (
                    <div className="ta-card border-sky-200 bg-sky-50 p-3 text-sm text-sky-700">{nfcBridgeStatus}</div>
                )}

                {canDailyBackup && (
                    <section id="daily-backup" className="ta-card scroll-mt-24 border-rose-100 bg-rose-50/80 p-5">
                        <h3 className="text-base font-semibold text-slate-800">Daily database backup</h3>
                        <p className="mt-1 text-sm text-slate-600">
                            Download a snapshot of the salon database for safekeeping. Run this at least once per day (for example end of shift). Owners always have this; assign the permission to reception in Roles if needed.
                        </p>
                        <div className="mt-4">
                            <a
                                href={route('backup.daily')}
                                className="inline-flex items-center rounded-xl border border-rose-200 bg-white px-4 py-2 text-sm font-semibold text-rose-800 shadow-sm hover:bg-rose-50"
                            >
                                Download backup now
                            </a>
                        </div>
                    </section>
                )}

                {isStaff && (
                    <section className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div className="ta-card p-5">
                            <h3 className="text-base font-semibold text-slate-800">Quick Actions</h3>
                            <p className="mt-1 text-sm text-slate-500">Common tasks you need every day.</p>
                            <div className="mt-4 flex flex-wrap gap-2">
                                <Link href={route('attendance.index')} className="rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-700">Attendance</Link>
                                <Link href={route('leave-requests.index')} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Leave Request</Link>
                                <Link href={route('profile.edit')} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">My Profile</Link>
                            </div>
                        </div>
                        <div className="ta-card p-5">
                            <h3 className="text-base font-semibold text-slate-800">Schedule View</h3>
                            <p className="mt-1 text-sm text-slate-500">Showing {periodLabel} ({range?.from} to {range?.to}).</p>
                            <div className="mt-4 flex gap-2">
                                {periodButtons.map((button) => (
                                    <button
                                        key={button.key}
                                        className={`rounded-xl border px-3 py-1.5 text-sm ${selectedPeriod === button.key ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'}`}
                                        onClick={() => switchPeriod(button.key)}
                                    >
                                        {button.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </section>
                )}

                {!isStaff && (
                    <section className="ta-card p-5">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 className="text-sm font-semibold text-slate-700">Quick Filters</h3>
                                <p className="text-xs text-slate-500">Showing <span className="font-medium text-slate-700">{periodLabel}</span> ({range?.from} to {range?.to})</p>
                            </div>
                            <div className="flex gap-2">
                                {periodButtons.map((button) => (
                                    <button
                                        key={button.key}
                                        className={`rounded-xl border px-3 py-1.5 text-sm ${selectedPeriod === button.key ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'}`}
                                        onClick={() => switchPeriod(button.key)}
                                    >
                                        {button.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </section>
                )}

                <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {visibleStats.map(([key, value]) => (
                        <div key={key} className="ta-card p-4">
                            <p className="text-xs uppercase text-slate-500">{metricLabel(key)}</p>
                            <p className="text-2xl font-semibold text-slate-800">{value}</p>
                        </div>
                    ))}
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4">
                        <h3 className="text-sm font-semibold text-slate-700">{isStaff ? 'My Upcoming Appointments' : `Appointments: ${periodLabel}`}</h3>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-5 py-3">Time</th>
                                    <th className="px-5 py-3">Customer</th>
                                    <th className="px-5 py-3">Service</th>
                                    <th className="px-5 py-3">Staff</th>
                                    <th className="px-5 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {upcomingAppointments.length === 0 && (
                                    <tr>
                                        <td className="px-5 py-4 text-slate-500" colSpan="5">No appointments for this period.</td>
                                    </tr>
                                )}
                                {upcomingAppointments.map((row) => (
                                    <tr key={row.id} className="border-t border-slate-100">
                                        <td className="px-5 py-3 text-slate-600">{new Date(row.scheduled_start).toLocaleString()}</td>
                                        <td className="px-5 py-3 text-slate-700">{row.customer_name}</td>
                                        <td className="px-5 py-3 text-slate-600">{row.service_name || 'N/A'}</td>
                                        <td className="px-5 py-3 text-slate-600">{row.staff_name || 'Unassigned'}</td>
                                        <td className="px-5 py-3 text-slate-600">{row.status}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                {(isStaff || isCustomer) && (
                    <section className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        {isStaff && (
                            <div className="ta-card p-5">
                                <h3 className="text-base font-semibold text-slate-800">Staff Feedback to Customer</h3>
                                <p className="mt-1 text-sm text-slate-500">Send service notes to customer records.</p>
                                <form
                                    className="mt-4 space-y-3"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        staffToCustomerForm.post(route('feedback.staff-to-customer.store'), {
                                            onSuccess: () => staffToCustomerForm.reset('customer_id', 'comment'),
                                        });
                                    }}
                                >
                                    <div>
                                        <label className="ta-field-label">Customer</label>
                                        <select className="ta-input" value={staffToCustomerForm.data.customer_id} onChange={(e) => staffToCustomerForm.setData('customer_id', e.target.value)} required>
                                            <option value="">Select customer</option>
                                            {(staffFeedbackOptions?.customers || []).map((customer) => (
                                                <option key={customer.id} value={customer.id}>{customer.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <label className="ta-field-label">Feedback Comment</label>
                                        <textarea className="ta-input min-h-[110px]" placeholder="Write feedback for this customer" value={staffToCustomerForm.data.comment} onChange={(e) => staffToCustomerForm.setData('comment', e.target.value)} required />
                                    </div>
                                    <button className="ta-btn-primary" disabled={staffToCustomerForm.processing}>Submit Feedback</button>
                                </form>
                            </div>
                        )}

                        {isCustomer && (
                            <div className="ta-card p-5">
                                <h3 className="text-base font-semibold text-slate-800">Review Staff</h3>
                                <p className="mt-1 text-sm text-slate-500">Share your experience with the team.</p>
                                <form
                                    className="mt-4 space-y-3"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        customerToStaffForm.post(route('feedback.customer-to-staff.store'), {
                                            onSuccess: () => customerToStaffForm.reset('staff_profile_id', 'rating', 'comment'),
                                        });
                                    }}
                                >
                                    <div>
                                        <label className="ta-field-label">Staff Member</label>
                                        <select className="ta-input" value={customerToStaffForm.data.staff_profile_id} onChange={(e) => customerToStaffForm.setData('staff_profile_id', e.target.value)} required>
                                            <option value="">Select staff</option>
                                            {(staffFeedbackOptions?.staffProfiles || []).map((staff) => (
                                                <option key={staff.id} value={staff.id}>{staff.employee_code} - {staff.name}</option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <label className="ta-field-label">Rating</label>
                                        <select className="ta-input" value={customerToStaffForm.data.rating} onChange={(e) => customerToStaffForm.setData('rating', e.target.value)} required>
                                            <option value="5">5 - Excellent</option>
                                            <option value="4">4 - Good</option>
                                            <option value="3">3 - Average</option>
                                            <option value="2">2 - Poor</option>
                                            <option value="1">1 - Very Poor</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className="ta-field-label">Review Comment</label>
                                        <textarea className="ta-input min-h-[110px]" placeholder="Tell us about your experience" value={customerToStaffForm.data.comment} onChange={(e) => customerToStaffForm.setData('comment', e.target.value)} required />
                                    </div>
                                    <button className="ta-btn-primary" disabled={customerToStaffForm.processing}>Submit Review</button>
                                </form>
                            </div>
                        )}
                    </section>
                )}

                {(isStaff || isManagerOrOwner || isCustomer) && (
                    <section className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div className="ta-card overflow-hidden">
                            <div className="border-b border-slate-200 px-5 py-4">
                                <h3 className="text-sm font-semibold text-slate-700">Customer Reviews About Staff</h3>
                            </div>
                            <div className="max-h-80 overflow-auto px-5 py-3 text-sm">
                                {customerToStaffReviews?.length ? customerToStaffReviews.map((row) => (
                                    <div key={row.id} className="border-b border-slate-100 py-3 last:border-0">
                                        <p className="font-semibold text-slate-700">{row.staff_name || 'Staff'} • <span className="text-amber-600">{renderStars(row.rating)}</span></p>
                                        <p className="text-xs text-slate-500">By {row.reviewer_name || 'Customer'} on {new Date(row.created_at).toLocaleString()}</p>
                                        <p className="mt-1 text-slate-600">{row.comment}</p>
                                    </div>
                                )) : <p className="text-slate-500">No customer reviews yet.</p>}
                            </div>
                        </div>

                        <div className="ta-card overflow-hidden">
                            <div className="border-b border-slate-200 px-5 py-4">
                                <h3 className="text-sm font-semibold text-slate-700">Staff Feedback To Customers</h3>
                            </div>
                            <div className="max-h-80 overflow-auto px-5 py-3 text-sm">
                                {staffToCustomerFeedback?.length ? staffToCustomerFeedback.map((row) => (
                                    <div key={row.id} className="border-b border-slate-100 py-3 last:border-0">
                                        <p className="font-semibold text-slate-700">{row.staff_name || 'Staff'} to {row.customer_name || 'Customer'}</p>
                                        <p className="text-xs text-slate-500">{new Date(row.created_at).toLocaleString()}</p>
                                        <p className="mt-1 text-slate-600">{row.comment}</p>
                                    </div>
                                )) : <p className="text-slate-500">No staff feedback yet.</p>}
                            </div>
                        </div>
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
