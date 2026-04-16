import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';

const money = (value) =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency: 'AED', minimumFractionDigits: 2 }).format(Number(value || 0));

export default function FinanceExpensesIndex({ expenses, purchaseOrders, categories }) {
    const { flash } = usePage().props;
    const form = useForm({
        category: 'supplies',
        vendor_name: '',
        expense_date: new Date().toISOString().slice(0, 10),
        amount_subtotal: '',
        vat_amount: '0',
        payment_status: 'unpaid',
        paid_at: '',
        purchase_order_id: '',
        notes: '',
    });

    const categoryOptions = Object.entries(categories);

    return (
        <AuthenticatedLayout header="Expenses">
            <Head title="Expenses" />

            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <div className="flex flex-wrap gap-3">
                    <Link href={route('finance.index')} className="text-sm text-indigo-600 hover:underline">
                        ← Finance overview
                    </Link>
                </div>

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Record expense</h3>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.transform((d) => ({
                                ...d,
                                amount_subtotal: parseFloat(d.amount_subtotal),
                                vat_amount: parseFloat(d.vat_amount),
                                purchase_order_id: d.purchase_order_id || null,
                            }));
                            form.post(route('finance.expenses.store'), { onSuccess: () => form.reset() });
                        }}
                        className="grid gap-3 md:grid-cols-3"
                    >
                        <div>
                            <label className="ta-field-label">Category</label>
                            <select className="ta-input" value={form.data.category} onChange={(e) => form.setData('category', e.target.value)}>
                                {categoryOptions.map(([k, label]) => (
                                    <option key={k} value={k}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="ta-field-label">Vendor</label>
                            <input className="ta-input" value={form.data.vendor_name} onChange={(e) => form.setData('vendor_name', e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Date</label>
                            <input type="date" className="ta-input" value={form.data.expense_date} onChange={(e) => form.setData('expense_date', e.target.value)} required />
                        </div>
                        <div>
                            <label className="ta-field-label">Amount (excl. VAT)</label>
                            <input type="number" step="0.01" min="0" className="ta-input" value={form.data.amount_subtotal} onChange={(e) => form.setData('amount_subtotal', e.target.value)} required />
                        </div>
                        <div>
                            <label className="ta-field-label">VAT amount</label>
                            <input type="number" step="0.01" min="0" className="ta-input" value={form.data.vat_amount} onChange={(e) => form.setData('vat_amount', e.target.value)} required />
                        </div>
                        <div>
                            <label className="ta-field-label">Payment</label>
                            <select className="ta-input" value={form.data.payment_status} onChange={(e) => form.setData('payment_status', e.target.value)}>
                                <option value="unpaid">Unpaid (payable)</option>
                                <option value="paid">Paid</option>
                            </select>
                        </div>
                        <div>
                            <label className="ta-field-label">Link PO (optional)</label>
                            <select className="ta-input" value={form.data.purchase_order_id} onChange={(e) => form.setData('purchase_order_id', e.target.value)}>
                                <option value="">None</option>
                                {purchaseOrders.map((po) => (
                                    <option key={po.id} value={po.id}>
                                        {po.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="md:col-span-3">
                            <label className="ta-field-label">Notes</label>
                            <input className="ta-input" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </div>
                        <button type="submit" className="ta-btn-primary" disabled={form.processing}>
                            Save expense
                        </button>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4">
                        <h3 className="text-sm font-semibold text-slate-700">Expense ledger</h3>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
                                <tr>
                                    <th className="px-5 py-3">Date</th>
                                    <th className="px-5 py-3">Category</th>
                                    <th className="px-5 py-3">Vendor</th>
                                    <th className="px-5 py-3 text-right">Subtotal</th>
                                    <th className="px-5 py-3 text-right">VAT</th>
                                    <th className="px-5 py-3 text-right">Total</th>
                                    <th className="px-5 py-3">Status</th>
                                    <th className="px-5 py-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {expenses.data.map((row) => (
                                    <tr key={row.id} className="border-t border-slate-100">
                                        <td className="px-5 py-3">{row.expense_date}</td>
                                        <td className="px-5 py-3">{categories[row.category] || row.category}</td>
                                        <td className="px-5 py-3 text-slate-600">{row.vendor_name || '—'}</td>
                                        <td className="px-5 py-3 text-right">{money(row.amount_subtotal)}</td>
                                        <td className="px-5 py-3 text-right">{money(row.vat_amount)}</td>
                                        <td className="px-5 py-3 text-right font-medium">{money(row.total_amount)}</td>
                                        <td className="px-5 py-3">
                                            <span
                                                className={`rounded-full px-2 py-0.5 text-xs font-semibold ${row.payment_status === 'paid' ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900'}`}
                                            >
                                                {row.payment_status}
                                            </span>
                                        </td>
                                        <td className="px-5 py-3">
                                            <div className="flex flex-wrap gap-2">
                                                {row.payment_status === 'unpaid' && (
                                                    <button
                                                        type="button"
                                                        className="text-xs text-indigo-600 hover:underline"
                                                        onClick={() => router.patch(route('finance.expenses.mark-paid', row.id))}
                                                    >
                                                        Mark paid
                                                    </button>
                                                )}
                                                <button
                                                    type="button"
                                                    className="text-xs text-red-600 hover:underline"
                                                    onClick={() => confirm('Delete this expense?') && router.delete(route('finance.expenses.destroy', row.id))}
                                                >
                                                    Delete
                                                </button>
                                            </div>
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
