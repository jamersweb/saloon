import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const toMoney = (value, currencyCode = 'AED') => new Intl.NumberFormat(undefined, { style: 'currency', currency: currencyCode }).format(Number(value || 0));
const isMoneyMetric = (key) => key.includes('revenue') || key.includes('payment');

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

function RevenueTrendChart({ data, currencyCode }) {
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
                        <p className="font-semibold text-slate-700">{toMoney(row.revenue, currencyCode)}</p>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function ReportsIndex({ filters, overview, statusBreakdown, servicePerformance, staffPerformance, dailyRevenue, waitingTimeByStaff, lateMinutesByStaff, clientRevenue = [], rentalAnalytics = { summary: {}, partners: [] }, marketingSpend = [], currencyCode = 'AED' }) {
    const { auth } = usePage().props;
    const canExport = Boolean(auth?.permissions?.can_export_reports);
    const [filterForm, setFilterForm] = useState({
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
        customer_name: filters.customer_name || '',
        invoice_number: filters.invoice_number || '',
    });

    const updateFilter = (key, value) => {
        setFilterForm((current) => ({ ...current, [key]: value }));
    };

    const applyFilters = (event) => {
        event?.preventDefault();
        router.get(route('reports.index'), filterForm, { preserveState: true, replace: true });
    };

    const resetFilters = () => {
        router.get(route('reports.index'), {}, { preserveState: false, replace: true });
    };

    const currentParams = (extra = {}) => {
        const params = new URLSearchParams();
        Object.entries({ ...filters, ...extra }).forEach(([key, value]) => {
            if (value !== undefined && value !== null && String(value) !== '') {
                params.set(key, value);
            }
        });

        return params;
    };

    const exportReport = (type) => {
        if (!canExport) {
            return;
        }

        const params = currentParams({ type });
        window.location.href = `${route('reports.export')}?${params.toString()}`;
    };

    const exportPdf = (report = 'summary') => {
        if (!canExport) {
            return;
        }

        const params = currentParams({ report });
        window.location.href = `${route('reports.export.pdf')}?${params.toString()}`;
    };

    const statusRows = Object.entries(statusBreakdown).map(([status, total]) => ({ status: status.replaceAll('_', ' '), total }));

    return (
        <AuthenticatedLayout header="Reports & Exports">
            <Head title="Reports" />

            <div className="space-y-6">
                <section className="ta-card p-5">
                    <form onSubmit={applyFilters} className="grid gap-3 lg:grid-cols-6">
                        <div className="min-w-0">
                            <label className="mb-1 block text-xs font-semibold uppercase text-slate-500">From</label>
                            <input className="ta-input w-full min-w-0" type="date" value={filterForm.date_from} onChange={(e) => updateFilter('date_from', e.target.value)} />
                        </div>
                        <div className="min-w-0">
                            <label className="mb-1 block text-xs font-semibold uppercase text-slate-500">To</label>
                            <input className="ta-input w-full min-w-0" type="date" value={filterForm.date_to} onChange={(e) => updateFilter('date_to', e.target.value)} />
                        </div>
                        <div className="min-w-0">
                            <label className="mb-1 block text-xs font-semibold uppercase text-slate-500">Customer</label>
                            <input className="ta-input w-full min-w-0" value={filterForm.customer_name} onChange={(e) => updateFilter('customer_name', e.target.value)} placeholder="Customer name" />
                        </div>
                        <div className="min-w-0">
                            <label className="mb-1 block text-xs font-semibold uppercase text-slate-500">Invoice No.</label>
                            <input className="ta-input w-full min-w-0" value={filterForm.invoice_number} onChange={(e) => updateFilter('invoice_number', e.target.value)} placeholder="Invoice number" />
                        </div>
                        <div className="grid gap-2 sm:grid-cols-2 lg:col-span-2">
                            <button type="submit" className="ta-btn-primary w-full">Apply Filters</button>
                            <button type="button" className="w-full rounded-xl border border-slate-200 px-4 py-2 text-sm text-slate-700" onClick={resetFilters}>Reset</button>
                        </div>
                    </form>
                    <div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-6">
                        <button className="ta-btn-primary w-full disabled:opacity-50" disabled={!canExport} onClick={() => exportReport('appointments')}>Export Appointments</button>
                        <button className="w-full rounded-xl border border-slate-200 px-4 py-2 text-sm disabled:opacity-50" disabled={!canExport} onClick={() => exportReport('customers')}>Customers CSV</button>
                        <button className="w-full rounded-xl border border-slate-200 px-4 py-2 text-sm disabled:opacity-50" disabled={!canExport} onClick={() => exportReport('inventory')}>Inventory CSV</button>
                        <button className="w-full rounded-xl border border-slate-200 px-4 py-2 text-sm disabled:opacity-50" disabled={!canExport} onClick={() => exportReport('loyalty')}>Loyalty CSV</button>
                        <button className="w-full rounded-xl border border-slate-200 px-4 py-2 text-sm disabled:opacity-50" disabled={!canExport} onClick={() => exportReport('client_revenue')}>Client Revenue CSV</button>
                        <button className="w-full rounded-xl border border-slate-200 px-4 py-2 text-sm disabled:opacity-50" disabled={!canExport} onClick={() => exportReport('marketing_campaigns')}>Campaign Spend CSV</button>
                        <button className="w-full rounded-xl border border-slate-200 px-4 py-2 text-sm disabled:opacity-50" disabled={!canExport} onClick={() => exportReport('rentals')}>Rental CSV</button>
                        <button className="w-full rounded-xl border border-indigo-200 bg-indigo-50 px-4 py-2 text-sm text-indigo-700 disabled:opacity-50" disabled={!canExport} onClick={() => exportPdf('summary')}>Summary PDF</button>
                        <button className="w-full rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-700 disabled:opacity-50" disabled={!canExport} onClick={() => exportPdf('service')}>Service Report PDF</button>
                    </div>
                </section>

                <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                    {Object.entries(overview).map(([key, value]) => (
                        <div key={key} className="ta-card p-4">
                            <p className="text-xs uppercase text-slate-500">{key.replaceAll('_', ' ')}</p>
                            <p className="mt-1 text-xl font-semibold text-slate-800">{isMoneyMetric(key) ? toMoney(value, currencyCode) : value}</p>
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
                            <RevenueTrendChart data={dailyRevenue} currencyCode={currencyCode} />
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
                                    <tbody>{servicePerformance.map((row, idx) => <tr key={`${row.service_name}-${idx}`} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-700">{row.service_name}</td><td className="px-5 py-3 text-slate-600">{row.total}</td><td className="px-5 py-3 font-semibold text-slate-700">{toMoney(row.revenue, currencyCode)}</td></tr>)}</tbody>
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

                <section className="grid gap-6 lg:grid-cols-2">
                    <div className="ta-card overflow-hidden">
                        <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Client Revenue</h3></div>
                        <div className="overflow-x-auto p-5">
                            <table className="min-w-full text-sm">
                                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-2">Client</th><th className="px-4 py-2">Invoices</th><th className="px-4 py-2">Revenue</th><th className="px-4 py-2">Paid</th><th className="px-4 py-2">Outstanding</th><th className="px-4 py-2">Last Invoice</th></tr></thead>
                                <tbody>{clientRevenue.map((row) => <tr key={row.customer_name} className="border-t border-slate-100"><td className="px-4 py-2 text-slate-700">{row.customer_name}</td><td className="px-4 py-2 text-slate-600">{row.invoice_count}</td><td className="px-4 py-2 font-semibold text-slate-700">{toMoney(row.revenue_total, currencyCode)}</td><td className="px-4 py-2 text-emerald-700">{toMoney(row.amount_paid, currencyCode)}</td><td className="px-4 py-2 text-amber-700">{toMoney(row.outstanding_balance, currencyCode)}</td><td className="px-4 py-2 text-slate-600">{row.last_invoice_date || '-'}</td></tr>)}</tbody>
                            </table>
                        </div>
                    </div>

                    <div className="ta-card overflow-hidden">
                        <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Rental Analytics</h3></div>
                        <div className="space-y-4 p-5">
                            <div className="grid gap-3 md:grid-cols-4">
                                <div className="rounded-xl border border-slate-200 p-3"><p className="text-xs uppercase text-slate-500">Settlements</p><p className="mt-1 text-lg font-semibold text-slate-800">{rentalAnalytics.summary?.settlement_count || 0}</p></div>
                                <div className="rounded-xl border border-slate-200 p-3"><p className="text-xs uppercase text-slate-500">Fixed Rent</p><p className="mt-1 text-lg font-semibold text-slate-800">{toMoney(rentalAnalytics.summary?.fixed_rent_total || 0, currencyCode)}</p></div>
                                <div className="rounded-xl border border-slate-200 p-3"><p className="text-xs uppercase text-slate-500">Commission</p><p className="mt-1 text-lg font-semibold text-slate-800">{toMoney(rentalAnalytics.summary?.commission_total || 0, currencyCode)}</p></div>
                                <div className="rounded-xl border border-slate-200 p-3"><p className="text-xs uppercase text-slate-500">Total Income</p><p className="mt-1 text-lg font-semibold text-slate-800">{toMoney(rentalAnalytics.summary?.total_income || 0, currencyCode)}</p></div>
                            </div>
                            <div className="overflow-x-auto">
                                <table className="min-w-full text-sm">
                                    <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-2">Partner</th><th className="px-4 py-2">Type</th><th className="px-4 py-2">Cost Center</th><th className="px-4 py-2">Settlements</th><th className="px-4 py-2">Fixed Rent</th><th className="px-4 py-2">Commission</th><th className="px-4 py-2">Total</th></tr></thead>
                                    <tbody>{(rentalAnalytics.partners || []).map((row) => <tr key={`${row.partner_name}-${row.cost_center}`} className="border-t border-slate-100"><td className="px-4 py-2 text-slate-700">{row.partner_name}</td><td className="px-4 py-2 text-slate-600">{row.agreement_type}</td><td className="px-4 py-2 text-slate-600">{row.cost_center_label}</td><td className="px-4 py-2 text-slate-600">{row.settlement_count}</td><td className="px-4 py-2 text-slate-600">{toMoney(row.fixed_rent_total, currencyCode)}</td><td className="px-4 py-2 text-slate-600">{toMoney(row.commission_total, currencyCode)}</td><td className="px-4 py-2 font-semibold text-slate-700">{toMoney(row.total_income, currencyCode)}</td></tr>)}</tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Marketing Spend by Campaign</h3></div>
                    <div className="overflow-x-auto p-5">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-2">Campaign</th><th className="px-4 py-2">Expenses</th><th className="px-4 py-2">Spend</th><th className="px-4 py-2">Last Expense</th></tr></thead>
                            <tbody>{marketingSpend.map((row) => <tr key={row.campaign_name} className="border-t border-slate-100"><td className="px-4 py-2 text-slate-700">{row.campaign_name}</td><td className="px-4 py-2 text-slate-600">{row.expense_count}</td><td className="px-4 py-2 font-semibold text-slate-700">{toMoney(row.spend_total, currencyCode)}</td><td className="px-4 py-2 text-slate-600">{row.last_expense_date || '-'}</td></tr>)}</tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}




