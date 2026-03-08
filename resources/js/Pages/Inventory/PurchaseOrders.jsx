import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;

const blankLine = { inventory_item_id: '', quantity_ordered: 1, unit_cost: 0 };

export default function PurchaseOrdersIndex({ suppliers, items, purchaseOrders }) {
    const { flash, auth } = usePage().props;
    const canManage = Boolean(auth?.permissions?.can_manage_procurement);
    const [editingId, setEditingId] = useState(null);

    const createForm = useForm({
        supplier_id: '',
        order_date: new Date().toISOString().slice(0, 10),
        expected_date: '',
        notes: '',
        items: [{ ...blankLine }],
    });

    const editForm = useForm({
        supplier_id: '',
        order_date: '',
        expected_date: '',
        notes: '',
        items: [{ ...blankLine }],
    });

    const addLine = (form) => form.setData('items', [...form.data.items, { ...blankLine }]);
    const removeLine = (form, idx) => form.setData('items', form.data.items.filter((_, i) => i !== idx));
    const updateLine = (form, idx, key, value) => form.setData('items', form.data.items.map((line, i) => i === idx ? { ...line, [key]: value } : line));

    const startEdit = (po) => {
        setEditingId(po.id);
        editForm.setData({
            supplier_id: po.supplier_id,
            order_date: po.order_date || '',
            expected_date: po.expected_date || '',
            notes: po.notes || '',
            items: po.items.map((row) => ({
                inventory_item_id: row.inventory_item_id,
                quantity_ordered: row.quantity_ordered,
                unit_cost: row.unit_cost,
            })),
        });
        editForm.clearErrors();
    };

    const transition = (id, status) => router.patch(route('purchase-orders.transition', id), { status });

    const FormLines = ({ form }) => (
        <div className="space-y-2">
            {form.data.items.map((line, idx) => (
                <div key={idx} className="grid gap-2 rounded-xl border border-slate-200 p-3 md:grid-cols-4">
                    <select className="ta-input" value={line.inventory_item_id} onChange={(e) => updateLine(form, idx, 'inventory_item_id', e.target.value)} required>
                        <option value="">Select item</option>
                        {items.map((item) => <option key={item.id} value={item.id}>{item.name} ({item.sku})</option>)}
                    </select>
                    <input className="ta-input" type="number" min="1" value={line.quantity_ordered} onChange={(e) => updateLine(form, idx, 'quantity_ordered', e.target.value)} required />
                    <input className="ta-input" type="number" step="0.01" min="0" value={line.unit_cost} onChange={(e) => updateLine(form, idx, 'unit_cost', e.target.value)} required />
                    <button type="button" className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" onClick={() => removeLine(form, idx)} disabled={form.data.items.length === 1}>Remove</button>
                </div>
            ))}
        </div>
    );

    return (
        <AuthenticatedLayout header="Purchase Orders">
            <Head title="Purchase Orders" />

            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Create Purchase Order</h3>
                    <form onSubmit={(e) => { e.preventDefault(); createForm.post(route('purchase-orders.store'), { onSuccess: () => createForm.reset('notes', 'expected_date') }); }} className="space-y-4">
                        <div className="grid gap-3 md:grid-cols-4">
                            <div><select className="ta-input" value={createForm.data.supplier_id} onChange={(e) => createForm.setData('supplier_id', e.target.value)} required><option value="">Select supplier</option>{suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>{fieldError(createForm, 'supplier_id')}</div>
                            <div><input className="ta-input" type="date" value={createForm.data.order_date} onChange={(e) => createForm.setData('order_date', e.target.value)} required />{fieldError(createForm, 'order_date')}</div>
                            <div><input className="ta-input" type="date" value={createForm.data.expected_date} onChange={(e) => createForm.setData('expected_date', e.target.value)} />{fieldError(createForm, 'expected_date')}</div>
                            <div><input className="ta-input" placeholder="Notes" value={createForm.data.notes} onChange={(e) => createForm.setData('notes', e.target.value)} />{fieldError(createForm, 'notes')}</div>
                        </div>

                        <FormLines form={createForm} />

                        <div className="flex gap-2">
                            <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => addLine(createForm)}>Add Line</button>
                            <button className="ta-btn-primary" disabled={createForm.processing || !canManage}>Create PO</button>
                        </div>
                    </form>
                </section>

                {editingId && (
                    <section className="ta-card p-5">
                        <h3 className="mb-4 text-sm font-semibold text-slate-700">Edit Draft Purchase Order #{editingId}</h3>
                        <form onSubmit={(e) => { e.preventDefault(); editForm.put(route('purchase-orders.update', editingId), { onSuccess: () => setEditingId(null) }); }} className="space-y-4">
                            <div className="grid gap-3 md:grid-cols-4">
                                <div><select className="ta-input" value={editForm.data.supplier_id} onChange={(e) => editForm.setData('supplier_id', e.target.value)} required><option value="">Select supplier</option>{suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>{fieldError(editForm, 'supplier_id')}</div>
                                <div><input className="ta-input" type="date" value={editForm.data.order_date} onChange={(e) => editForm.setData('order_date', e.target.value)} required />{fieldError(editForm, 'order_date')}</div>
                                <div><input className="ta-input" type="date" value={editForm.data.expected_date || ''} onChange={(e) => editForm.setData('expected_date', e.target.value)} />{fieldError(editForm, 'expected_date')}</div>
                                <div><input className="ta-input" placeholder="Notes" value={editForm.data.notes} onChange={(e) => editForm.setData('notes', e.target.value)} />{fieldError(editForm, 'notes')}</div>
                            </div>

                            <FormLines form={editForm} />

                            <div className="flex gap-2">
                                <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => addLine(editForm)}>Add Line</button>
                                <button className="ta-btn-primary" disabled={editForm.processing || !canManage}>Save Changes</button>
                                <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setEditingId(null)}>Cancel</button>
                            </div>
                        </form>
                    </section>
                )}

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Purchase Orders</h3></div>
                    <div className="space-y-4 p-5">
                        {purchaseOrders.length === 0 && <p className="text-sm text-slate-500">No purchase orders yet.</p>}
                        {purchaseOrders.map((po) => (
                            <div key={po.id} className="rounded-xl border border-slate-200 p-4">
                                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p className="text-sm font-semibold text-slate-700">{po.po_number} • {po.supplier_name}</p>
                                        <p className="text-xs text-slate-500">Order: {po.order_date} | Expected: {po.expected_date || '-'} | Total: {po.total_cost}</p>
                                    </div>
                                    <div className="flex gap-2">
                                        {po.status === 'draft' && <button className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs text-indigo-700" onClick={() => startEdit(po)} disabled={!canManage}>Edit</button>}
                                        {po.status === 'draft' && <button className="rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs text-emerald-700" onClick={() => transition(po.id, 'approved')} disabled={!canManage}>Approve</button>}
                                        {po.status === 'approved' && <button className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs text-indigo-700" onClick={() => transition(po.id, 'received')} disabled={!canManage}>Receive</button>}
                                        {po.status !== 'received' && po.status !== 'cancelled' && <button className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs text-red-700" onClick={() => transition(po.id, 'cancelled')} disabled={!canManage}>Cancel</button>}
                                        <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">{po.status}</span>
                                    </div>
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full text-sm">
                                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-3 py-2">Item</th><th className="px-3 py-2">Ordered</th><th className="px-3 py-2">Received</th><th className="px-3 py-2">Unit Cost</th><th className="px-3 py-2">Line Total</th></tr></thead>
                                        <tbody>{po.items.map((line) => <tr key={line.id} className="border-t border-slate-100"><td className="px-3 py-2">{line.item_name} ({line.item_sku})</td><td className="px-3 py-2">{line.quantity_ordered}</td><td className="px-3 py-2">{line.quantity_received}</td><td className="px-3 py-2">{line.unit_cost}</td><td className="px-3 py-2">{line.line_total}</td></tr>)}</tbody>
                                    </table>
                                </div>
                            </div>
                        ))}
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
