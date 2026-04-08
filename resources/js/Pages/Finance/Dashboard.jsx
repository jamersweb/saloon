import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';

const money = (value, currency = 'AED') =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency, minimumFractionDigits: 2 }).format(Number(value || 0));

export default function FinanceDashboard({ filters, summary, accountsReceivable, accountsPayable, periodic }) {
    const { flash } = usePage().props;

    const applyFilter = (key, value) => {
        router.get(route('finance.index'), { ...filters, [key]: value }, { preserveState: true, replace: true });
    };

    const exportCsv = () => {
        const params = new URLSearchParams({ date_from: filters.date_from, date_to: filters.date_to });
        window.location.href = `${route('finance.export')}?${params.toString()}`;
    };

    return (
        <AuthenticatedLayout header="Finance & accounting">
            <Head title="Finance" />

            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <section className="ta-card p-5">
                    <div className="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                        <div>
                            <h3 className="text-sm font-semibold text-slate-700">Periodic reporting</h3>
                            <p className="text-xs text-slate-500">Income, expenses, tax, and cash movement for the selected range.</p>
                        </div>
                        <div className="flex flex-wrap gap-3">
                            <div>
                                <label className="ta-field-label">From</label>
                                <input type="date" className="ta-input" value={filters.date_from} onChange={(e) => applyFilter('date_from', e.target.value)} />
                            </div>
                            <div>
                                <label className="ta-field-label">To</label>
                                <input type="date" className="ta-input" value={filters.date_to} onChange={(e) => applyFilter('date_to', e.target.value)} />
                            </div>
                            <button type="button" className="ta-btn-primary mt-5 h-10" onClick={exportCsv}>
                                Export CSV
                            </button>
                        </div>
                    </div>
                </section>

                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <div className="ta-card p-4">
                        <p className="text-xs uppercase tracking-wide text-slate-500">Invoiced (finalized)</p>
                        <p className="mt-1 text-2xl font-semibold text-slate-800">{money(summary.invoiced_total)}</p>
                        <p className="mt-1 text-xs text-slate-500">VAT included in total: {money(summary.vat_collected)}</p>
                    </div>
                    <div className="ta-card p-4">
                        <p className="text-xs uppercase tracking-wide text-slate-500">Payments collected</p>
                        <p className="mt-1 text-2xl font-semibold text-emerald-700">{money(summary.payments_collected)}</p>
                    </div>
                    <div className="ta-card p-4">
                        <p className="text-xs uppercase tracking-wide text-slate-500">Expenses (period)</p>
                        <p className="mt-1 text-2xl font-semibold text-amber-700">{money(summary.expense_total)}</p>
                        <p className="mt-1 text-xs text-slate-500">Paid in range: {money(summary.expenses_paid)}</p>
                    </div>
                    <div className="ta-card p-4">
                        <p className="text-xs uppercase tracking-wide text-slate-500">Payroll paid (period end in range)</p>
                        <p className="mt-1 text-2xl font-semibold text-slate-800">{money(summary.payroll_paid_in_range)}</p>
                    </div>
                    <div className="ta-card p-4 md:col-span-2">
                        <p className="text-xs uppercase tracking-wide text-slate-500">Net operating (cash view)</p>
                        <p className="mt-1 text-2xl font-semibold text-indigo-700">{money(summary.net_operating)}</p>
                        <p className="mt-1 text-xs text-slate-500">Payments collected − expenses paid − payroll paid (approximate).</p>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="ta-card p-5">
                        <h3 className="mb-3 text-sm font-semibold text-slate-700">Accounts receivable (open balances)</h3>
                        {accountsReceivable.length === 0 ? (
                            <p className="text-sm text-slate-500">No outstanding invoice balances.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full text-sm">
                                    <thead className="text-left text-xs uppercase text-slate-500">
                                        <tr>
                                            <th className="pb-2">Invoice</th>
                                            <th className="pb-2">Customer</th>
                                            <th className="pb-2 text-right">Balance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {accountsReceivable.map((row) => (
                                            <tr key={row.id} className="border-t border-slate-100">
                                                <td className="py-2">
                                                    <Link href={route('finance.invoices.show', row.id)} className="text-indigo-600 hover:underline">
                                                        {row.invoice_number}
                                                    </Link>
                                                </td>
                                                <td className="py-2 text-slate-600">{row.customer_display_name}</td>
                                                <td className="py-2 text-right font-medium text-slate-800">{money(row.balance)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>

                    <section className="ta-card p-5">
                        <h3 className="mb-3 text-sm font-semibold text-slate-700">Accounts payable</h3>
                        <p className="mb-2 text-xs text-slate-500">Unpaid expenses</p>
                        {accountsPayable.expenses.length === 0 ? (
                            <p className="mb-4 text-sm text-slate-500">No unpaid expenses.</p>
                        ) : (
                            <ul className="mb-4 space-y-1 text-sm">
                                {accountsPayable.expenses.map((row) => (
                                    <li key={row.id} className="flex justify-between border-b border-slate-50 py-1">
                                        <span className="text-slate-600">{row.vendor}</span>
                                        <span className="font-medium">{money(row.amount)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <p className="mb-2 text-xs text-slate-500">Vendor PO commitments (approved / received)</p>
                        {accountsPayable.purchase_orders.length === 0 ? (
                            <p className="text-sm text-slate-500">None listed.</p>
                        ) : (
                            <ul className="space-y-1 text-sm">
                                {accountsPayable.purchase_orders.map((row) => (
                                    <li key={`po-${row.id}`} className="flex justify-between border-b border-slate-50 py-1">
                                        <span className="text-slate-600">
                                            {row.reference} · {row.vendor}
                                        </span>
                                        <span className="font-medium">{money(row.amount)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>

                <section className="ta-card p-5">
                    <h3 className="mb-3 text-sm font-semibold text-slate-700">Rolling 12 months</h3>
                    <div className="grid gap-6 md:grid-cols-2">
                        <div>
                            <p className="mb-2 text-xs font-medium uppercase text-slate-500">Income by month</p>
                            <ul className="space-y-1 text-sm">
                                {periodic.income_by_month.map((row) => (
                                    <li key={row.period} className="flex justify-between">
                                        <span>{row.period}</span>
                                        <span className="font-semibold">{money(row.total)}</span>
                                    </li>
                                ))}
                                {periodic.income_by_month.length === 0 && <li className="text-slate-500">No finalized invoices yet.</li>}
                            </ul>
                        </div>
                        <div>
                            <p className="mb-2 text-xs font-medium uppercase text-slate-500">Expenses by month</p>
                            <ul className="space-y-1 text-sm">
                                {periodic.expenses_by_month.map((row) => (
                                    <li key={row.period} className="flex justify-between">
                                        <span>{row.period}</span>
                                        <span className="font-semibold">{money(row.total)}</span>
                                    </li>
                                ))}
                                {periodic.expenses_by_month.length === 0 && <li className="text-slate-500">No expenses yet.</li>}
                            </ul>
                        </div>
                    </div>
                </section>

                <div className="flex flex-wrap gap-3">
                    <Link href={route('finance.invoices.index')} className="ta-btn-primary">
                        Tax invoices
                    </Link>
                    <Link href={route('finance.expenses.index')} className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700">
                        Expenses
                    </Link>
                    <Link href={route('finance.payroll.index')} className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700">
                        Payroll
                    </Link>
                    <Link href={route('finance.settings.edit')} className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700">
                        Finance settings
                    </Link>
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
