import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';

const toMoney = (value) => new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' }).format(Number(value || 0));

function HorizontalBarChart({ rows, labelKey, valueKey, colorClass }) {
    const max = Math.max(...rows.map((row) => Number(row[valueKey] || 0)), 1);

    return (
        <div className="space-y-2">
            {rows.map((row, idx) => {
                const value = Number(row[valueKey] || 0);
                const width = Math.max(6, Math.round((value / max) * 100));
                return (
                    <div key={`${row[labelKey]}-${idx}`}>
                        <div className="mb-1 flex items-center justify-between text-xs text-slate-600">
                            <span className="truncate pr-3">{row[labelKey]}</span>
                            <span className="font-semibold text-slate-700">{value}</span>
                        </div>
                        <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                            <div className={`h-2 rounded-full ${colorClass}`} style={{ width: `${width}%` }} />
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

function RevenueTrendChart({ data }) {
    const width = 560;
    const height = 220;
    const pad = 24;
    const values = data.map((row) => Number(row.revenue || 0));
    const max = Math.max(...values, 1);

    const points = values.map((value, index) => {
        const x = pad + (index * (width - (pad * 2))) / Math.max(values.length - 1, 1);
        const y = height - pad - ((value / max) * (height - (pad * 2)));
        return `${x},${y}`;
    }).join(' ');

    return (
        <div>
            <svg viewBox={`0 0 ${width} ${height}`} className="h-56 w-full">
                <line x1={pad} y1={height - pad} x2={width - pad} y2={height - pad} stroke="#d1d5db" strokeWidth="1" />
                <line x1={pad} y1={pad} x2={pad} y2={height - pad} stroke="#d1d5db" strokeWidth="1" />
                <polyline points={points} fill="none" stroke="#4f46e5" strokeWidth="3" strokeLinejoin="round" strokeLinecap="round" />
                {values.map((value, index) => {
                    const x = pad + (index * (width - (pad * 2))) / Math.max(values.length - 1, 1);
                    const y = height - pad - ((value / max) * (height - (pad * 2)));
                    return <circle key={index} cx={x} cy={y} r="3" fill="#312e81" />;
                })}
            </svg>
            <div className="mt-2 grid grid-cols-2 gap-2 text-xs text-slate-500 md:grid-cols-4">
                {data.slice(-4).map((row) => (
                    <div key={row.date} className="rounded-lg border border-slate-200 px-2 py-1">
                        <p>{row.date}</p>
                        <p className="font-semibold text-slate-700">{toMoney(row.revenue)}</p>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function ReportsIndex({ filters, overview, statusBreakdown, servicePerformance, staffPerformance, dailyRevenue, waitingTimeByStaff, lateMinutesByStaff }) {
    const { auth } = usePage().props;
    const canExport = Boolean(auth?.permissions?.can_export_reports);

    const applyFilter = (key, value) => {
        router.get(route('reports.index'), { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    const exportReport = (type) => {
        if (!canExport) {
            return;
        }

        const params = new URLSearchParams({ type, date_from: filters.date_from, date_to: filters.date_to });
        window.location.href = `${route('reports.export')}?${params.toString()}`;
    };

    const exportPdf = () => {
        if (!canExport) {
            return;
        }

        const params = new URLSearchParams({ date_from: filters.date_from, date_to: filters.date_to });
        window.location.href = `${route('reports.export.pdf')}?${params.toString()}`;
    };

    const statusRows = Object.entries(statusBreakdown).map(([status, total]) => ({ status: status.replaceAll('_', ' '), total }));

    return (
        <AuthenticatedLayout header="Reports & Exports">
            <Head title="Reports" />

            <div className="space-y-6">
                <section className="ta-card p-5">
                    <div className="grid gap-3 md:grid-cols-4">
                        <div><label className="mb-1 block text-xs font-semibold uppercase text-slate-500">From</label><input className="ta-input" type="date" value={filters.date_from} onChange={(e) => applyFilter('date_from', e.target.value)} /></div>
                        <div><label className="mb-1 block text-xs font-semibold uppercase text-slate-500">To</label><input className="ta-input" type="date" value={filters.date_to} onChange={(e) => applyFilter('date_to', e.target.value)} /></div>
                        <div className="md:col-span-2 flex items-end gap-2">
                            <button className="ta-btn-primary disabled:opacity-50" disabled={!canExport} onClick={() => exportReport('appointments')}>Export Appointments</button>
                            <button className="rounded-xl border border-slate-200 px-4 py-2 text-sm disabled:opacity-50" disabled={!canExport} onClick={() => exportReport('customers')}>Customers CSV</button>
                            <button className="rounded-xl border border-slate-200 px-4 py-2 text-sm disabled:opacity-50" disabled={!canExport} onClick={() => exportReport('inventory')}>Inventory CSV</button>
                            <button className="rounded-xl border border-slate-200 px-4 py-2 text-sm disabled:opacity-50" disabled={!canExport} onClick={() => exportReport('loyalty')}>Loyalty CSV</button>
                            <button className="rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm text-indigo-700 disabled:opacity-50" disabled={!canExport} onClick={exportPdf}>Summary PDF</button>
                        </div>
                    </div>
                </section>

                <section className="grid gap-4 md:grid-cols-3 lg:grid-cols-7">
                    {Object.entries(overview).map(([key, value]) => (
                        <div key={key} className="ta-card p-4">
                            <p className="text-xs uppercase text-slate-500">{key.replaceAll('_', ' ')}</p>
                            <p className="mt-1 text-xl font-semibold text-slate-800">{key.includes('revenue') ? toMoney(value) : value}</p>
                        </div>
                    ))}
                </section>

                <section className="grid gap-6 lg:grid-cols-2">
                    <div className="ta-card overflow-hidden">
                        <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Appointment Status Chart</h3></div>
                        <div className="p-5">
                            <HorizontalBarChart rows={statusRows} labelKey="status" valueKey="total" colorClass="bg-indigo-500" />
                        </div>
                    </div>

                    <div className="ta-card overflow-hidden">
                        <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Daily Revenue Trend</h3></div>
                        <div className="p-5">
                            <RevenueTrendChart data={dailyRevenue} />
                        </div>
                    </div>
                </section>

                <section className="grid gap-6 lg:grid-cols-2">
                    <div className="ta-card overflow-hidden">
                        <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Top Services (Chart + Table)</h3></div>
                        <div className="space-y-4 p-5">
                            <HorizontalBarChart rows={servicePerformance} labelKey="service_name" valueKey="total" colorClass="bg-emerald-500" />
                            <div className="overflow-x-auto">
                                <table className="min-w-full text-sm">
                                    <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Service</th><th className="px-5 py-3">Appointments</th><th className="px-5 py-3">Revenue</th></tr></thead>
                                    <tbody>{servicePerformance.map((row, idx) => <tr key={`${row.service_name}-${idx}`} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-700">{row.service_name}</td><td className="px-5 py-3 text-slate-600">{row.total}</td><td className="px-5 py-3 font-semibold text-slate-700">{toMoney(row.revenue)}</td></tr>)}</tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div className="ta-card overflow-hidden">
                        <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Top Staff (Chart + Table)</h3></div>
                        <div className="space-y-4 p-5">
                            <HorizontalBarChart rows={staffPerformance} labelKey="staff_name" valueKey="total" colorClass="bg-amber-500" />
                            <div className="overflow-x-auto">
                                <table className="min-w-full text-sm">
                                    <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Staff</th><th className="px-5 py-3">Appointments</th></tr></thead>
                                    <tbody>{staffPerformance.map((row, idx) => <tr key={`${row.staff_name}-${idx}`} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-700">{row.staff_name}</td><td className="px-5 py-3 text-slate-600">{row.total}</td></tr>)}</tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>

                <section className="grid gap-6 lg:grid-cols-2">
                    <div className="ta-card overflow-hidden">
                        <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Average Waiting Time by Staff (Minutes)</h3></div>
                        <div className="space-y-4 p-5">
                            <HorizontalBarChart rows={waitingTimeByStaff} labelKey="staff_name" valueKey="avg_waiting_minutes" colorClass="bg-rose-500" />
                            <div className="overflow-x-auto">
                                <table className="min-w-full text-sm">
                                    <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Staff</th><th className="px-5 py-3">Avg Wait (min)</th></tr></thead>
                                    <tbody>{waitingTimeByStaff.map((row, idx) => <tr key={`${row.staff_name}-${idx}`} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-700">{row.staff_name}</td><td className="px-5 py-3 text-slate-600">{row.avg_waiting_minutes}</td></tr>)}</tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div className="ta-card overflow-hidden">
                        <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Late Minutes by Staff</h3></div>
                        <div className="space-y-4 p-5">
                            <HorizontalBarChart rows={lateMinutesByStaff} labelKey="staff_name" valueKey="late_minutes" colorClass="bg-cyan-500" />
                            <div className="overflow-x-auto">
                                <table className="min-w-full text-sm">
                                    <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Staff</th><th className="px-5 py-3">Late Minutes</th></tr></thead>
                                    <tbody>{lateMinutesByStaff.map((row, idx) => <tr key={`${row.staff_name}-${idx}`} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-700">{row.staff_name}</td><td className="px-5 py-3 text-slate-600">{row.late_minutes}</td></tr>)}</tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}




