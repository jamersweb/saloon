import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, usePage } from '@inertiajs/react';

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

export default function FinanceInvoicesIndex({ invoices }) {
    const { flash } = usePage().props;

    return (
        <AuthenticatedLayout header="Tax invoices">
            <Head title="Tax invoices" />

            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Link href={route('finance.index')} className="text-sm text-indigo-600 hover:underline">
                        ← Finance overview
                    </Link>
                    <Link href={route('finance.invoices.create')} className="ta-btn-primary">
                        New draft invoice
                    </Link>
                </div>

                <section className="ta-card overflow-hidden">
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
