import ConfirmActionModal from '@/Components/ConfirmActionModal';
import SearchableSelect from '@/Components/SearchableSelect';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const money = (value, currency = 'AED') =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency, minimumFractionDigits: 2 }).format(Number(value || 0));

const lineTotals = (row, vatRatePercent = 0) => {
    const quantity = Number(row.quantity || 0);
    const unitPrice = Number(row.unit_price || 0);
    const discount = Number(row.discount_amount || 0);
    const subtotal = quantity * unitPrice;
    const taxable = Math.max(0, subtotal - discount);
    const vat = taxable * (Number(vatRatePercent || 0) / 100);

    return {
        subtotal,
        vat,
        total: taxable + vat,
    };
};

const blankItem = () => ({
    salon_service_id: '',
    description: '',
    quantity: '1',
    unit_price: '',
    discount_amount: '0',
});

export default function FinanceInvoicesShow({
    invoice,
    customers,
    services,
    inventory_items = [],
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
                  discount_amount: String(r.discount_amount || 0),
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

    const assignedGiftCards = gift_cards_for_payment || [];
    const singleAssignedGiftCard = assignedGiftCards.length === 1 ? assignedGiftCards[0] : null;
    const totalAssignedGiftCardBalance = assignedGiftCards.reduce((sum, card) => sum + Number(card.remaining_value || 0), 0);

    const serviceById = useMemo(() => Object.fromEntries(services.map((s) => [String(s.id), s])), [services]);
    const inventoryById = useMemo(() => Object.fromEntries(inventory_items.map((item) => [String(item.id), item])), [inventory_items]);
    const customerOptions = useMemo(() => ([
        { value: '', label: 'Walk-in' },
        ...customers.map((c) => ({ value: String(c.id), label: c.name })),
    ]), [customers]);
    const appointmentOptions = useMemo(() => ([
        { value: '', label: 'None' },
        ...appointments.map((a) => ({ value: String(a.id), label: a.label })),
    ]), [appointments]);
    const serviceOptions = useMemo(() => ([
        { value: '', label: 'Custom' },
        ...services.map((s) => ({ value: `service:${s.id}`, label: s.name })),
        ...inventory_items.map((item) => ({ value: `inventory:${item.id}`, label: `${item.name}${item.sku ? ` (${item.sku})` : ''}` })),
    ]), [services, inventory_items]);

    const selectedLineName = (row) => {
        if (row.salon_service_id && serviceById[String(row.salon_service_id)]?.name) {
            return serviceById[String(row.salon_service_id)].name;
        }

        return row.description || 'Custom line';
    };

    const addRow = () => editForm.setData('items', [...editForm.data.items, blankItem()]);
    const removeRow = (idx) => {
        const next = editForm.data.items.filter((_, i) => i !== idx);
        editForm.setData('items', next.length ? next : [blankItem()]);
    };

    const applyService = (idx, selectedValue) => {
        const next = [...editForm.data.items];
        if (!selectedValue) {
            next[idx] = {
                ...next[idx],
                salon_service_id: '',
            };
            editForm.setData('items', next);
            return;
        }
        const [kind, rawId] = String(selectedValue).split(':');
        const s = kind === 'service' ? serviceById[rawId] : null;
        const item = kind === 'inventory' ? inventoryById[rawId] : null;
        next[idx] = {
            ...next[idx],
            salon_service_id: s ? String(s.id) : '',
            description: s
                ? s.name
                : item
                    ? `${item.name}${item.sku ? ` (${item.sku})` : ''}`
                    : next[idx].description,
            unit_price: s
                ? String(s.price)
                : item
                    ? String(item.selling_price ?? '')
                    : next[idx].unit_price,
            discount_amount: next[idx].discount_amount || '0',
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
        if (Array.isArray(ap.visit_items) && ap.visit_items.length) {
            editForm.setData({
                ...editForm.data,
                appointment_id: id,
                customer_id: ap.customer_id ? String(ap.customer_id) : '',
                customer_display_name: cust ? cust.name : editForm.data.customer_display_name,
                items: ap.visit_items,
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
                    {assignedGiftCards.length > 0 && (
                        <div className="mt-3 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
                            <p className="font-medium">
                                Gift card balance available: <strong>{money(totalAssignedGiftCardBalance, currency_code)}</strong>
                            </p>
                            <p className="mt-1 text-emerald-800">
                                {assignedGiftCards.length === 1
                                    ? `Assigned card: ${assignedGiftCards[0].code}`
                                    : `${assignedGiftCards.length} assigned gift cards are available for this customer.`}
                            </p>
                            {invoice.total > totalAssignedGiftCardBalance ? (
                                <p className="mt-1 font-medium text-red-700">
                                    Services total is short by {money(invoice.total - totalAssignedGiftCardBalance, currency_code)}.
                                </p>
                            ) : (
                                <p className="mt-1 text-emerald-800">Gift card balance is enough to cover these services.</p>
                            )}
                        </div>
                    )}
                    {invoice.cashier_name && (
                        <p className="text-sm text-slate-600">
                            Cashier: <strong>{invoice.cashier_name}</strong>
                        </p>
                    )}
                    {invoice.settlement_label && (
                        <p className="text-sm text-slate-600">
                            Payment method: <strong>{invoice.settlement_label}</strong>
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
                                        discount_amount: parseFloat(row.discount_amount) || 0,
                                    })),
                                }));
                                editForm.put(route('finance.invoices.update', invoice.id));
                            }}
                            className="space-y-4"
                        >
                            <div className="grid gap-4 md:grid-cols-2">
                                <div>
                                    <SearchableSelect
                                        label="Customer"
                                        value={editForm.data.customer_id}
                                        onChange={onCustomer}
                                        options={customerOptions}
                                        placeholder="Search customer"
                                        className="md:col-span-1"
                                    />
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
                                    <SearchableSelect
                                        label="Link visit (optional)"
                                        value={editForm.data.appointment_id}
                                        onChange={onAppointment}
                                        options={appointmentOptions}
                                        placeholder="Search linked visit"
                                    />
                                </div>
                            </div>
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <label className="ta-field-label">Services and items used</label>
                                    <p className="text-xs text-slate-500">Each line below is what the client will see on the invoice.</p>
                                </div>
                                <button type="button" className="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-100" onClick={addRow}>
                                    + Add service/item
                                </button>
                            </div>
                            <div className="space-y-3">
                                {editForm.data.items.map((row, idx) => {
                                    const totals = lineTotals(row, vat_rate_percent);
                                    const lineName = selectedLineName(row);

                                    return (
                                        <div key={idx} className="rounded-lg border border-slate-200 bg-slate-50/40 p-4">
                                            <div className="mb-4 flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 pb-3">
                                                <div className="min-w-0">
                                                    <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Line {idx + 1}</p>
                                                    <h4 className="mt-1 break-words text-base font-semibold text-slate-900">{lineName}</h4>
                                                    <p className="mt-1 text-xs text-slate-500">
                                                        {row.salon_service_id ? 'Selected service' : 'Custom service or product'} shown on the invoice.
                                                    </p>
                                                </div>
                                                <div className="text-right">
                                                    <p className="text-xs uppercase text-slate-500">Line total</p>
                                                    <p className="text-lg font-semibold text-slate-900">{money(totals.total, currency_code)}</p>
                                                    <button type="button" className="mt-1 text-xs font-medium text-red-600 hover:underline" onClick={() => removeRow(idx)}>
                                                        Remove line
                                                    </button>
                                                </div>
                                            </div>
                                            <div className="grid gap-3 lg:grid-cols-12 lg:items-start">
                                                <div className="lg:col-span-3">
                                                    <SearchableSelect
                                                        label="Choose service or product"
                                                        value={row.salon_service_id ? `service:${row.salon_service_id}` : ''}
                                                        onChange={(serviceId) => applyService(idx, serviceId)}
                                                        options={serviceOptions}
                                                        placeholder="Search service or product"
                                                    />
                                                </div>
                                                <div className="lg:col-span-4">
                                                    <label className="ta-field-label">Service name on invoice</label>
                                                    <input
                                                        className="ta-input"
                                                        value={row.description}
                                                        onChange={(e) => {
                                                            const next = [...editForm.data.items];
                                                            next[idx] = { ...next[idx], description: e.target.value };
                                                            editForm.setData('items', next);
                                                        }}
                                                        placeholder="Example: Blowdry Curly/Wavy with Iron Short"
                                                        required
                                                    />
                                                </div>
                                                <div className="lg:col-span-1">
                                                    <label className="ta-field-label">Qty</label>
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
                                                <div className="lg:col-span-1">
                                                    <label className="ta-field-label">Price</label>
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
                                                <div className="lg:col-span-1">
                                                    <label className="ta-field-label">Discount</label>
                                                    <input
                                                        className="ta-input"
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        value={row.discount_amount}
                                                        onChange={(e) => {
                                                            const next = [...editForm.data.items];
                                                            next[idx] = { ...next[idx], discount_amount: e.target.value };
                                                            editForm.setData('items', next);
                                                        }}
                                                    />
                                                </div>
                                                <div className="rounded-lg border border-slate-200 bg-white p-3 text-xs text-slate-600 lg:col-span-2">
                                                    <div className="flex justify-between gap-2">
                                                        <span>Net</span>
                                                        <span className="font-medium text-slate-800">{money(Math.max(0, totals.subtotal - Number(row.discount_amount || 0)), currency_code)}</span>
                                                    </div>
                                                    <div className="mt-1 flex justify-between gap-2">
                                                        <span>VAT</span>
                                                        <span className="font-medium text-slate-800">{money(totals.vat, currency_code)}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
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
                            <h3 className="mb-1 text-sm font-semibold text-slate-700">Services used</h3>
                            <p className="mb-3 text-xs text-slate-500">These are the services and items included in this invoice.</p>
                            <div className="overflow-x-auto">
                                <table className="min-w-full text-sm">
                                    <thead className="text-left text-xs uppercase text-slate-500">
                                        <tr>
                                            <th className="py-2">Service / item</th>
                                            <th className="py-2 text-right">Qty</th>
                                            <th className="py-2 text-right">Price</th>
                                            <th className="py-2 text-right">Discount</th>
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
                                                <td className="py-2 text-right">{money(row.discount_amount, currency_code)}</td>
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
                                                if (method === 'gift_card') {
                                                    if (Number(invoice.amount_paid || 0) < 0.01) {
                                                        payForm.setData('amount', String(Number(invoice.subtotal || 0)));
                                                    }
                                                    payForm.setData('gift_card_id', singleAssignedGiftCard ? String(singleAssignedGiftCard.id) : '');
                                                } else {
                                                    if (Number(invoice.balance || 0) > 0) {
                                                        payForm.setData('amount', String(Number(invoice.balance || 0)));
                                                    }
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
                                    {payForm.data.method === 'gift_card' ? (
                                        <div className="md:col-span-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4">
                                            {assignedGiftCards.length === 0 ? (
                                                <p className="text-sm font-medium text-red-700">No active gift card with remaining balance is assigned to this customer.</p>
                                            ) : singleAssignedGiftCard ? (
                                                <>
                                                    <p className="text-sm font-medium text-emerald-900">Assigned gift card: <strong>{singleAssignedGiftCard.code}</strong></p>
                                                    <p className="mt-1 text-sm text-emerald-800">Remaining balance: {money(singleAssignedGiftCard.remaining_value, currency_code)}</p>
                                                </>
                                            ) : (
                                                <div>
                                                    <label className="ta-field-label">Assigned gift card</label>
                                                    <select
                                                        className="ta-input"
                                                        value={payForm.data.gift_card_id}
                                                        onChange={(e) => payForm.setData('gift_card_id', e.target.value)}
                                                        required
                                                    >
                                                        <option value="">Select gift card</option>
                                                        {assignedGiftCards.map((card) => (
                                                            <option key={card.id} value={card.id}>
                                                                {card.code} ({money(card.remaining_value, currency_code)})
                                                            </option>
                                                        ))}
                                                    </select>
                                                    {payForm.errors.gift_card_id && <p className="mt-1 text-xs text-red-600">{payForm.errors.gift_card_id}</p>}
                                                </div>
                                            )}
                                        </div>
                                    ) : null}
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
                                                {p.method_label || payment_methods[p.method] || p.method} · {p.created_by_name || 'Staff'}
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
