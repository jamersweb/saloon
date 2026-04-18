import ConfirmActionModal from '@/Components/ConfirmActionModal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const money = (value, currency = 'AED') =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency, minimumFractionDigits: 2 }).format(Number(value || 0));

const blankItem = () => ({
    salon_service_id: '',
    description: '',
    quantity: '1',
    unit_price: '',
});

export default function FinanceInvoicesShow({
    invoice,
    customers,
    services,
    appointments = [],
    vat_rate_percent,
    currency_code,
    payment_methods,
    gift_cards_for_payment = [],
    can_manage_full_finance = true,
}) {
    const { flash } = usePage().props;
    const [invoiceConfirm, setInvoiceConfirm] = useState(null);
    const [invoiceConfirmBusy, setInvoiceConfirmBusy] = useState(false);
    const isDraft = invoice.status === 'draft';

    const editForm = useForm({
        customer_id: invoice.customer_id ? String(invoice.customer_id) : '',
        customer_display_name: invoice.customer_display_name,
        appointment_id: invoice.appointment_id != null ? String(invoice.appointment_id) : '',
        cashier_name: invoice.cashier_name || '',
        notes: invoice.notes || '',
        items: invoice.items.length
            ? invoice.items.map((r) => ({
                  salon_service_id: r.salon_service_id ? String(r.salon_service_id) : '',
                  description: r.description,
                  quantity: String(r.quantity),
                  unit_price: String(r.unit_price),
              }))
            : [blankItem()],
    });

    const payForm = useForm({
        amount: invoice.balance > 0 ? String(Math.min(invoice.balance, invoice.total)) : '',
        method: 'cash',
        paid_at: new Date().toISOString().slice(0, 16),
        reference_note: '',
        gift_card_id: '',
    });

    const emailForm = useForm({
        recipient_email: invoice.customer_email || '',
    });

    const serviceById = Object.fromEntries(services.map((s) => [String(s.id), s]));

    const addRow = () => editForm.setData('items', [...editForm.data.items, blankItem()]);
    const removeRow = (idx) => {
        const next = editForm.data.items.filter((_, i) => i !== idx);
        editForm.setData('items', next.length ? next : [blankItem()]);
    };

    const applyService = (idx, serviceId) => {
        const s = serviceById[serviceId];
        const next = [...editForm.data.items];
        next[idx] = {
            ...next[idx],
            salon_service_id: serviceId,
            description: s ? s.name : next[idx].description,
            unit_price: s ? String(s.price) : next[idx].unit_price,
        };
        editForm.setData('items', next);
    };

    const onCustomer = (id) => {
        const c = customers.find((x) => String(x.id) === String(id));
        editForm.setData({
            ...editForm.data,
            customer_id: id,
            customer_display_name: c ? c.name : editForm.data.customer_display_name,
        });
    };

    const onAppointment = (id) => {
        const ap = appointments.find((a) => String(a.id) === String(id));
        if (!ap) {
            editForm.setData('appointment_id', id);
            return;
        }
        const cust = customers.find((c) => c.id === ap.customer_id);
        if (ap.service_id && serviceById[String(ap.service_id)]) {
            const s = serviceById[String(ap.service_id)];
            editForm.setData({
                ...editForm.data,
                appointment_id: id,
                customer_id: ap.customer_id ? String(ap.customer_id) : '',
                customer_display_name: cust ? cust.name : editForm.data.customer_display_name,
                items: [
                    {
                        salon_service_id: String(ap.service_id),
                        description: s.name,
                        quantity: '1',
                        unit_price: String(s.price),
                    },
                ],
            });
            return;
        }
        editForm.setData({
            ...editForm.data,
            appointment_id: id,
            customer_id: ap.customer_id ? String(ap.customer_id) : '',
            customer_display_name: cust ? cust.name : editForm.data.customer_display_name,
        });
    };

    return (
        <AuthenticatedLayout header={`Invoice ${invoice.invoice_number || 'draft'}`}>
            <Head title={invoice.invoice_number || 'Draft invoice'} />

            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <div className="flex flex-wrap gap-3">
                    <Link href={route('finance.invoices.index')} className="text-sm text-indigo-600 hover:underline">
                        ← Invoices
                    </Link>
                    {invoice.status === 'finalized' && (
                        <a
                            href={route('finance.invoices.pdf', invoice.id)}
                            target="_blank"
                            rel="noreferrer"
                            className="text-sm font-medium text-indigo-600 hover:underline"
                        >
                            Print PDF receipt
                        </a>
                    )}
                </div>

                {invoice.status === 'finalized' && can_manage_full_finance && (
                    <section className="ta-card p-5">
                        <h3 className="mb-2 text-sm font-semibold text-slate-700">Email PDF receipt</h3>
                        <p className="mb-4 text-xs text-slate-500">
                            Sends the thermal-style tax receipt as a PDF attachment. Configure outgoing mail in your server <code className="rounded bg-slate-100 px-1">.env</code> (for example{' '}
                            <code className="rounded bg-slate-100 px-1">MAIL_MAILER</code>, <code className="rounded bg-slate-100 px-1">MAIL_HOST</code>).
                        </p>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                emailForm.post(route('finance.invoices.email-receipt', invoice.id), { preserveScroll: true });
                            }}
                            className="flex flex-wrap items-end gap-3"
                        >
                            <div className="min-w-[240px] flex-1">
                                <label className="ta-field-label">Recipient email</label>
                                <input
                                    type="email"
                                    className="ta-input"
                                    value={emailForm.data.recipient_email}
                                    onChange={(e) => emailForm.setData('recipient_email', e.target.value)}
                                    required
                                />
                                {emailForm.errors.recipient_email && <p className="mt-1 text-xs text-red-600">{emailForm.errors.recipient_email}</p>}
                            </div>
                            <button type="submit" className="ta-btn-primary" disabled={emailForm.processing}>
                                Send email
                            </button>
                        </form>
                    </section>
                )}

                <section className="ta-card p-5">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <p className="text-xs uppercase text-slate-500">Status</p>
                            <p className="text-lg font-semibold capitalize text-slate-800">{invoice.status}</p>
                            {invoice.issued_at && <p className="text-sm text-slate-500">Issued {new Date(invoice.issued_at).toLocaleString()}</p>}
                        </div>
                        <div className="text-right">
                            <p className="text-xs text-slate-500">Total / VAT / Subtotal</p>
                            <p className="text-xl font-bold text-slate-900">{money(invoice.total, currency_code)}</p>
                            <p className="text-sm text-slate-600">
                                VAT {money(invoice.vat_amount, currency_code)} · Net {money(invoice.subtotal, currency_code)}
                            </p>
                            {!isDraft && (
                                <p className="mt-1 text-sm">
                                    Paid {money(invoice.amount_paid, currency_code)} · Due{' '}
                                    <span className="font-semibold text-amber-700">{money(invoice.balance, currency_code)}</span>
                                </p>
                            )}
                        </div>
                    </div>
                    <p className="mt-4 text-sm text-slate-600">
                        Customer: <strong>{invoice.customer_display_name}</strong>
                    </p>
                    {invoice.cashier_name && (
                        <p className="text-sm text-slate-600">
                            Cashier: <strong>{invoice.cashier_name}</strong>
                        </p>
                    )}
                    <p className="text-xs text-slate-500">VAT rate used on lines: {vat_rate_percent}%</p>
                </section>

                {isDraft ? (
                    <section className="ta-card p-5">
                        <h3 className="mb-4 text-sm font-semibold text-slate-700">Edit draft</h3>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                editForm.transform((data) => ({
                                    ...data,
                                    customer_id: data.customer_id || null,
                                    appointment_id: data.appointment_id ? data.appointment_id : null,
                                    items: data.items.map((row) => ({
                                        salon_service_id: row.salon_service_id || null,
                                        description: row.description,
                                        quantity: parseFloat(row.quantity) || 0,
                                        unit_price: parseFloat(row.unit_price) || 0,
                                    })),
                                }));
                                editForm.put(route('finance.invoices.update', invoice.id));
                            }}
                            className="space-y-4"
                        >
                            <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label className="ta-field-label">Customer</label>
                                    <select className="ta-input" value={editForm.data.customer_id} onChange={(e) => onCustomer(e.target.value)}>
                                        <option value="">Walk-in</option>
                                        {customers.map((c) => (
                                            <option key={c.id} value={c.id}>
                                                {c.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div>
                                    <label className="ta-field-label">Name on receipt</label>
                                    <input
                                        className="ta-input"
                                        value={editForm.data.customer_display_name}
                                        onChange={(e) => editForm.setData('customer_display_name', e.target.value)}
                                        required
                                    />
                                </div>
                                <div>
                                    <label className="ta-field-label">Cashier</label>
                                    <input className="ta-input" value={editForm.data.cashier_name} onChange={(e) => editForm.setData('cashier_name', e.target.value)} />
                                </div>
                                <div className="md:col-span-2">
                                    <label className="ta-field-label">Link visit (optional)</label>
                                    <select className="ta-input" value={editForm.data.appointment_id} onChange={(e) => onAppointment(e.target.value)}>
                                        <option value="">None</option>
                                        {appointments.map((a) => (
                                            <option key={a.id} value={a.id}>
                                                {a.label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            </div>
                            <div className="flex items-center justify-between">
                                <label className="ta-field-label">Lines</label>
                                <button type="button" className="text-sm text-indigo-600 hover:underline" onClick={addRow}>
                                    + Add line
                                </button>
                            </div>
                            <div className="space-y-3">
                                {editForm.data.items.map((row, idx) => (
                                    <div key={idx} className="grid gap-2 rounded-lg border border-slate-200 p-3 md:grid-cols-12 md:items-end">
                                        <div className="md:col-span-3">
                                            <select className="ta-input" value={row.salon_service_id} onChange={(e) => applyService(idx, e.target.value)}>
                                                <option value="">Custom</option>
                                                {services.map((s) => (
                                                    <option key={s.id} value={s.id}>
                                                        {s.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="md:col-span-4">
                                            <input
                                                className="ta-input"
                                                value={row.description}
                                                onChange={(e) => {
                                                    const next = [...editForm.data.items];
                                                    next[idx] = { ...next[idx], description: e.target.value };
                                                    editForm.setData('items', next);
                                                }}
                                                required
                                            />
                                        </div>
                                        <div className="md:col-span-2">
                                            <input
                                                className="ta-input"
                                                type="number"
                                                min="0.01"
                                                step="0.01"
                                                value={row.quantity}
                                                onChange={(e) => {
                                                    const next = [...editForm.data.items];
                                                    next[idx] = { ...next[idx], quantity: e.target.value };
                                                    editForm.setData('items', next);
                                                }}
                                            />
                                        </div>
                                        <div className="md:col-span-2">
                                            <input
                                                className="ta-input"
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                value={row.unit_price}
                                                onChange={(e) => {
                                                    const next = [...editForm.data.items];
                                                    next[idx] = { ...next[idx], unit_price: e.target.value };
                                                    editForm.setData('items', next);
                                                }}
                                            />
                                        </div>
                                        <div className="md:col-span-1">
                                            <button type="button" className="text-xs text-red-600" onClick={() => removeRow(idx)}>
                                                Remove
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                            <textarea className="ta-input min-h-[60px]" value={editForm.data.notes} onChange={(e) => editForm.setData('notes', e.target.value)} placeholder="Notes" />
                            <div className="flex flex-wrap gap-2">
                                <button type="submit" className="ta-btn-primary" disabled={editForm.processing}>
                                    Save changes
                                </button>
                                <button
                                    type="button"
                                    className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm font-medium text-emerald-800"
                                    onClick={() => router.post(route('finance.invoices.finalize', invoice.id))}
                                >
                                    Issue tax invoice
                                </button>
                                {can_manage_full_finance ? (
                                    <button
                                        type="button"
                                        className="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700"
                                        onClick={() => setInvoiceConfirm('delete_draft')}
                                    >
                                        Delete draft
                                    </button>
                                ) : null}
                            </div>
                        </form>
                    </section>
                ) : (
                    <>
                        <section className="ta-card p-5">
                            <h3 className="mb-3 text-sm font-semibold text-slate-700">Line items</h3>
                            <div className="overflow-x-auto">
                                <table className="min-w-full text-sm">
                                    <thead className="text-left text-xs uppercase text-slate-500">
                                        <tr>
                                            <th className="py-2">Description</th>
                                            <th className="py-2 text-right">Qty</th>
                                            <th className="py-2 text-right">Price</th>
                                            <th className="py-2 text-right">VAT</th>
                                            <th className="py-2 text-right">Line total</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {invoice.items.map((row) => (
                                            <tr key={row.id} className="border-t border-slate-100">
                                                <td className="py-2">{row.description}</td>
                                                <td className="py-2 text-right">{row.quantity}</td>
                                                <td className="py-2 text-right">{money(row.unit_price, currency_code)}</td>
                                                <td className="py-2 text-right">{money(row.line_tax, currency_code)}</td>
                                                <td className="py-2 text-right font-medium">{money(row.line_total, currency_code)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        {invoice.status === 'finalized' && invoice.balance > 0.009 && (
                            <section className="ta-card p-5">
                                <h3 className="mb-4 text-sm font-semibold text-slate-700">Record payment</h3>
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        payForm.post(route('finance.invoices.payments.store', invoice.id), {
                                            preserveScroll: true,
                                            onSuccess: () => router.reload(),
                                        });
                                    }}
                                    className="grid gap-3 md:grid-cols-4"
                                >
                                    <div>
                                        <label className="ta-field-label">Amount</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            className="ta-input"
                                            value={payForm.data.amount}
                                            onChange={(e) => payForm.setData('amount', e.target.value)}
                                            required
                                        />
                                        {payForm.errors.amount && <p className="text-xs text-red-600">{payForm.errors.amount}</p>}
                                    </div>
                                    <div>
                                        <label className="ta-field-label">Method</label>
                                        <select
                                            className="ta-input"
                                            value={payForm.data.method}
                                            onChange={(e) => {
                                                const method = e.target.value;
                                                payForm.setData('method', method);
                                                if (method !== 'gift_card') {
                                                    payForm.setData('gift_card_id', '');
                                                }
                                            }}
                                        >
                                            {Object.entries(payment_methods).map(([k, label]) => (
                                                <option key={k} value={k}>
                                                    {label}
                                                </option>
                                            ))}
                                        </select>
                                    </div>
                                    <div>
                                        <label className="ta-field-label">Paid at</label>
                                        <input
                                            type="datetime-local"
                                            className="ta-input"
                                            value={payForm.data.paid_at}
                                            onChange={(e) => payForm.setData('paid_at', e.target.value)}
                                            required
                                        />
                                    </div>
                                    <div>
                                        <label className="ta-field-label">Reference</label>
                                        <input className="ta-input" value={payForm.data.reference_note} onChange={(e) => payForm.setData('reference_note', e.target.value)} />
                                    </div>
                                    <button type="submit" className="ta-btn-primary md:col-span-4" disabled={payForm.processing}>
                                        Add payment
                                    </button>
                                </form>
                            </section>
                        )}

                        {invoice.payments?.length > 0 && (
                            <section className="ta-card p-5">
                                <h3 className="mb-3 text-sm font-semibold text-slate-700">Payments</h3>
                                <ul className="space-y-2 text-sm">
                                    {invoice.payments.map((p) => (
                                        <li key={p.id} className="flex flex-wrap justify-between border-b border-slate-100 py-2">
                                            <span>
                                                {payment_methods[p.method] || p.method} · {p.created_by_name || 'Staff'}
                                            </span>
                                            <span className="font-semibold text-emerald-700">{money(p.amount, currency_code)}</span>
                                            <span className="w-full text-xs text-slate-500">{new Date(p.paid_at).toLocaleString()}</span>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}

                        {can_manage_full_finance && invoice.status === 'finalized' && invoice.amount_paid < 0.01 && (
                            <button
                                type="button"
                                className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900"
                                onClick={() => setInvoiceConfirm('void')}
                            >
                                Void invoice
                            </button>
                        )}
                    </>
                )}
                <ConfirmActionModal
                    show={Boolean(invoiceConfirm)}
                    title={invoiceConfirm === 'void' ? 'Void this invoice?' : 'Delete this draft?'}
                    message={invoiceConfirm === 'void' ? 'Voiding cannot be undone.' : 'This removes the draft invoice permanently.'}
                    confirmText={invoiceConfirm === 'void' ? 'Void invoice' : 'Delete draft'}
                    confirmClassName={
                        invoiceConfirm === 'void'
                            ? 'rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700 disabled:opacity-60'
                            : undefined
                    }
                    onClose={() => !invoiceConfirmBusy && setInvoiceConfirm(null)}
                    processing={invoiceConfirmBusy}
                    onConfirm={() => {
                        if (!invoiceConfirm) return;
                        setInvoiceConfirmBusy(true);
                        const finish = () => {
                            setInvoiceConfirmBusy(false);
                            setInvoiceConfirm(null);
                        };
                        if (invoiceConfirm === 'void') {
                            router.post(route('finance.invoices.void', invoice.id), {}, { onFinish: finish });
                            return;
                        }
                        router.delete(route('finance.invoices.destroy', invoice.id), { onFinish: finish });
                    }}
                />
            </div>
        </AuthenticatedLayout>
    );
}
