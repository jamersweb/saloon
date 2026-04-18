import ConfirmActionModal from '@/Components/ConfirmActionModal';
import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;

export default function InventoryIndex({ items, recentTransactions, openAlerts }) {
    const { flash, auth } = usePage().props;
    const canManage = Boolean(auth?.permissions?.can_manage_inventory);
    const [editingId, setEditingId] = useState(null);
    const [adjustingId, setAdjustingId] = useState(null);
    const [deactivateItemId, setDeactivateItemId] = useState(null);
    const [deactivateBusy, setDeactivateBusy] = useState(false);

    const createForm = useForm({ sku: '', name: '', category: '', unit: 'pcs', cost_price: '', selling_price: '', stock_quantity: 0, reorder_level: 0, is_active: true });
    const editForm = useForm({ sku: '', name: '', category: '', unit: 'pcs', cost_price: '', selling_price: '', stock_quantity: 0, reorder_level: 0, is_active: true });
    const adjustForm = useForm({ type: 'in', quantity: 1, reference: '', notes: '' });

    const startEdit = (item) => {
        setAdjustingId(null);
        adjustForm.clearErrors();
        setEditingId(item.id);
        editForm.setData({
            sku: item.sku,
            name: item.name,
            category: item.category || '',
            unit: item.unit,
            cost_price: item.cost_price,
            selling_price: item.selling_price,
            stock_quantity: item.stock_quantity,
            reorder_level: item.reorder_level,
            is_active: Boolean(item.is_active),
        });
        editForm.clearErrors();
    };

    const startAdjust = (item) => {
        setEditingId(null);
        editForm.clearErrors();
        setAdjustingId(item.id);
        adjustForm.setData({ type: 'in', quantity: 1, reference: '', notes: '' });
        adjustForm.clearErrors();
    };

    const closeEditModal = () => {
        setEditingId(null);
        editForm.clearErrors();
    };

    const closeAdjustModal = () => {
        setAdjustingId(null);
        adjustForm.clearErrors();
    };

    return (
        <AuthenticatedLayout header="Inventory">
            <Head title="Inventory" />
            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <section className="ta-card overflow-hidden">
                    <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                        <h3 className="text-sm font-semibold text-slate-700">Low Stock Alerts</h3>
                        <button className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700 disabled:opacity-50" disabled={!canManage} onClick={() => router.post(route('inventory.alerts.scan'))}>Refresh Alerts</button>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Item</th><th className="px-5 py-3">Stock</th><th className="px-5 py-3">Reorder</th><th className="px-5 py-3">Created</th><th className="px-5 py-3">Action</th></tr></thead>
                            <tbody>
                                {openAlerts.length === 0 && <tr><td className="px-5 py-3 text-slate-500" colSpan="5">No open low-stock alerts.</td></tr>}
                                {openAlerts.map((alert) => (
                                    <tr key={alert.id} className="border-t border-slate-100">
                                        <td className="px-5 py-3 text-slate-700">{alert.item_name} ({alert.item_sku})</td>
                                        <td className="px-5 py-3 font-semibold text-red-600">{alert.stock_quantity}</td>
                                        <td className="px-5 py-3 text-slate-600">{alert.reorder_level}</td>
                                        <td className="px-5 py-3 text-slate-600">{new Date(alert.created_at).toLocaleString()}</td>
                                        <td className="px-5 py-3"><button className="rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 disabled:opacity-50" disabled={!canManage} onClick={() => router.patch(route('inventory.alerts.resolve', alert.id))}>Resolve</button></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Create Inventory Item</h3>
                    <form onSubmit={(e) => { e.preventDefault(); createForm.post(route('inventory.store'), { onSuccess: () => createForm.reset('sku', 'name', 'category', 'cost_price', 'selling_price') }); }} className="grid gap-3 md:grid-cols-5">
                        <div><label className="ta-field-label">Sku</label><input className="ta-input" placeholder="SKU" value={createForm.data.sku} onChange={(e) => createForm.setData('sku', e.target.value)} required />{fieldError(createForm, 'sku')}</div>
                        <div><label className="ta-field-label">Item Name</label><input className="ta-input" placeholder="Item name" value={createForm.data.name} onChange={(e) => createForm.setData('name', e.target.value)} required />{fieldError(createForm, 'name')}</div>
                        <div><label className="ta-field-label">Category</label><input className="ta-input" placeholder="Category" value={createForm.data.category} onChange={(e) => createForm.setData('category', e.target.value)} />{fieldError(createForm, 'category')}</div>
                        <div><label className="ta-field-label">Unit</label><input className="ta-input" placeholder="Unit (pcs, ml, etc.)" value={createForm.data.unit} onChange={(e) => createForm.setData('unit', e.target.value)} required />{fieldError(createForm, 'unit')}</div>
                        <div><label className="ta-field-label">Cost Price</label><input className="ta-input" type="number" step="0.01" min="0" placeholder="Cost price" value={createForm.data.cost_price} onChange={(e) => createForm.setData('cost_price', e.target.value)} required />{fieldError(createForm, 'cost_price')}</div>
                        <div><label className="ta-field-label">Selling Price</label><input className="ta-input" type="number" step="0.01" min="0" placeholder="Selling price" value={createForm.data.selling_price} onChange={(e) => createForm.setData('selling_price', e.target.value)} required />{fieldError(createForm, 'selling_price')}</div>
                        <div><label className="ta-field-label">Opening Stock</label><input className="ta-input" type="number" min="0" placeholder="Opening stock" value={createForm.data.stock_quantity} onChange={(e) => createForm.setData('stock_quantity', e.target.value)} required />{fieldError(createForm, 'stock_quantity')}</div>
                        <div><label className="ta-field-label">Reorder Level</label><input className="ta-input" type="number" min="0" placeholder="Reorder level" value={createForm.data.reorder_level} onChange={(e) => createForm.setData('reorder_level', e.target.value)} required />{fieldError(createForm, 'reorder_level')}</div>
                        <div className="flex items-center"><label className="text-sm text-slate-600"><input type="checkbox" checked={createForm.data.is_active} onChange={(e) => createForm.setData('is_active', e.target.checked)} className="mr-2" />Active</label></div>
                        <button className="ta-btn-primary" disabled={createForm.processing || !canManage}>Add Item</button>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Stock Catalog</h3></div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">SKU</th><th className="px-5 py-3">Item</th><th className="px-5 py-3">Category</th><th className="px-5 py-3">Stock</th><th className="px-5 py-3">Reorder</th><th className="px-5 py-3">Price</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Actions</th></tr></thead>
                            <tbody>
                                {items.map((item) => (
                                    <tr key={item.id} className="border-t border-slate-100">
                                        <td className="px-5 py-3 font-medium text-slate-700">{item.sku}</td>
                                        <td className="px-5 py-3 text-slate-600">{item.name}</td>
                                        <td className="px-5 py-3 text-slate-600">{item.category || '—'}</td>
                                        <td className={`px-5 py-3 font-semibold ${item.stock_quantity <= item.reorder_level ? 'text-red-600' : 'text-slate-700'}`}>{item.stock_quantity} {item.unit}</td>
                                        <td className="px-5 py-3 text-slate-600">{item.reorder_level}</td>
                                        <td className="px-5 py-3 text-slate-600">{item.selling_price}</td>
                                        <td className="px-5 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${item.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700'}`}>{item.is_active ? 'Active' : 'Inactive'}</span></td>
                                        <td className="px-5 py-3"><div className="flex flex-wrap gap-2"><button type="button" className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 disabled:opacity-50" disabled={!canManage} onClick={() => startEdit(item)}>Edit</button><button type="button" className="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 disabled:opacity-50" disabled={!canManage} onClick={() => startAdjust(item)}>Adjust</button><button type="button" className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700 disabled:opacity-50" disabled={!canManage} onClick={() => setDeactivateItemId(item.id)}>Deactivate</button></div></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <Modal show={Boolean(editingId)} onClose={closeEditModal} maxWidth="2xl">
                    <div className="min-w-0 p-6">
                        <h3 className="mb-4 text-base font-semibold text-slate-800">Edit item #{editingId}</h3>
                        <form onSubmit={(e) => { e.preventDefault(); editForm.put(route('inventory.update', editingId), { onSuccess: () => closeEditModal() }); }} className="grid min-w-0 gap-3 md:grid-cols-5">
                            <div><label className="ta-field-label">Sku</label><input className="ta-input" value={editForm.data.sku} onChange={(e) => editForm.setData('sku', e.target.value)} required />{fieldError(editForm, 'sku')}</div>
                            <div><label className="ta-field-label">Name</label><input className="ta-input" value={editForm.data.name} onChange={(e) => editForm.setData('name', e.target.value)} required />{fieldError(editForm, 'name')}</div>
                            <div><label className="ta-field-label">Category</label><input className="ta-input" value={editForm.data.category} onChange={(e) => editForm.setData('category', e.target.value)} />{fieldError(editForm, 'category')}</div>
                            <div><label className="ta-field-label">Unit</label><input className="ta-input" value={editForm.data.unit} onChange={(e) => editForm.setData('unit', e.target.value)} required />{fieldError(editForm, 'unit')}</div>
                            <div><label className="ta-field-label">Cost Price</label><input className="ta-input" type="number" step="0.01" min="0" value={editForm.data.cost_price} onChange={(e) => editForm.setData('cost_price', e.target.value)} required />{fieldError(editForm, 'cost_price')}</div>
                            <div><label className="ta-field-label">Selling Price</label><input className="ta-input" type="number" step="0.01" min="0" value={editForm.data.selling_price} onChange={(e) => editForm.setData('selling_price', e.target.value)} required />{fieldError(editForm, 'selling_price')}</div>
                            <div><label className="ta-field-label">Stock Quantity</label><input className="ta-input" type="number" min="0" value={editForm.data.stock_quantity} onChange={(e) => editForm.setData('stock_quantity', e.target.value)} required />{fieldError(editForm, 'stock_quantity')}</div>
                            <div><label className="ta-field-label">Reorder Level</label><input className="ta-input" type="number" min="0" value={editForm.data.reorder_level} onChange={(e) => editForm.setData('reorder_level', e.target.value)} required />{fieldError(editForm, 'reorder_level')}</div>
                            <div className="flex items-center"><label className="text-sm text-slate-600"><input type="checkbox" checked={editForm.data.is_active} onChange={(e) => editForm.setData('is_active', e.target.checked)} className="mr-2" />Active</label></div>
                            <div className="md:col-span-5 flex gap-2"><button className="ta-btn-primary" disabled={editForm.processing || !canManage}>Save</button><button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={closeEditModal}>Close</button></div>
                        </form>
                    </div>
                </Modal>

                <Modal show={Boolean(adjustingId)} onClose={closeAdjustModal} maxWidth="xl">
                    <div className="min-w-0 p-6">
                        <h3 className="mb-4 text-base font-semibold text-slate-800">Adjust stock</h3>
                        <form onSubmit={(e) => { e.preventDefault(); adjustForm.post(route('inventory.adjust', adjustingId), { onSuccess: () => closeAdjustModal() }); }} className="space-y-4">
                            <div className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <div className="min-w-0"><label className="ta-field-label">Type</label><select className="ta-input w-full min-w-0" value={adjustForm.data.type} onChange={(e) => adjustForm.setData('type', e.target.value)}><option value="in">Stock In (+)</option><option value="out">Stock Out (-)</option><option value="adjustment">Adjustment (+/-)</option></select>{fieldError(adjustForm, 'type')}</div>
                                <div className="min-w-0"><label className="ta-field-label">Quantity</label><input className="ta-input w-full min-w-0" type="number" placeholder="Quantity" value={adjustForm.data.quantity} onChange={(e) => adjustForm.setData('quantity', e.target.value)} required />{fieldError(adjustForm, 'quantity')}</div>
                                <div className="min-w-0 sm:col-span-2 lg:col-span-2"><label className="ta-field-label">Reference</label><input className="ta-input w-full min-w-0" placeholder="Reference" value={adjustForm.data.reference} onChange={(e) => adjustForm.setData('reference', e.target.value)} />{fieldError(adjustForm, 'reference')}</div>
                                <div className="min-w-0 sm:col-span-2 lg:col-span-4"><label className="ta-field-label">Notes</label><input className="ta-input w-full min-w-0" placeholder="Notes" value={adjustForm.data.notes} onChange={(e) => adjustForm.setData('notes', e.target.value)} />{fieldError(adjustForm, 'notes')}</div>
                            </div>
                            <div className="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-4">
                                <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={closeAdjustModal}>Close</button>
                                <button type="submit" className="ta-btn-primary" disabled={adjustForm.processing || !canManage}>Apply</button>
                            </div>
                        </form>
                    </div>
                </Modal>

                <ConfirmActionModal
                    show={Boolean(deactivateItemId)}
                    title="Deactivate this inventory item?"
                    message="It will be hidden from selection lists. Stock history is preserved."
                    confirmText="Deactivate"
                    onClose={() => !deactivateBusy && setDeactivateItemId(null)}
                    processing={deactivateBusy}
                    onConfirm={() => {
                        if (!deactivateItemId) return;
                        setDeactivateBusy(true);
                        router.delete(route('inventory.destroy', deactivateItemId), {
                            onFinish: () => {
                                setDeactivateBusy(false);
                                setDeactivateItemId(null);
                            },
                        });
                    }}
                />

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Recent Stock Transactions</h3></div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Date</th><th className="px-5 py-3">Item</th><th className="px-5 py-3">Type</th><th className="px-5 py-3">Qty</th><th className="px-5 py-3">Reference</th><th className="px-5 py-3">By</th></tr></thead>
                            <tbody>
                                {recentTransactions.map((tx) => (
                                    <tr key={tx.id} className="border-t border-slate-100">
                                        <td className="px-5 py-3 text-slate-600">{new Date(tx.created_at).toLocaleString()}</td>
                                        <td className="px-5 py-3 text-slate-700">{tx.item_name} ({tx.item_sku})</td>
                                        <td className="px-5 py-3 text-slate-600">{tx.type}</td>
                                        <td className={`px-5 py-3 font-semibold ${tx.quantity > 0 ? 'text-emerald-600' : 'text-red-600'}`}>{tx.quantity > 0 ? `+${tx.quantity}` : tx.quantity}</td>
                                        <td className="px-5 py-3 text-slate-600">{tx.reference || '-'}</td>
                                        <td className="px-5 py-3 text-slate-600">{tx.performed_by || '-'}</td>
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









