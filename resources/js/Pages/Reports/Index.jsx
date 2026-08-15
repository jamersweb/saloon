import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

const toMoney = (value, currencyCode = 'AED') => new Intl.NumberFormat(undefined, { style: 'currency', currency: currencyCode }).format(Number(value || 0));
const toPercent = (value) => `${Number(value || 0).toFixed(2)}%`;
const toNumber = (value) => Number(value || 0);
const sumRows = (rows, key) => rows.reduce((total, row) => total + toNumber(row[key]), 0);
const avgRows = (rows, key) => (rows.length > 0 ? sumRows(rows, key) / rows.length : 0);
const isMoneyMetric = (key) => key.includes('revenue') || key.includes('payment');

function ReportSection({ title, subtitle, children }) {
    return (
        <section className="ta-card overflow-hidden">
            <div className="border-b border-slate-200 bg-slate-900 px-5 py-4 text-white">
                <h3 className="text-base font-bold">{title}</h3>
                {subtitle && <p className="mt-1 text-xs font-medium text-slate-300">{subtitle}</p>}
            </div>
            {children}
        </section>
    );
}

function ReportTable({ columns, rows, footer, emptyMessage }) {
    return (
        <div className="overflow-x-auto">
            <table className="min-w-full border-collapse text-sm">
                <thead className="bg-slate-100 text-left text-xs font-bold uppercase tracking-wide text-slate-700">
                    <tr>
                        {columns.map((column) => (
                            <th key={column.key} className={`border-b border-slate-300 px-4 py-3 ${column.className || ''}`}>
                                {column.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {rows.map((row, idx) => (
                        <tr key={row.id || `${columns[0]?.key}-${idx}`} className={idx % 2 === 0 ? 'bg-white' : 'bg-slate-50/60'}>
                            {columns.map((column) => (
                                <td key={column.key} className={`px-4 py-3 text-slate-700 ${column.cellClassName || ''}`}>
                                    {column.render ? column.render(row, idx) : row[column.key]}
                                </td>
                            ))}
                        </tr>
                    ))}
                    {rows.length === 0 && (
                        <tr>
                            <td colSpan={columns.length} className="px-4 py-8 text-center text-sm font-medium text-slate-500">
                                {emptyMessage || 'No report rows found for this date range.'}
                            </td>
                        </tr>
                    )}
                </tbody>
                {footer && (
                    <tfoot>
                        <tr className="border-t-2 border-slate-400 bg-amber-50 text-sm font-bold text-slate-900">
                            {columns.map((column) => (
                                <td key={column.key} className={`px-4 py-3 ${column.cellClassName || ''}`}>
                                    {footer[column.key] ?? ''}
                                </td>
                            ))}
                        </tr>
                    </tfoot>
                )}
            </table>
        </div>
    );
}

function HorizontalBarChart({ rows, labelKey, valueKey, colorClass, valueFormatter = (value) => value }) {
    const max = Math.max(...rows.map((row) => Number(row[valueKey] || 0)), 1);

    return (
        <div className="space-y-3">
            {rows.map((row, idx) => {
                const value = Number(row[valueKey] || 0);
                const width = Math.max(6, Math.round((value / max) * 100));
                return (
                    <div key={`${row[labelKey]}-${idx}`}>
                        <div className="mb-1 flex items-center justify-between text-xs font-semibold text-slate-600">
                            <span className="truncate pr-3">{row[labelKey]}</span>
                            <span className="text-slate-800">{valueFormatter(value)}</span>
                        </div>
                        <div className="h-2.5 overflow-hidden rounded-full bg-slate-100">
                            <div className={`h-2.5 rounded-full ${colorClass}`} style={{ width: `${width}%` }} />
                        </div>
                    </div>
                );
            })}
            {rows.length === 0 && <p className="py-6 text-center text-sm font-medium text-slate-500">No chart data found for this date range.</p>}
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
                <line x1={pad} y1={height - pad} x2={width - pad} y2={height - pad} stroke="#cbd5e1" strokeWidth="1" />
                <line x1={pad} y1={pad} x2={pad} y2={height - pad} stroke="#cbd5e1" strokeWidth="1" />
                <polyline points={points} fill="none" stroke="#4f46e5" strokeWidth="3" strokeLinejoin="round" strokeLinecap="round" />
                {values.map((value, index) => {
                    const x = pad + (index * (width - (pad * 2))) / Math.max(values.length - 1, 1);
                    const y = height - pad - ((value / max) * (height - (pad * 2)));
                    return <circle key={index} cx={x} cy={y} r="3.5" fill="#312e81" />;
                })}
            </svg>
            <div className="mt-3 grid grid-cols-2 gap-2 text-xs text-slate-500 md:grid-cols-4">
                {data.slice(-4).map((row) => (
                    <div key={row.date} className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                        <p className="font-semibold">{row.date}</p>
                        <p className="font-bold text-slate-800">{toMoney(row.revenue, currencyCode)}</p>
                    </div>
                ))}
            </div>
        </div>
    );
}

function ExportButton({ children, disabled, onClick, variant = 'default' }) {
    const variants = {
        default: 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50',
        primary: 'border-indigo-600 bg-indigo-600 text-white hover:bg-indigo-700',
        pdf: 'border-emerald-200 bg-emerald-50 text-emerald-800 hover:bg-emerald-100',
    };

    return (
        <button className={`w-full rounded-xl border px-4 py-2 text-sm font-bold disabled:opacity-50 ${variants[variant]}`} disabled={disabled} onClick={onClick}>
            {children}
        </button>
    );
}

export default function ReportsIndex({ filters, overview, statusBreakdown, servicePerformance, staffPerformance, staffServiceSales = [], staffServiceTotals = [], dailyRevenue, waitingTimeByStaff, lateMinutesByStaff, clientRevenue = [], rentalAnalytics = { summary: {}, partners: [] }, marketingSpend = [], currencyCode = 'AED' }) {
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
    const statusTotal = sumRows(statusRows, 'total');
    const serviceTotals = { total: sumRows(servicePerformance, 'total'), revenue: sumRows(servicePerformance, 'revenue') };
    const staffPerformanceTotals = { total: sumRows(staffPerformance, 'total'), revenue: sumRows(staffPerformance, 'revenue') };
    const staffServicesGrandTotal = {
        service_count: sumRows(staffServiceTotals, 'service_count'),
        quantity: sumRows(staffServiceTotals, 'quantity'),
        subtotal: sumRows(staffServiceTotals, 'subtotal'),
        discount_amount: sumRows(staffServiceTotals, 'discount_amount'),
        tax: sumRows(staffServiceTotals, 'tax'),
        total: sumRows(staffServiceTotals, 'total'),
    };
    staffServicesGrandTotal.avg_sale_per_line = staffServicesGrandTotal.service_count > 0 ? staffServicesGrandTotal.total / staffServicesGrandTotal.service_count : 0;
    const waitingAverage = avgRows(waitingTimeByStaff, 'avg_waiting_minutes');
    const lateMinutesTotal = sumRows(lateMinutesByStaff, 'late_minutes');
    const clientTotals = {
        invoice_count: sumRows(clientRevenue, 'invoice_count'),
        revenue_total: sumRows(clientRevenue, 'revenue_total'),
        amount_paid: sumRows(clientRevenue, 'amount_paid'),
        outstanding_balance: sumRows(clientRevenue, 'outstanding_balance'),
    };
    const rentalPartnerTotals = {
        settlement_count: sumRows(rentalAnalytics.partners || [], 'settlement_count'),
        fixed_rent_total: sumRows(rentalAnalytics.partners || [], 'fixed_rent_total'),
        commission_total: sumRows(rentalAnalytics.partners || [], 'commission_total'),
        total_income: sumRows(rentalAnalytics.partners || [], 'total_income'),
    };
    const marketingTotals = {
        expense_count: sumRows(marketingSpend, 'expense_count'),
        spend_total: sumRows(marketingSpend, 'spend_total'),
    };

    const moneyCell = 'text-right font-semibold tabular-nums';
    const numberCell = 'text-right tabular-nums';

    return (
        <AuthenticatedLayout header="Reports & Exports">
            <Head title="Reports" />

            <div className="space-y-6">
                <section className="ta-card p-5">
                    <div className="mb-4 border-b border-slate-200 pb-3">
                        <h2 className="text-lg font-bold text-slate-900">Report Filters</h2>
                        <p className="mt-1 text-sm font-medium text-slate-500">Select a date range and export the report you need.</p>
                    </div>
                    <form onSubmit={applyFilters} className="grid gap-3 lg:grid-cols-6">
                        <div className="min-w-0">
                            <label className="ta-field-label">From</label>
                            <input className="ta-input w-full min-w-0" type="date" value={filterForm.date_from} onChange={(e) => updateFilter('date_from', e.target.value)} />
                        </div>
                        <div className="min-w-0">
                            <label className="ta-field-label">To</label>
                            <input className="ta-input w-full min-w-0" type="date" value={filterForm.date_to} onChange={(e) => updateFilter('date_to', e.target.value)} />
                        </div>
                        <div className="min-w-0">
                            <label className="ta-field-label">Customer</label>
                            <input className="ta-input w-full min-w-0" value={filterForm.customer_name} onChange={(e) => updateFilter('customer_name', e.target.value)} placeholder="Customer name" />
                        </div>
                        <div className="min-w-0">
                            <label className="ta-field-label">Invoice No.</label>
                            <input className="ta-input w-full min-w-0" value={filterForm.invoice_number} onChange={(e) => updateFilter('invoice_number', e.target.value)} placeholder="Invoice number" />
                        </div>
                        <div className="grid gap-2 sm:grid-cols-2 lg:col-span-2">
                            <button type="submit" className="ta-btn-primary w-full font-bold">Apply Filters</button>
                            <button type="button" className="w-full rounded-xl border border-slate-300 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50" onClick={resetFilters}>Reset</button>
                        </div>
                    </form>
                    <div className="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
                        <ExportButton variant="primary" disabled={!canExport} onClick={() => exportReport('appointments')}>Appointments CSV</ExportButton>
                        <ExportButton disabled={!canExport} onClick={() => exportReport('customers')}>Customers CSV</ExportButton>
                        <ExportButton disabled={!canExport} onClick={() => exportReport('inventory')}>Inventory CSV</ExportButton>
                        <ExportButton disabled={!canExport} onClick={() => exportReport('loyalty')}>Loyalty CSV</ExportButton>
                        <ExportButton disabled={!canExport} onClick={() => exportReport('client_revenue')}>Client Revenue CSV</ExportButton>
                        <ExportButton disabled={!canExport} onClick={() => exportReport('marketing_campaigns')}>Campaign Spend CSV</ExportButton>
                        <ExportButton disabled={!canExport} onClick={() => exportReport('rentals')}>Rental CSV</ExportButton>
                        <ExportButton variant="pdf" disabled={!canExport} onClick={() => exportPdf('staff_services')}>Staff Services PDF</ExportButton>
                        <ExportButton variant="pdf" disabled={!canExport} onClick={() => exportPdf('summary')}>Summary PDF</ExportButton>
                        <ExportButton variant="pdf" disabled={!canExport} onClick={() => exportPdf('service')}>Service Report PDF</ExportButton>
                    </div>
                </section>

                <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5">
                    {Object.entries(overview).map(([key, value]) => {
                        const isMoney = isMoneyMetric(key);

                        return (
                            <div key={key} className="min-w-0 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                                <p className="text-xs font-bold uppercase tracking-wide text-slate-500">{key.replaceAll('_', ' ')}</p>
                                <p className={`mt-2 font-black tabular-nums text-slate-900 ${isMoney ? 'whitespace-nowrap text-xl' : 'text-2xl'}`}>
                                    {isMoney ? toMoney(value, currencyCode) : value}
                                </p>
                            </div>
                        );
                    })}
                </section>

                <section className="grid gap-6 lg:grid-cols-2">
                    <ReportSection title="Appointment Status" subtitle={`${statusTotal} total appointments in this period`}>
                        <div className="space-y-5 p-5">
                            <HorizontalBarChart rows={statusRows} labelKey="status" valueKey="total" colorClass="bg-indigo-500" />
                            <ReportTable
                                columns={[
                                    { key: 'status', label: 'Status' },
                                    { key: 'total', label: 'Total', cellClassName: numberCell },
                                ]}
                                rows={statusRows}
                                footer={{ status: 'Total', total: statusTotal }}
                            />
                        </div>
                    </ReportSection>

                    <ReportSection title="Daily Revenue Trend" subtitle={`${toMoney(sumRows(dailyRevenue, 'revenue'), currencyCode)} total daily revenue`}>
                        <div className="space-y-5 p-5">
                            <RevenueTrendChart data={dailyRevenue} currencyCode={currencyCode} />
                            <ReportTable
                                columns={[
                                    { key: 'date', label: 'Date' },
                                    { key: 'revenue', label: 'Revenue', render: (row) => toMoney(row.revenue, currencyCode), cellClassName: moneyCell },
                                ]}
                                rows={dailyRevenue}
                                footer={{ date: 'Total', revenue: toMoney(sumRows(dailyRevenue, 'revenue'), currencyCode) }}
                            />
                        </div>
                    </ReportSection>
                </section>

                <section className="grid gap-6 lg:grid-cols-2">
                    <ReportSection title="Top Services" subtitle="Services sorted by completed sales lines">
                        <div className="space-y-5 p-5">
                            <HorizontalBarChart rows={servicePerformance} labelKey="service_name" valueKey="total" colorClass="bg-emerald-500" />
                            <ReportTable
                                columns={[
                                    { key: 'service_name', label: 'Service' },
                                    { key: 'total', label: 'Completed Lines', cellClassName: numberCell },
                                    { key: 'revenue', label: 'Revenue', render: (row) => toMoney(row.revenue, currencyCode), cellClassName: moneyCell },
                                ]}
                                rows={servicePerformance}
                                footer={{ service_name: 'Total', total: serviceTotals.total, revenue: toMoney(serviceTotals.revenue, currencyCode) }}
                            />
                        </div>
                    </ReportSection>

                    <ReportSection title="Top Staff" subtitle="Staff sales performance for the selected period">
                        <div className="space-y-5 p-5">
                            <HorizontalBarChart rows={staffPerformance} labelKey="staff_name" valueKey="revenue" colorClass="bg-amber-500" valueFormatter={(value) => toMoney(value, currencyCode)} />
                            <ReportTable
                                columns={[
                                    { key: 'staff_name', label: 'Staff' },
                                    { key: 'total', label: 'Completed Lines', cellClassName: numberCell },
                                    { key: 'revenue', label: 'Sales', render: (row) => toMoney(row.revenue, currencyCode), cellClassName: moneyCell },
                                ]}
                                rows={staffPerformance}
                                footer={{ staff_name: 'Total', total: staffPerformanceTotals.total, revenue: toMoney(staffPerformanceTotals.revenue, currencyCode) }}
                            />
                        </div>
                    </ReportSection>
                </section>

                <ReportSection title="Staff Service Sales" subtitle="Summary by staff first, then detailed services by staff">
                    <div className="space-y-6 p-5">
                        <ReportTable
                            columns={[
                                { key: 'staff_name', label: 'Staff Total' },
                                { key: 'service_count', label: 'Completed Lines', cellClassName: numberCell },
                                { key: 'quantity', label: 'Qty', cellClassName: numberCell },
                                { key: 'total', label: 'Sales', render: (row) => toMoney(row.total, currencyCode), cellClassName: moneyCell },
                                { key: 'avg_sale_per_line', label: 'Avg Sale / Line', render: (row) => toMoney(row.avg_sale_per_line, currencyCode), cellClassName: moneyCell },
                                { key: 'sales_percent', label: '% of Month', render: (row) => toPercent(row.sales_percent), cellClassName: numberCell },
                            ]}
                            rows={staffServiceTotals}
                            footer={{
                                staff_name: 'Grand Total',
                                service_count: staffServicesGrandTotal.service_count,
                                quantity: staffServicesGrandTotal.quantity,
                                total: toMoney(staffServicesGrandTotal.total, currencyCode),
                                avg_sale_per_line: toMoney(staffServicesGrandTotal.avg_sale_per_line, currencyCode),
                                sales_percent: staffServiceTotals.length > 0 ? '100.00%' : '0.00%',
                            }}
                            emptyMessage="No staff totals found for this date range."
                        />

                        <ReportTable
                            columns={[
                                { key: 'staff_name', label: 'Staff' },
                                { key: 'service_name', label: 'Service' },
                                { key: 'service_count', label: 'Completed Lines', cellClassName: numberCell },
                                { key: 'quantity', label: 'Qty', cellClassName: numberCell },
                                { key: 'subtotal', label: 'Subtotal', render: (row) => toMoney(row.subtotal, currencyCode), cellClassName: moneyCell },
                                { key: 'discount_amount', label: 'Discount', render: (row) => toMoney(row.discount_amount, currencyCode), cellClassName: moneyCell },
                                { key: 'tax', label: 'VAT', render: (row) => toMoney(row.tax, currencyCode), cellClassName: moneyCell },
                                { key: 'total', label: 'Sales', render: (row) => toMoney(row.total, currencyCode), cellClassName: moneyCell },
                                { key: 'avg_sale_per_line', label: 'Avg Sale / Line', render: (row) => toMoney(row.avg_sale_per_line, currencyCode), cellClassName: moneyCell },
                                { key: 'sales_percent', label: '% of Month', render: (row) => toPercent(row.sales_percent), cellClassName: numberCell },
                            ]}
                            rows={staffServiceSales}
                            footer={{
                                staff_name: 'Grand Total',
                                service_count: staffServicesGrandTotal.service_count,
                                quantity: staffServicesGrandTotal.quantity,
                                subtotal: toMoney(staffServicesGrandTotal.subtotal, currencyCode),
                                discount_amount: toMoney(staffServicesGrandTotal.discount_amount, currencyCode),
                                tax: toMoney(staffServicesGrandTotal.tax, currencyCode),
                                total: toMoney(staffServicesGrandTotal.total, currencyCode),
                                avg_sale_per_line: toMoney(staffServicesGrandTotal.avg_sale_per_line, currencyCode),
                                sales_percent: staffServiceTotals.length > 0 ? '100.00%' : '0.00%',
                            }}
                            emptyMessage="No completed staff service sales found for this date range."
                        />
                    </div>
                </ReportSection>

                <section className="grid gap-6 lg:grid-cols-2">
                    <ReportSection title="Average Waiting Time by Staff" subtitle={`${waitingAverage.toFixed(2)} minutes average across listed staff`}>
                        <div className="space-y-5 p-5">
                            <HorizontalBarChart rows={waitingTimeByStaff} labelKey="staff_name" valueKey="avg_waiting_minutes" colorClass="bg-rose-500" />
                            <ReportTable
                                columns={[
                                    { key: 'staff_name', label: 'Staff' },
                                    { key: 'avg_waiting_minutes', label: 'Avg Wait (min)', cellClassName: numberCell },
                                ]}
                                rows={waitingTimeByStaff}
                                footer={{ staff_name: 'Average', avg_waiting_minutes: waitingAverage.toFixed(2) }}
                            />
                        </div>
                    </ReportSection>

                    <ReportSection title="Late Minutes by Staff" subtitle={`${lateMinutesTotal} total late minutes`}>
                        <div className="space-y-5 p-5">
                            <HorizontalBarChart rows={lateMinutesByStaff} labelKey="staff_name" valueKey="late_minutes" colorClass="bg-cyan-500" />
                            <ReportTable
                                columns={[
                                    { key: 'staff_name', label: 'Staff' },
                                    { key: 'late_minutes', label: 'Late Minutes', cellClassName: numberCell },
                                ]}
                                rows={lateMinutesByStaff}
                                footer={{ staff_name: 'Total', late_minutes: lateMinutesTotal }}
                            />
                        </div>
                    </ReportSection>
                </section>

                <section className="grid gap-6 lg:grid-cols-2">
                    <ReportSection title="Client Revenue" subtitle={`${toMoney(clientTotals.revenue_total, currencyCode)} total client revenue`}>
                        <div className="p-5">
                            <ReportTable
                                columns={[
                                    { key: 'customer_name', label: 'Client' },
                                    { key: 'invoice_count', label: 'Invoices', cellClassName: numberCell },
                                    { key: 'revenue_total', label: 'Revenue', render: (row) => toMoney(row.revenue_total, currencyCode), cellClassName: moneyCell },
                                    { key: 'amount_paid', label: 'Paid', render: (row) => toMoney(row.amount_paid, currencyCode), cellClassName: moneyCell },
                                    { key: 'outstanding_balance', label: 'Outstanding', render: (row) => toMoney(row.outstanding_balance, currencyCode), cellClassName: moneyCell },
                                    { key: 'last_invoice_date', label: 'Last Invoice', render: (row) => row.last_invoice_date || '-' },
                                ]}
                                rows={clientRevenue}
                                footer={{
                                    customer_name: 'Total',
                                    invoice_count: clientTotals.invoice_count,
                                    revenue_total: toMoney(clientTotals.revenue_total, currencyCode),
                                    amount_paid: toMoney(clientTotals.amount_paid, currencyCode),
                                    outstanding_balance: toMoney(clientTotals.outstanding_balance, currencyCode),
                                }}
                            />
                        </div>
                    </ReportSection>

                    <ReportSection title="Rental Analytics" subtitle={`${toMoney(rentalAnalytics.summary?.total_income || 0, currencyCode)} total rental income`}>
                        <div className="space-y-5 p-5">
                            <div className="grid gap-3 md:grid-cols-4">
                                {[
                                    ['Settlements', rentalAnalytics.summary?.settlement_count || 0],
                                    ['Fixed Rent', toMoney(rentalAnalytics.summary?.fixed_rent_total || 0, currencyCode)],
                                    ['Commission', toMoney(rentalAnalytics.summary?.commission_total || 0, currencyCode)],
                                    ['Total Income', toMoney(rentalAnalytics.summary?.total_income || 0, currencyCode)],
                                ].map(([label, value]) => (
                                    <div key={label} className="rounded-xl border border-slate-200 bg-slate-50 p-3">
                                        <p className="text-xs font-bold uppercase text-slate-500">{label}</p>
                                        <p className="mt-1 text-lg font-black text-slate-900">{value}</p>
                                    </div>
                                ))}
                            </div>
                            <ReportTable
                                columns={[
                                    { key: 'partner_name', label: 'Partner' },
                                    { key: 'agreement_type', label: 'Type' },
                                    { key: 'cost_center_label', label: 'Cost Center' },
                                    { key: 'settlement_count', label: 'Settlements', cellClassName: numberCell },
                                    { key: 'fixed_rent_total', label: 'Fixed Rent', render: (row) => toMoney(row.fixed_rent_total, currencyCode), cellClassName: moneyCell },
                                    { key: 'commission_total', label: 'Commission', render: (row) => toMoney(row.commission_total, currencyCode), cellClassName: moneyCell },
                                    { key: 'total_income', label: 'Total', render: (row) => toMoney(row.total_income, currencyCode), cellClassName: moneyCell },
                                ]}
                                rows={rentalAnalytics.partners || []}
                                footer={{
                                    partner_name: 'Total',
                                    settlement_count: rentalPartnerTotals.settlement_count,
                                    fixed_rent_total: toMoney(rentalPartnerTotals.fixed_rent_total, currencyCode),
                                    commission_total: toMoney(rentalPartnerTotals.commission_total, currencyCode),
                                    total_income: toMoney(rentalPartnerTotals.total_income, currencyCode),
                                }}
                            />
                        </div>
                    </ReportSection>
                </section>

                <ReportSection title="Marketing Spend by Campaign" subtitle={`${toMoney(marketingTotals.spend_total, currencyCode)} total campaign spend`}>
                    <div className="p-5">
                        <ReportTable
                            columns={[
                                { key: 'campaign_name', label: 'Campaign' },
                                { key: 'expense_count', label: 'Expenses', cellClassName: numberCell },
                                { key: 'spend_total', label: 'Spend', render: (row) => toMoney(row.spend_total, currencyCode), cellClassName: moneyCell },
                                { key: 'last_expense_date', label: 'Last Expense', render: (row) => row.last_expense_date || '-' },
                            ]}
                            rows={marketingSpend}
                            footer={{
                                campaign_name: 'Total',
                                expense_count: marketingTotals.expense_count,
                                spend_total: toMoney(marketingTotals.spend_total, currencyCode),
                            }}
                        />
                    </div>
                </ReportSection>
            </div>
        </AuthenticatedLayout>
    );
}
