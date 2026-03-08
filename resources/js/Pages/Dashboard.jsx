import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';

const periodButtons = [
    { key: 'today', label: 'Today' },
    { key: 'week', label: 'This Week' },
    { key: 'month', label: 'This Month' },
];

export default function Dashboard({ stats, upcomingAppointments, selectedPeriod, periodLabel, range }) {
    const { flash } = usePage().props;

    const switchPeriod = (period) => {
        router.get(route('dashboard'), { period }, { preserveState: true, replace: true });
    };

    return (
        <AuthenticatedLayout header="Vina Operations Dashboard">
            <Head title="Dashboard" />
            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

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

                <section className="grid grid-cols-1 gap-4 md:grid-cols-3 lg:grid-cols-6">
                    {Object.entries(stats).map(([key, value]) => (
                        <div key={key} className="ta-card p-4">
                            <p className="text-xs uppercase text-slate-500">{key.replaceAll('_', ' ')}</p>
                            <p className="text-2xl font-semibold text-slate-800">{value}</p>
                        </div>
                    ))}
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4">
                        <h3 className="text-sm font-semibold text-slate-700">Appointments: {periodLabel}</h3>
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
            </div>
        </AuthenticatedLayout>
    );
}
