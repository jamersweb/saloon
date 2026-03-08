import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;

export default function SuppliersIndex({ suppliers }) {
    const { flash, auth } = usePage().props;
    const canManage = Boolean(auth?.permissions?.can_manage_procurement);
    const [editingId, setEditingId] = useState(null);

    const createForm = useForm({ name: '', contact_person: '', phone: '', email: '', address: '', is_active: true });
    const editForm = useForm({ name: '', contact_person: '', phone: '', email: '', address: '', is_active: true });

    const startEdit = (supplier) => {
        setEditingId(supplier.id);
        editForm.setData({
            name: supplier.name,
            contact_person: supplier.contact_person || '',
            phone: supplier.phone || '',
            email: supplier.email || '',
            address: supplier.address || '',
            is_active: Boolean(supplier.is_active),
        });
        editForm.clearErrors();
    };

    return (
        <AuthenticatedLayout header="Suppliers">
            <Head title="Suppliers" />
            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Create Supplier</h3>
                    <form onSubmit={(e) => { e.preventDefault(); createForm.post(route('suppliers.store'), { onSuccess: () => createForm.reset('name', 'contact_person', 'phone', 'email', 'address') }); }} className="grid gap-3 md:grid-cols-6">
                        <div><label className="ta-field-label">Supplier Name</label><input className="ta-input" placeholder="Supplier name" value={createForm.data.name} onChange={(e) => createForm.setData('name', e.target.value)} required />{fieldError(createForm, 'name')}</div>
                        <div><label className="ta-field-label">Contact Person</label><input className="ta-input" placeholder="Contact person" value={createForm.data.contact_person} onChange={(e) => createForm.setData('contact_person', e.target.value)} />{fieldError(createForm, 'contact_person')}</div>
                        <div><label className="ta-field-label">Phone</label><input className="ta-input" placeholder="Phone" value={createForm.data.phone} onChange={(e) => createForm.setData('phone', e.target.value)} />{fieldError(createForm, 'phone')}</div>
                        <div><label className="ta-field-label">Email</label><input className="ta-input" placeholder="Email" value={createForm.data.email} onChange={(e) => createForm.setData('email', e.target.value)} />{fieldError(createForm, 'email')}</div>
                        <div className="md:col-span-2"><input className="ta-input" placeholder="Address" value={createForm.data.address} onChange={(e) => createForm.setData('address', e.target.value)} />{fieldError(createForm, 'address')}</div>
                        <button className="ta-btn-primary" disabled={createForm.processing || !canManage}>Add Supplier</button>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Supplier Directory</h3></div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Name</th><th className="px-5 py-3">Contact</th><th className="px-5 py-3">Phone</th><th className="px-5 py-3">Email</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Actions</th></tr></thead>
                            <tbody>
                                {suppliers.map((supplier) => (
                                    <tr key={supplier.id} className="border-t border-slate-100">
                                        <td className="px-5 py-3 font-medium text-slate-700">{supplier.name}</td>
                                        <td className="px-5 py-3 text-slate-600">{supplier.contact_person || '-'}</td>
                                        <td className="px-5 py-3 text-slate-600">{supplier.phone || '-'}</td>
                                        <td className="px-5 py-3 text-slate-600">{supplier.email || '-'}</td>
                                        <td className="px-5 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${supplier.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700'}`}>{supplier.is_active ? 'Active' : 'Inactive'}</span></td>
                                        <td className="px-5 py-3"><div className="flex gap-2"><button className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 disabled:opacity-50" disabled={!canManage} onClick={() => startEdit(supplier)}>Edit</button><button className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700 disabled:opacity-50" disabled={!canManage} onClick={() => router.delete(route('suppliers.destroy', supplier.id))}>Deactivate</button></div></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                {editingId && (
                    <section className="ta-card p-5">
                        <h3 className="mb-4 text-sm font-semibold text-slate-700">Edit Supplier #{editingId}</h3>
                        <form onSubmit={(e) => { e.preventDefault(); editForm.put(route('suppliers.update', editingId), { onSuccess: () => setEditingId(null) }); }} className="grid gap-3 md:grid-cols-6">
                            <div><label className="ta-field-label">Name</label><input className="ta-input" value={editForm.data.name} onChange={(e) => editForm.setData('name', e.target.value)} required />{fieldError(editForm, 'name')}</div>
                            <div><label className="ta-field-label">Contact Person</label><input className="ta-input" value={editForm.data.contact_person} onChange={(e) => editForm.setData('contact_person', e.target.value)} />{fieldError(editForm, 'contact_person')}</div>
                            <div><label className="ta-field-label">Phone</label><input className="ta-input" value={editForm.data.phone} onChange={(e) => editForm.setData('phone', e.target.value)} />{fieldError(editForm, 'phone')}</div>
                            <div><label className="ta-field-label">Email</label><input className="ta-input" value={editForm.data.email} onChange={(e) => editForm.setData('email', e.target.value)} />{fieldError(editForm, 'email')}</div>
                            <div className="md:col-span-2"><input className="ta-input" value={editForm.data.address} onChange={(e) => editForm.setData('address', e.target.value)} />{fieldError(editForm, 'address')}</div>
                            <div className="flex items-center"><label className="text-sm text-slate-600"><input type="checkbox" checked={editForm.data.is_active} onChange={(e) => editForm.setData('is_active', e.target.checked)} className="mr-2" />Active</label></div>
                            <div className="md:col-span-6 flex gap-2"><button className="ta-btn-primary" disabled={editForm.processing || !canManage}>Save</button><button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setEditingId(null)}>Cancel</button></div>
                        </form>
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}








