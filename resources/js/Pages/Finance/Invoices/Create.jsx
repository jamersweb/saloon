import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useMemo } from 'react';

const blankItem = () => ({
    salon_service_id: '',
    description: '',
    quantity: '1',
    unit_price: '',
});

export default function FinanceInvoicesCreate({ customers, services, appointments, vat_rate_percent, currency_code }) {
    const { flash } = usePage().props;

    const form = useForm({
        customer_id: '',
        customer_display_name: '',
        appointment_id: '',
        cashier_name: '',
        notes: '',
        items: [blankItem()],
    });

    const serviceById = useMemo(() => Object.fromEntries(services.map((s) => [String(s.id), s])), [services]);

    const addRow = () => form.setData('items', [...form.data.items, blankItem()]);

    const removeRow = (idx) => {
        const next = form.data.items.filter((_, i) => i !== idx);
        form.setData('items', next.length ? next : [blankItem()]);
    };

    const applyService = (idx, serviceId) => {
        const s = serviceById[serviceId];
        const next = [...form.data.items];
        next[idx] = {
            ...next[idx],
            salon_service_id: serviceId,
            description: s ? s.name : next[idx].description,
            unit_price: s ? String(s.price) : next[idx].unit_price,
        };
        form.setData('items', next);
    };

    const onAppointment = (id) => {
        const ap = appointments.find((a) => String(a.id) === String(id));
        if (!ap) {
            form.setData('appointment_id', id);
            return;
        }
        const cust = customers.find((c) => c.id === ap.customer_id);
        form.setData({
            ...form.data,
            appointment_id: id,
            customer_id: ap.customer_id ? String(ap.customer_id) : '',
            customer_display_name: cust ? cust.name : form.data.customer_display_name,
        });
        if (ap.service_id && serviceById[String(ap.service_id)]) {
            const s = serviceById[String(ap.service_id)];
            form.setData({
                ...form.data,
                appointment_id: id,
                customer_id: ap.customer_id ? String(ap.customer_id) : '',
                customer_display_name: cust ? cust.name : form.data.customer_display_name,
                items: [
                    {
                        salon_service_id: String(ap.service_id),
                        description: s.name,
                        quantity: '1',
                        unit_price: String(s.price),
                    },
                ],
            });
        }
    };

    const onCustomer = (id) => {
        const c = customers.find((x) => String(x.id) === String(id));
        form.setData({
            ...form.data,
            customer_id: id,
            customer_display_name: c ? c.name : '',
        });
    };

    return (
        <AuthenticatedLayout header="New tax invoice (draft)">
            <Head title="New invoice" />

            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <Link href={route('finance.invoices.index')} className="text-sm text-indigo-600 hover:underline">
                    ← Invoices
                </Link>

                <section className="ta-card p-5">
                    <p className="mb-4 text-xs text-slate-500">
                        VAT rate from settings: <strong>{vat_rate_percent}%</strong> · Currency: <strong>{currency_code}</strong>
                    </p>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.transform((data) => ({
                                ...data,
                                customer_id: data.customer_id || null,
                                appointment_id: data.appointment_id || null,
                                items: data.items.map((row) => ({
                                    salon_service_id: row.salon_service_id || null,
                                    description: row.description,
                                    quantity: parseFloat(row.quantity) || 0,
                                    unit_price: parseFloat(row.unit_price) || 0,
                                })),
                            })).post(route('finance.invoices.store'));
                        }}
                        className="space-y-4"
                    >
                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <label className="ta-field-label">Customer (directory)</label>
                                <select className="ta-input" value={form.data.customer_id} onChange={(e) => onCustomer(e.target.value)}>
                                    <option value="">Walk-in / manual name</option>
                                    {customers.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="ta-field-label">Customer name on receipt</label>
                                <input
                                    className="ta-input"
                                    value={form.data.customer_display_name}
                                    onChange={(e) => form.setData('customer_display_name', e.target.value)}
                                    required
                                />
                                {form.errors.customer_display_name && <p className="mt-1 text-xs text-red-600">{form.errors.customer_display_name}</p>}
                            </div>
                            <div>
                                <label className="ta-field-label">Link visit (optional)</label>
                                <select className="ta-input" value={form.data.appointment_id} onChange={(e) => onAppointment(e.target.value)}>
                                    <option value="">None</option>
                                    {appointments.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="ta-field-label">Cashier name (optional)</label>
                                <input className="ta-input" value={form.data.cashier_name} onChange={(e) => form.setData('cashier_name', e.target.value)} />
                            </div>
                        </div>

                        <div>
                            <div className="mb-2 flex items-center justify-between">
                                <label className="ta-field-label">Line items (services)</label>
                                <button type="button" className="text-sm text-indigo-600 hover:underline" onClick={addRow}>
                                    + Add line
                                </button>
                            </div>
                            <div className="space-y-3">
                                {form.data.items.map((row, idx) => (
                                    <div key={idx} className="grid gap-2 rounded-lg border border-slate-200 p-3 md:grid-cols-12 md:items-end">
                                        <div className="md:col-span-3">
                                            <label className="text-xs text-slate-500">Service</label>
                                            <select className="ta-input mt-1" value={row.salon_service_id} onChange={(e) => applyService(idx, e.target.value)}>
                                                <option value="">Custom line</option>
                                                {services.map((s) => (
                                                    <option key={s.id} value={s.id}>
                                                        {s.name} ({currency_code} {s.price})
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="md:col-span-4">
                                            <label className="text-xs text-slate-500">Description</label>
                                            <input
                                                className="ta-input mt-1"
                                                value={row.description}
                                                onChange={(e) => {
                                                    const next = [...form.data.items];
                                                    next[idx] = { ...next[idx], description: e.target.value };
                                                    form.setData('items', next);
                                                }}
                                                required
                                            />
                                        </div>
                                        <div className="md:col-span-2">
                                            <label className="text-xs text-slate-500">Qty</label>
                                            <input
                                                className="ta-input mt-1"
                                                type="number"
                                                min="0.01"
                                                step="0.01"
                                                value={row.quantity}
                                                onChange={(e) => {
                                                    const next = [...form.data.items];
                                                    next[idx] = { ...next[idx], quantity: e.target.value };
                                                    form.setData('items', next);
                                                }}
                                                required
                                            />
                                        </div>
                                        <div className="md:col-span-2">
                                            <label className="text-xs text-slate-500">Unit price</label>
                                            <input
                                                className="ta-input mt-1"
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                value={row.unit_price}
                                                onChange={(e) => {
                                                    const next = [...form.data.items];
                                                    next[idx] = { ...next[idx], unit_price: e.target.value };
                                                    form.setData('items', next);
                                                }}
                                                required
                                            />
                                        </div>
                                        <div className="md:col-span-1 flex md:justify-end">
                                            <button type="button" className="text-xs text-red-600 hover:underline" onClick={() => removeRow(idx)}>
                                                Remove
                                            </button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                            {form.errors.items && <p className="text-xs text-red-600">{form.errors.items}</p>}
                        </div>

                        <div>
                            <label className="ta-field-label">Notes</label>
                            <textarea className="ta-input min-h-[80px]" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </div>

                        <button type="submit" className="ta-btn-primary" disabled={form.processing}>
                            Save draft
                        </button>
                    </form>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
