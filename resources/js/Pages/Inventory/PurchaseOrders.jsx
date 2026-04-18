import ConfirmActionModal from '@/Components/ConfirmActionModal';
import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;

/** Keeps digits and at most one decimal point for controlled currency-style typing. */
const normalizeDecimalTyping = (raw) => {
    if (raw === '' || raw == null) return '';
    let v = String(raw).replace(/[^\d.]/g, '');
    const dot = v.indexOf('.');
    if (dot === -1) return v;
    return v.slice(0, dot + 1) + v.slice(dot + 1).replace(/\./g, '');
};

const digitsOnly = (raw) => String(raw ?? '').replace(/\D/g, '');

export default function PurchaseOrdersIndex({ suppliers, items, purchaseOrders }) {
    const { flash, auth } = usePage().props;
    const canManage = Boolean(auth?.permissions?.can_manage_procurement);
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [editingId, setEditingId] = useState(null);
    const [cancelPoId, setCancelPoId] = useState(null);
    const [cancelPoBusy, setCancelPoBusy] = useState(false);

    const lineKeyRef = useRef(0);
    const newLine = () => ({
        lineKey: ++lineKeyRef.current,
        inventory_item_id: '',
        quantity_ordered: '1',
        unit_cost: '',
    });

    const freshCreatePayload = () => ({
        supplier_id: '',
        order_date: new Date().toISOString().slice(0, 10),
        expected_date: '',
        notes: '',
        items: [newLine()],
    });

    const createForm = useForm({
        supplier_id: '',
        order_date: new Date().toISOString().slice(0, 10),
        expected_date: '',
        notes: '',
        items: [newLine()],
    });

    const editForm = useForm({
        supplier_id: '',
        order_date: '',
        expected_date: '',
        notes: '',
        items: [newLine()],
    });

    createForm.transform((data) => ({
        ...data,
        items: (data.items || []).map(({ lineKey, ...rest }) => rest),
    }));
    editForm.transform((data) => ({
        ...data,
        items: (data.items || []).map(({ lineKey, ...rest }) => rest),
    }));

    const addLine = (form) => form.setData('items', [...form.data.items, newLine()]);
    const removeLine = (form, idx) => form.setData('items', form.data.items.filter((_, i) => i !== idx));
    const updateLine = (form, idx, key, value) => form.setData('items', form.data.items.map((line, i) => (i === idx ? { ...line, [key]: value } : line)));

    const openCreateModal = () => {
        setEditingId(null);
        editForm.clearErrors();
        createForm.clearErrors();
        createForm.setData(freshCreatePayload());
        setShowCreateModal(true);
    };

    const closeCreateModal = () => {
        setShowCreateModal(false);
        createForm.clearErrors();
    };

    const startEdit = (po) => {
        setShowCreateModal(false);
        createForm.clearErrors();
        setEditingId(po.id);
        editForm.setData({
            supplier_id: String(po.supplier_id),
            order_date: po.order_date || '',
            expected_date: po.expected_date || '',
            notes: po.notes || '',
            items: po.items.map((row) => ({
                lineKey: `saved-${row.id}`,
                inventory_item_id: String(row.inventory_item_id),
                quantity_ordered: String(row.quantity_ordered),
                unit_cost: normalizeDecimalTyping(row.unit_cost != null ? String(row.unit_cost) : ''),
            })),
        });
        editForm.clearErrors();
    };

    const closeEditModal = () => {
        setEditingId(null);
        editForm.clearErrors();
    };

    const transition = (id, status) => router.patch(route('purchase-orders.transition', id), { status });

    const createdPurchaseOrderId = flash?.created_purchase_order_id;

    useEffect(() => {
        if (createdPurchaseOrderId == null || createdPurchaseOrderId === '') {
            return;
        }
        const id = String(createdPurchaseOrderId);
        const el = document.getElementById(`po-card-${id}`);
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }, [createdPurchaseOrderId]);

    const FormLines = ({ form }) => (
        <div className="space-y-3">
            {form.data.items.map((line, idx) => (
                <div key={line.lineKey} className="min-w-0 rounded-xl border border-slate-200 p-3">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-12 lg:items-end">
                        <div className="min-w-0 sm:col-span-2 lg:col-span-5">
                            <label className="ta-field-label">Inventory item</label>
                            <select className="ta-input w-full min-w-0" value={line.inventory_item_id} onChange={(e) => updateLine(form, idx, 'inventory_item_id', e.target.value)} required>
                                <option value="">Select item</option>
                                {items.map((item) => <option key={item.id} value={item.id}>{item.name} ({item.sku})</option>)}
                            </select>
                        </div>
                        <div className="min-w-0 lg:col-span-2">
                            <label className="ta-field-label">Quantity</label>
                            <input
                                className="ta-input w-full min-w-0"
                                type="text"
                                inputMode="numeric"
                                autoComplete="off"
                                value={line.quantity_ordered}
                                onChange={(e) => {
                                    const v = digitsOnly(e.target.value);
                                    updateLine(form, idx, 'quantity_ordered', v === '' ? '' : v);
                                }}
                                onBlur={() => {
                                    const v = line.quantity_ordered;
                                    if (v === '' || v === '0') {
                                        updateLine(form, idx, 'quantity_ordered', '1');
                                    }
                                }}
                                required
                            />
                        </div>
                        <div className="min-w-0 lg:col-span-2">
                            <label className="ta-field-label">Unit cost</label>
                            <input
                                className="ta-input w-full min-w-0"
                                type="text"
                                inputMode="decimal"
                                autoComplete="off"
                                placeholder="0.00"
                                value={line.unit_cost}
                                onChange={(e) => updateLine(form, idx, 'unit_cost', normalizeDecimalTyping(e.target.value))}
                                required
                            />
                        </div>
                        <div className="flex items-end justify-stretch sm:justify-end lg:col-span-3">
                            <button type="button" className="w-full rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700 sm:w-auto" onClick={() => removeLine(form, idx)} disabled={form.data.items.length === 1}>
                                Remove line
                            </button>
                        </div>
                    </div>
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
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="min-w-0 space-y-1">
                            <h3 className="text-sm font-semibold text-slate-800">New purchase order</h3>
                            <p className="max-w-xl text-sm text-slate-600">
                                Open a guided form to add supplier, dates, and lines. After you save, the new draft appears in the <span className="font-semibold text-slate-700">Purchase orders</span> section on this same page (scroll down). You can edit or approve it there.
                            </p>
                        </div>
                        <button type="button" className="ta-btn-primary shrink-0 self-start sm:self-center" disabled={!canManage} onClick={openCreateModal}>
                            Create purchase order
                        </button>
                    </div>
                    {!canManage && <p className="mt-3 text-xs text-slate-500">You do not have permission to create purchase orders.</p>}
                </section>

                <Modal show={showCreateModal} onClose={closeCreateModal} maxWidth="4xl">
                    <div className="min-w-0 p-6">
                        <h3 className="mb-1 text-base font-semibold text-slate-800">Create purchase order</h3>
                        <p className="mb-4 text-sm text-slate-600">Fill in the header, add one or more inventory lines, then create a draft PO.</p>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                createForm.post(route('purchase-orders.store'), {
                                    onSuccess: () => {
                                        closeCreateModal();
                                        createForm.clearErrors();
                                        createForm.setData(freshCreatePayload());
                                    },
                                });
                            }}
                            className="space-y-4"
                        >
                            <div className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <div className="min-w-0">
                                    <label className="ta-field-label">Supplier</label>
                                    <select className="ta-input w-full min-w-0" value={createForm.data.supplier_id} onChange={(e) => createForm.setData('supplier_id', e.target.value)} required>
                                        <option value="">Select supplier</option>
                                        {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                    </select>
                                    {fieldError(createForm, 'supplier_id')}
                                </div>
                                <div className="min-w-0">
                                    <label className="ta-field-label">Order date</label>
                                    <input className="ta-input w-full min-w-0" type="date" value={createForm.data.order_date} onChange={(e) => createForm.setData('order_date', e.target.value)} required />
                                    {fieldError(createForm, 'order_date')}
                                </div>
                                <div className="min-w-0">
                                    <label className="ta-field-label">Expected date</label>
                                    <input className="ta-input w-full min-w-0" type="date" value={createForm.data.expected_date} onChange={(e) => createForm.setData('expected_date', e.target.value)} />
                                    {fieldError(createForm, 'expected_date')}
                                </div>
                                <div className="min-w-0 sm:col-span-2 lg:col-span-1">
                                    <label className="ta-field-label">Notes</label>
                                    <input className="ta-input w-full min-w-0" placeholder="Optional notes" value={createForm.data.notes} onChange={(e) => createForm.setData('notes', e.target.value)} />
                                    {fieldError(createForm, 'notes')}
                                </div>
                            </div>

                            <div>
                                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Line items</p>
                                <FormLines form={createForm} />
                            </div>

                            <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4">
                                <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={closeCreateModal}>
                                    Cancel
                                </button>
                                <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => addLine(createForm)}>
                                    Add line
                                </button>
                                <button type="submit" className="ta-btn-primary" disabled={createForm.processing || !canManage}>
                                    Create draft PO
                                </button>
                            </div>
                        </form>
                    </div>
                </Modal>

                <Modal show={Boolean(editingId)} onClose={closeEditModal} maxWidth="4xl">
                    <div className="min-w-0 p-6">
                        <h3 className="mb-1 text-base font-semibold text-slate-800">Edit draft purchase order #{editingId}</h3>
                        <p className="mb-4 text-sm text-slate-600">Update lines and dates, then save. Use the list below to approve when ready.</p>
                        <form onSubmit={(e) => { e.preventDefault(); editForm.put(route('purchase-orders.update', editingId), { onSuccess: () => closeEditModal() }); }} className="space-y-4">
                            <div className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <div className="min-w-0">
                                    <label className="ta-field-label">Supplier</label>
                                    <select className="ta-input w-full min-w-0" value={editForm.data.supplier_id} onChange={(e) => editForm.setData('supplier_id', e.target.value)} required>
                                        <option value="">Select supplier</option>
                                        {suppliers.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                    </select>
                                    {fieldError(editForm, 'supplier_id')}
                                </div>
                                <div className="min-w-0">
                                    <label className="ta-field-label">Order date</label>
                                    <input className="ta-input w-full min-w-0" type="date" value={editForm.data.order_date} onChange={(e) => editForm.setData('order_date', e.target.value)} required />
                                    {fieldError(editForm, 'order_date')}
                                </div>
                                <div className="min-w-0">
                                    <label className="ta-field-label">Expected date</label>
                                    <input className="ta-input w-full min-w-0" type="date" value={editForm.data.expected_date || ''} onChange={(e) => editForm.setData('expected_date', e.target.value)} />
                                    {fieldError(editForm, 'expected_date')}
                                </div>
                                <div className="min-w-0 sm:col-span-2 lg:col-span-1">
                                    <label className="ta-field-label">Notes</label>
                                    <input className="ta-input w-full min-w-0" placeholder="Optional notes" value={editForm.data.notes} onChange={(e) => editForm.setData('notes', e.target.value)} />
                                    {fieldError(editForm, 'notes')}
                                </div>
                            </div>

                            <div>
                                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Line items</p>
                                <FormLines form={editForm} />
                            </div>

                            <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4">
                                <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={closeEditModal}>
                                    Close
                                </button>
                                <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => addLine(editForm)}>
                                    Add line
                                </button>
                                <button type="submit" className="ta-btn-primary" disabled={editForm.processing || !canManage}>
                                    Save changes
                                </button>
                            </div>
                        </form>
                    </div>
                </Modal>

                <ConfirmActionModal
                    show={Boolean(cancelPoId)}
                    title="Cancel this purchase order?"
                    message="The order will be marked cancelled and will no longer receive goods."
                    confirmText="Cancel order"
                    onClose={() => !cancelPoBusy && setCancelPoId(null)}
                    processing={cancelPoBusy}
                    onConfirm={() => {
                        if (!cancelPoId) return;
                        setCancelPoBusy(true);
                        router.patch(
                            route('purchase-orders.transition', cancelPoId),
                            { status: 'cancelled' },
                            {
                                onFinish: () => {
                                    setCancelPoBusy(false);
                                    setCancelPoId(null);
                                },
                            },
                        );
                    }}
                />

                <section id="purchase-orders-list" className="ta-card overflow-hidden scroll-mt-24">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                        <div>
                            <h3 className="text-sm font-semibold text-slate-700">Purchase orders</h3>
                            <p className="mt-0.5 text-xs text-slate-500">Newest first. Drafts stay here until you approve, receive, or cancel.</p>
                        </div>
                        {canManage ? (
                            <button type="button" className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50" onClick={openCreateModal}>
                                New purchase order
                            </button>
                        ) : null}
                    </div>
                    <div className="space-y-4 p-5">
                        {purchaseOrders.length === 0 && <p className="text-sm text-slate-500">No purchase orders yet.</p>}
                        {purchaseOrders.map((po) => {
                            const isJustCreated = String(po.id) === String(createdPurchaseOrderId ?? '');
                            return (
                            <div
                                key={po.id}
                                id={`po-card-${po.id}`}
                                className={`rounded-xl border p-4 transition-shadow ${
                                    isJustCreated ? 'border-emerald-300 bg-emerald-50/40 ring-2 ring-emerald-200/80' : 'border-slate-200'
                                }`}
                            >
                                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                                    <div>
                                        <p className="text-sm font-semibold text-slate-700">{po.po_number} • {po.supplier_name}</p>
                                        <p className="text-xs text-slate-500">Order: {po.order_date} | Expected: {po.expected_date || '-'} | Total: {po.total_cost}</p>
                                    </div>
                                    <div className="flex gap-2">
                                        {po.status === 'draft' && <button type="button" className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs text-indigo-700" onClick={() => startEdit(po)} disabled={!canManage}>Edit</button>}
                                        {po.status === 'draft' && <button type="button" className="rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs text-emerald-700" onClick={() => transition(po.id, 'approved')} disabled={!canManage}>Approve</button>}
                                        {po.status === 'approved' && <button type="button" className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs text-indigo-700" onClick={() => transition(po.id, 'received')} disabled={!canManage}>Receive</button>}
                                        {po.status !== 'received' && po.status !== 'cancelled' && <button type="button" className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs text-red-700" onClick={() => setCancelPoId(po.id)} disabled={!canManage}>Cancel</button>}
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
                            );
                        })}
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}










