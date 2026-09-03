import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

const money = (value) =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency: 'AED', minimumFractionDigits: 2 }).format(Number(value || 0));

const statusBadge = (status) => {
    const map = {
        draft: 'bg-slate-200 text-slate-800',
        finalized: 'bg-emerald-100 text-emerald-800',
        void: 'bg-red-100 text-red-800',
    };
    return map[status] || 'bg-slate-100 text-slate-700';
};

export default function FinanceInvoicesIndex({ invoices, filters = {} }) {
    const { flash } = usePage().props;
    const filterForm = useForm({
        q: filters.q || '',
        status: filters.status || '',
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
    });
    const applyFilters = (e) => {
        e.preventDefault();
        filterForm.get(route('finance.invoices.index'), {
            preserveScroll: true,
            preserveState: true,
        });
    };
    const clearFilters = () => {
        filterForm.reset('q', 'status', 'date_from', 'date_to');
        router.get(route('finance.invoices.index'), {}, { preserveScroll: true });
    };

    return (
        <AuthenticatedLayout header="Tax invoices">
            <Head title="Tax invoices" />

            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Link href={route('finance.index')} className="text-sm text-indigo-600 hover:underline">
                        ← Finance overview
                    </Link>
                    <div className="flex flex-wrap gap-2">
                        <Link href={route('finance.invoices.create', { sale_type: 'retail' })} className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-semibold text-emerald-800 hover:bg-emerald-100">
                            New product sale
                        </Link>
                        <Link href={route('finance.invoices.create')} className="ta-btn-primary">
                            New draft invoice
                        </Link>
                    </div>
                </div>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4">
                        <form onSubmit={applyFilters} className="grid gap-2 md:grid-cols-5 md:items-end">
                            <div className="md:col-span-2">
                                <label className="ta-field-label">Search</label>
                                <input className="ta-input" value={filterForm.data.q} onChange={(e) => filterForm.setData('q', e.target.value)} placeholder="Invoice, customer, phone" />
                            </div>
                            <div>
                                <label className="ta-field-label">Status</label>
                                <select className="ta-input" value={filterForm.data.status} onChange={(e) => filterForm.setData('status', e.target.value)}>
                                    <option value="">All statuses</option>
                                    <option value="draft">Draft</option>
                                    <option value="finalized">Finalized</option>
                                    <option value="void">Void</option>
                                </select>
                            </div>
                            <div>
                                <label className="ta-field-label">From</label>
                                <input className="ta-input" type="date" value={filterForm.data.date_from} onChange={(e) => filterForm.setData('date_from', e.target.value)} />
                            </div>
                            <div>
                                <label className="ta-field-label">To</label>
                                <input className="ta-input" type="date" value={filterForm.data.date_to} onChange={(e) => filterForm.setData('date_to', e.target.value)} />
                            </div>
                            <div className="flex gap-2 md:col-span-5">
                                <button className="ta-btn-primary" disabled={filterForm.processing}>Apply filters</button>
                                <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={clearFilters}>Clear</button>
                                <span className="self-center text-xs text-slate-500">Showing {invoices.from || 0}-{invoices.to || 0} of {invoices.total || 0}</span>
                            </div>
                        </form>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-5 py-3">Number</th>
                                    <th className="px-5 py-3">Customer</th>
                                    <th className="px-5 py-3">Status</th>
                                    <th className="px-5 py-3 text-right">Total</th>
                                    <th className="px-5 py-3 text-right">Paid</th>
                                    <th className="px-5 py-3 text-right">Balance</th>
                                    <th className="px-5 py-3">Issued</th>
                                    <th className="px-5 py-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {invoices.data.map((inv) => (
                                    <tr key={inv.id} className="border-t border-slate-100">
                                        <td className="px-5 py-3 font-medium text-slate-800">{inv.invoice_number || '— draft —'}</td>
                                        <td className="px-5 py-3 text-slate-600">{inv.customer_display_name}</td>
                                        <td className="px-5 py-3">
                                            <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${statusBadge(inv.status)}`}>{inv.status}</span>
                                        </td>
                                        <td className="px-5 py-3 text-right">{money(inv.total)}</td>
                                        <td className="px-5 py-3 text-right text-emerald-700">{money(inv.amount_paid)}</td>
                                        <td className="px-5 py-3 text-right font-medium">{money(inv.balance)}</td>
                                        <td className="px-5 py-3 text-slate-500">{inv.issued_at ? new Date(inv.issued_at).toLocaleString() : '—'}</td>
                                        <td className="px-5 py-3">
                                            <Link href={route('finance.invoices.show', inv.id)} className="text-indigo-600 hover:underline">
                                                Open
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                                {invoices.data.length === 0 ? (
                                    <tr className="border-t border-slate-100">
                                        <td className="px-5 py-6 text-sm text-slate-500" colSpan="8">No invoices match the current filters.</td>
                                    </tr>
                                ) : null}
                            </tbody>
                        </table>
                    </div>
                    {invoices.links?.length > 3 && (
                        <div className="flex flex-wrap gap-2 border-t border-slate-100 px-5 py-3 text-sm">
                            {invoices.links.map((link, i) =>
                                link.url ? (
                                    <Link
                                        key={i}
                                        href={link.url}
                                        className={`rounded-lg px-3 py-1 ${link.active ? 'bg-indigo-100 text-indigo-800' : 'bg-slate-100 text-slate-600'}`}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
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
