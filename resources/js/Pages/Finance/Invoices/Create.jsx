import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import SearchableSelect from '@/Components/SearchableSelect';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useMemo } from 'react';

const blankItem = () => ({
    salon_service_id: '',
    staff_profile_id: '',
    revenue_category: 'service_income',
    cost_center: 'general_salon',
    description: '',
    quantity: '1',
    unit_price: '',
});

export default function FinanceInvoicesCreate({ customers, services, staff_profiles = [], inventory_items = [], revenue_categories = {}, cost_centers = {}, appointments, vat_rate_percent, currency_code }) {
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
    const inventoryById = useMemo(() => Object.fromEntries(inventory_items.map((item) => [String(item.id), item])), [inventory_items]);
    const customerOptions = useMemo(() => ([
        { value: '', label: 'Walk-in / manual name' },
        ...customers.map((c) => ({ value: String(c.id), label: c.name })),
    ]), [customers]);
    const appointmentOptions = useMemo(() => ([
        { value: '', label: 'None' },
        ...appointments.map((a) => ({ value: String(a.id), label: a.label })),
    ]), [appointments]);
    const serviceOptions = useMemo(() => ([
        { value: '', label: 'Custom line' },
        ...services.map((s) => ({ value: `service:${s.id}`, label: `${s.name} (${currency_code} ${s.price})` })),
        ...inventory_items.map((item) => ({ value: `inventory:${item.id}`, label: `${item.name}${item.sku ? ` (${item.sku})` : ''} (${currency_code} ${item.selling_price})` })),
    ]), [services, inventory_items, currency_code]);
    const staffOptions = useMemo(() => ([
        { value: '', label: 'Unassigned' },
        ...staff_profiles.map((staff) => ({ value: String(staff.id), label: staff.name || `Staff #${staff.id}` })),
    ]), [staff_profiles]);
    const revenueCategoryOptions = Object.entries(revenue_categories);
    const costCenterOptions = Object.entries(cost_centers);

    const addRow = () => form.setData('items', [...form.data.items, blankItem()]);

    const removeRow = (idx) => {
        const next = form.data.items.filter((_, i) => i !== idx);
        form.setData('items', next.length ? next : [blankItem()]);
    };

    const applyService = (idx, selectedValue) => {
        const next = [...form.data.items];
        if (!selectedValue) {
            next[idx] = {
                ...next[idx],
                salon_service_id: '',
                revenue_category: 'service_income',
            };
            form.setData('items', next);
            return;
        }
        const [kind, rawId] = String(selectedValue).split(':');
        const s = kind === 'service' ? serviceById[rawId] : null;
        const item = kind === 'inventory' ? inventoryById[rawId] : null;
        next[idx] = {
            ...next[idx],
            salon_service_id: s ? String(s.id) : '',
            revenue_category: s ? 'service_income' : 'retail_product_sales',
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
        if (Array.isArray(ap.visit_items) && ap.visit_items.length) {
            form.setData({
                ...form.data,
                appointment_id: id,
                customer_id: ap.customer_id ? String(ap.customer_id) : '',
                customer_display_name: cust ? cust.name : form.data.customer_display_name,
                items: ap.visit_items,
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
                                    staff_profile_id: row.staff_profile_id || null,
                                    revenue_category: row.revenue_category || null,
                                    cost_center: row.cost_center || null,
                                    description: row.description,
                                    quantity: parseFloat(row.quantity) || 0,
                                    unit_price: parseFloat(row.unit_price) || 0,
                                })),
                            }));
                            form.post(route('finance.invoices.store'));
                        }}
                        className="space-y-4"
                    >
                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <SearchableSelect
                                    label="Customer (directory)"
                                    value={form.data.customer_id}
                                    onChange={onCustomer}
                                    options={customerOptions}
                                    placeholder="Search customer"
                                />
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
                                <SearchableSelect
                                    label="Link visit (optional)"
                                    value={form.data.appointment_id}
                                    onChange={onAppointment}
                                    options={appointmentOptions}
                                    placeholder="Search linked visit"
                                />
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
                                            <SearchableSelect
                                                className="mt-1"
                                                value={row.salon_service_id ? `service:${row.salon_service_id}` : ''}
                                                onChange={(serviceId) => applyService(idx, serviceId)}
                                                options={serviceOptions}
                                                placeholder="Search service or product"
                                            />
                                        </div>
                                        <div className="md:col-span-3">
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
                                            <label className="text-xs text-slate-500">Staff</label>
                                            <SearchableSelect
                                                className="mt-1"
                                                value={row.staff_profile_id || ''}
                                                onChange={(staffId) => {
                                                    const next = [...form.data.items];
                                                    next[idx] = { ...next[idx], staff_profile_id: staffId };
                                                    form.setData('items', next);
                                                }}
                                                options={staffOptions}
                                                placeholder="Search staff"
                                            />
                                        </div>
                                        <div className="md:col-span-2">
                                            <label className="text-xs text-slate-500">Revenue category</label>
                                            <select
                                                className="ta-input mt-1"
                                                value={row.revenue_category}
                                                onChange={(e) => {
                                                    const next = [...form.data.items];
                                                    next[idx] = { ...next[idx], revenue_category: e.target.value };
                                                    form.setData('items', next);
                                                }}
                                            >
                                                {revenueCategoryOptions.map(([value, label]) => (
                                                    <option key={value} value={value}>
                                                        {label}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="md:col-span-2">
                                            <label className="text-xs text-slate-500">Cost center</label>
                                            <select
                                                className="ta-input mt-1"
                                                value={row.cost_center}
                                                onChange={(e) => {
                                                    const next = [...form.data.items];
                                                    next[idx] = { ...next[idx], cost_center: e.target.value };
                                                    form.setData('items', next);
                                                }}
                                            >
                                                {costCenterOptions.map(([value, label]) => (
                                                    <option key={value} value={value}>
                                                        {label}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="md:col-span-1">
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
