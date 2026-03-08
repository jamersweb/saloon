import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;

export default function ServicesIndex({ services }) {
    const { flash } = usePage().props;
    const [editingId, setEditingId] = useState(null);

    const createForm = useForm({ name: '', category: '', duration_minutes: '', buffer_minutes: '', repeat_after_days: '', price: '', is_active: true });
    const editForm = useForm({ name: '', category: '', duration_minutes: '', buffer_minutes: '', repeat_after_days: '', price: '', is_active: true });

    const startEdit = (service) => {
        setEditingId(service.id);
        editForm.setData({
            name: service.name ?? '',
            category: service.category ?? '',
            duration_minutes: service.duration_minutes ?? '',
            buffer_minutes: service.buffer_minutes ?? 0,
            repeat_after_days: service.repeat_after_days ?? '',
            price: service.price ?? '',
            is_active: Boolean(service.is_active),
        });
        editForm.clearErrors();
    };

    return (
        <AuthenticatedLayout header="Services">
            <Head title="Services" />
            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Create Service</h3>
                    <form onSubmit={(e) => { e.preventDefault(); createForm.post(route('services.store'), { onSuccess: () => createForm.reset() }); }} className="grid gap-3 md:grid-cols-7">
                        <div><input className="ta-input" placeholder="Service name" value={createForm.data.name} onChange={(e) => createForm.setData('name', e.target.value)} required />{fieldError(createForm, 'name')}</div>
                        <div><input className="ta-input" placeholder="Category" value={createForm.data.category} onChange={(e) => createForm.setData('category', e.target.value)} />{fieldError(createForm, 'category')}</div>
                        <div><input className="ta-input" type="number" min="5" placeholder="Duration" value={createForm.data.duration_minutes} onChange={(e) => createForm.setData('duration_minutes', e.target.value)} required />{fieldError(createForm, 'duration_minutes')}</div>
                        <div><input className="ta-input" type="number" min="0" placeholder="Buffer" value={createForm.data.buffer_minutes} onChange={(e) => createForm.setData('buffer_minutes', e.target.value)} />{fieldError(createForm, 'buffer_minutes')}</div>
                        <div><input className="ta-input" type="number" min="1" placeholder="Repeat days" value={createForm.data.repeat_after_days ?? ''} onChange={(e) => createForm.setData('repeat_after_days', e.target.value === '' ? null : e.target.value)} />{fieldError(createForm, 'repeat_after_days')}</div>
                        <div><input className="ta-input" type="number" step="0.01" min="0" placeholder="Price" value={createForm.data.price} onChange={(e) => createForm.setData('price', e.target.value)} required />{fieldError(createForm, 'price')}</div>
                        <button className="ta-btn-primary" disabled={createForm.processing}>Add</button>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Service Catalog</h3></div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Name</th><th className="px-5 py-3">Category</th><th className="px-5 py-3">Duration</th><th className="px-5 py-3">Buffer</th><th className="px-5 py-3">Repeat</th><th className="px-5 py-3">Price</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Actions</th></tr></thead>
                            <tbody>{services.map((s) => <tr key={s.id} className="border-t border-slate-100"><td className="px-5 py-3 font-medium text-slate-700">{s.name}</td><td className="px-5 py-3 text-slate-600">{s.category || '-'}</td><td className="px-5 py-3 text-slate-600">{s.duration_minutes}m</td><td className="px-5 py-3 text-slate-600">{s.buffer_minutes}m</td><td className="px-5 py-3 text-slate-600">{s.repeat_after_days ? `${s.repeat_after_days}d` : '-'}</td><td className="px-5 py-3 text-slate-600">{s.price}</td><td className="px-5 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${s.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700'}`}>{s.is_active ? 'Active' : 'Inactive'}</span></td><td className="px-5 py-3"><div className="flex gap-2"><button className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700" onClick={() => startEdit(s)}>Edit</button><button className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700" onClick={() => router.delete(route('services.destroy', s.id))}>Deactivate</button></div></td></tr>)}</tbody>
                        </table>
                    </div>
                </section>

                {editingId && (
                    <section className="ta-card p-5">
                        <h3 className="mb-4 text-sm font-semibold text-slate-700">Edit Service #{editingId}</h3>
                        <form onSubmit={(e) => { e.preventDefault(); editForm.put(route('services.update', editingId), { onSuccess: () => setEditingId(null) }); }} className="grid gap-3 md:grid-cols-7">
                            <div><input className="ta-input" value={editForm.data.name} onChange={(e) => editForm.setData('name', e.target.value)} required />{fieldError(editForm, 'name')}</div>
                            <div><input className="ta-input" value={editForm.data.category} onChange={(e) => editForm.setData('category', e.target.value)} />{fieldError(editForm, 'category')}</div>
                            <div><input className="ta-input" type="number" min="5" value={editForm.data.duration_minutes} onChange={(e) => editForm.setData('duration_minutes', e.target.value)} required />{fieldError(editForm, 'duration_minutes')}</div>
                            <div><input className="ta-input" type="number" min="0" value={editForm.data.buffer_minutes} onChange={(e) => editForm.setData('buffer_minutes', e.target.value)} />{fieldError(editForm, 'buffer_minutes')}</div>
                            <div><input className="ta-input" type="number" min="1" value={editForm.data.repeat_after_days ?? ''} onChange={(e) => editForm.setData('repeat_after_days', e.target.value === '' ? null : e.target.value)} />{fieldError(editForm, 'repeat_after_days')}</div>
                            <div><input className="ta-input" type="number" step="0.01" min="0" value={editForm.data.price} onChange={(e) => editForm.setData('price', e.target.value)} required />{fieldError(editForm, 'price')}</div>
                            <div className="flex items-center"><label className="text-sm text-slate-600"><input type="checkbox" checked={editForm.data.is_active} onChange={(e) => editForm.setData('is_active', e.target.checked)} className="mr-2" />Active</label>{fieldError(editForm, 'is_active')}</div>
                            <div className="md:col-span-6 flex gap-2"><button className="ta-btn-primary" disabled={editForm.processing}>Save</button><button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setEditingId(null)}>Cancel</button></div>
                        </form>
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
