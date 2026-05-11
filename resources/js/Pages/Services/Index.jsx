import ConfirmActionModal from '@/Components/ConfirmActionModal';
import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;

export default function ServicesIndex({ services, filters, categories = [] }) {
    const { flash, app_currency_code: currencyCode = 'AED' } = usePage().props;
    const [editingId, setEditingId] = useState(null);
    const [deactivateId, setDeactivateId] = useState(null);
    const [deactivateBusy, setDeactivateBusy] = useState(false);
    const filterForm = useForm({
        search: filters?.search || '',
        category: filters?.category || '',
        status: filters?.status || 'all',
        min_price: filters?.min_price ?? '',
        max_price: filters?.max_price ?? '',
        min_duration: filters?.min_duration ?? '',
        max_duration: filters?.max_duration ?? '',
        per_page: String(filters?.per_page || 10),
    });

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

    const closeEditModal = () => {
        setEditingId(null);
        editForm.clearErrors();
    };

    useEffect(() => {
        filterForm.setData({
            search: filters?.search || '',
            category: filters?.category || '',
            status: filters?.status || 'all',
            min_price: filters?.min_price ?? '',
            max_price: filters?.max_price ?? '',
            min_duration: filters?.min_duration ?? '',
            max_duration: filters?.max_duration ?? '',
            per_page: String(filters?.per_page || 10),
        });
    }, [filters?.search, filters?.category, filters?.status, filters?.min_price, filters?.max_price, filters?.min_duration, filters?.max_duration, filters?.per_page]);

    const applyFilters = () => {
        router.get(route('services.index'), {
            search: filterForm.data.search || undefined,
            category: filterForm.data.category || undefined,
            status: filterForm.data.status,
            min_price: filterForm.data.min_price || undefined,
            max_price: filterForm.data.max_price || undefined,
            min_duration: filterForm.data.min_duration || undefined,
            max_duration: filterForm.data.max_duration || undefined,
            per_page: filterForm.data.per_page,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const clearFilters = () => {
        const defaults = {
            search: '',
            category: '',
            status: 'all',
            min_price: '',
            max_price: '',
            min_duration: '',
            max_duration: '',
            per_page: '10',
        };

        filterForm.setData(defaults);

        router.get(route('services.index'), { status: 'all', per_page: 10 }, {
            preserveState: true,
            replace: true,
        });
    };

    return (
        <AuthenticatedLayout header="Services">
            <Head title="Services" />
            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Create Service</h3>
                    <form onSubmit={(e) => { e.preventDefault(); createForm.post(route('services.store'), { onSuccess: () => createForm.reset() }); }} className="grid gap-3 md:grid-cols-7">
                        <div><label className="ta-field-label">Service Name</label><input className="ta-input" placeholder="Service name" value={createForm.data.name} onChange={(e) => createForm.setData('name', e.target.value)} required />{fieldError(createForm, 'name')}</div>
                        <div><label className="ta-field-label">Category</label><input className="ta-input" placeholder="Category" value={createForm.data.category} onChange={(e) => createForm.setData('category', e.target.value)} />{fieldError(createForm, 'category')}</div>
                        <div><label className="ta-field-label">Duration</label><input className="ta-input" type="number" min="5" placeholder="Duration" value={createForm.data.duration_minutes} onChange={(e) => createForm.setData('duration_minutes', e.target.value)} required />{fieldError(createForm, 'duration_minutes')}</div>
                        <div><label className="ta-field-label">Buffer</label><input className="ta-input" type="number" min="0" placeholder="Buffer" value={createForm.data.buffer_minutes} onChange={(e) => createForm.setData('buffer_minutes', e.target.value)} />{fieldError(createForm, 'buffer_minutes')}</div>
                        <div><label className="ta-field-label">Repeat Days</label><input className="ta-input" type="number" min="1" placeholder="Repeat days" value={createForm.data.repeat_after_days ?? ''} onChange={(e) => createForm.setData('repeat_after_days', e.target.value === '' ? null : e.target.value)} />{fieldError(createForm, 'repeat_after_days')}</div>
                        <div><label className="ta-field-label">Price</label><input className="ta-input" type="number" step="0.01" min="0" placeholder="Price" value={createForm.data.price} onChange={(e) => createForm.setData('price', e.target.value)} required />{fieldError(createForm, 'price')}</div>
                        <button className="ta-btn-primary" disabled={createForm.processing}>Add</button>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4">
                        <h3 className="text-sm font-semibold text-slate-700">Service Catalog</h3>
                        <p className="mt-1 text-xs text-slate-500">Showing {services?.from || 0}-{services?.to || 0} of {services?.total || 0} services</p>
                    </div>
                    <div className="grid gap-3 border-b border-slate-200 bg-slate-50 px-5 py-4 sm:grid-cols-2 xl:grid-cols-6">
                        <div className="sm:col-span-2 xl:col-span-2">
                            <label className="ta-field-label">Search</label>
                            <input className="ta-input w-full min-w-0" placeholder="Service name or category" value={filterForm.data.search} onChange={(e) => filterForm.setData('search', e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Category</label>
                            <select className="ta-input w-full min-w-0" value={filterForm.data.category} onChange={(e) => filterForm.setData('category', e.target.value)}>
                                <option value="">All categories</option>
                                {categories.map((category) => <option key={category} value={category}>{category}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="ta-field-label">Status</label>
                            <select className="ta-input w-full min-w-0" value={filterForm.data.status} onChange={(e) => filterForm.setData('status', e.target.value)}>
                                <option value="all">All services</option>
                                <option value="active">Active only</option>
                                <option value="inactive">Inactive only</option>
                            </select>
                        </div>
                        <div>
                            <label className="ta-field-label">Min price</label>
                            <input className="ta-input w-full min-w-0" type="number" min="0" step="0.01" value={filterForm.data.min_price} onChange={(e) => filterForm.setData('min_price', e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Max price</label>
                            <input className="ta-input w-full min-w-0" type="number" min="0" step="0.01" value={filterForm.data.max_price} onChange={(e) => filterForm.setData('max_price', e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Min duration</label>
                            <input className="ta-input w-full min-w-0" type="number" min="0" value={filterForm.data.min_duration} onChange={(e) => filterForm.setData('min_duration', e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Max duration</label>
                            <input className="ta-input w-full min-w-0" type="number" min="0" value={filterForm.data.max_duration} onChange={(e) => filterForm.setData('max_duration', e.target.value)} />
                        </div>
                        <div className="sm:col-span-2 xl:col-span-6">
                            <div className="grid gap-3 sm:flex sm:flex-wrap sm:items-center">
                                <select className="ta-input w-full min-w-0 sm:max-w-[140px]" value={filterForm.data.per_page} onChange={(e) => filterForm.setData('per_page', e.target.value)}>
                                    {[10, 25, 50, 100].map((size) => <option key={size} value={size}>{size} / page</option>)}
                                </select>
                                <button type="button" className="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs text-slate-700 sm:w-auto" onClick={applyFilters}>Apply Filters</button>
                                <button type="button" className="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs text-slate-700 sm:w-auto" onClick={clearFilters}>Reset filters</button>
                            </div>
                        </div>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Name</th><th className="px-5 py-3">Category</th><th className="px-5 py-3">Duration</th><th className="px-5 py-3">Buffer</th><th className="px-5 py-3">Repeat</th><th className="px-5 py-3">Price</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Actions</th></tr></thead>
                            <tbody>{(services?.data || []).map((s) => <tr key={s.id} className="border-t border-slate-100"><td className="px-5 py-3 font-medium text-slate-700">{s.name}</td><td className="px-5 py-3 text-slate-600">{s.category || '-'}</td><td className="px-5 py-3 text-slate-600">{s.duration_minutes}m</td><td className="px-5 py-3 text-slate-600">{s.buffer_minutes}m</td><td className="px-5 py-3 text-slate-600">{s.repeat_after_days ? `${s.repeat_after_days}d` : '-'}</td><td className="px-5 py-3 text-slate-600">{new Intl.NumberFormat(undefined, { style: 'currency', currency: currencyCode }).format(Number(s.price || 0))}</td><td className="px-5 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${s.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700'}`}>{s.is_active ? 'Active' : 'Inactive'}</span></td><td className="px-5 py-3"><div className="flex gap-2"><button type="button" className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700" onClick={() => startEdit(s)}>Edit</button><button type="button" className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700" onClick={() => setDeactivateId(s.id)}>Delete</button></div></td></tr>)}
                            {(services?.data || []).length === 0 && <tr><td className="px-5 py-3 text-slate-500" colSpan="8">No services match the selected filters.</td></tr>}
                            </tbody>
                        </table>
                    </div>
                    <div className="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-xs text-slate-600">
                        <span>Page {services?.current_page || 1} of {services?.last_page || 1}</span>
                        <div className="flex flex-wrap gap-2">
                            {(services?.links || []).map((link) => (
                                <Link
                                    key={`${link.label}-${link.url || 'null'}`}
                                    href={link.url || '#'}
                                    preserveState
                                    className={`rounded-lg border px-3 py-1 ${link.active ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-600'} ${!link.url ? 'pointer-events-none opacity-50' : 'hover:bg-slate-50'}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    </div>
                </section>

                <Modal show={Boolean(editingId)} onClose={closeEditModal} maxWidth="2xl">
                    <div className="p-6">
                        <h3 className="mb-4 text-base font-semibold text-slate-800">Edit service #{editingId}</h3>
                        <form onSubmit={(e) => { e.preventDefault(); editForm.put(route('services.update', editingId), { onSuccess: () => closeEditModal() }); }} className="grid gap-3 md:grid-cols-7">
                            <div><label className="ta-field-label">Name</label><input className="ta-input" value={editForm.data.name} onChange={(e) => editForm.setData('name', e.target.value)} required />{fieldError(editForm, 'name')}</div>
                            <div><label className="ta-field-label">Category</label><input className="ta-input" value={editForm.data.category} onChange={(e) => editForm.setData('category', e.target.value)} />{fieldError(editForm, 'category')}</div>
                            <div><label className="ta-field-label">Duration Minutes</label><input className="ta-input" type="number" min="5" value={editForm.data.duration_minutes} onChange={(e) => editForm.setData('duration_minutes', e.target.value)} required />{fieldError(editForm, 'duration_minutes')}</div>
                            <div><label className="ta-field-label">Buffer Minutes</label><input className="ta-input" type="number" min="0" value={editForm.data.buffer_minutes} onChange={(e) => editForm.setData('buffer_minutes', e.target.value)} />{fieldError(editForm, 'buffer_minutes')}</div>
                            <div><label className="ta-field-label">Repeat After Days</label><input className="ta-input" type="number" min="1" value={editForm.data.repeat_after_days ?? ''} onChange={(e) => editForm.setData('repeat_after_days', e.target.value === '' ? null : e.target.value)} />{fieldError(editForm, 'repeat_after_days')}</div>
                            <div><label className="ta-field-label">Price</label><input className="ta-input" type="number" step="0.01" min="0" value={editForm.data.price} onChange={(e) => editForm.setData('price', e.target.value)} required />{fieldError(editForm, 'price')}</div>
                            <div className="flex items-center"><label className="text-sm text-slate-600"><input type="checkbox" checked={editForm.data.is_active} onChange={(e) => editForm.setData('is_active', e.target.checked)} className="mr-2" />Active</label>{fieldError(editForm, 'is_active')}</div>
                            <div className="md:col-span-6 flex gap-2"><button className="ta-btn-primary" disabled={editForm.processing}>Save</button><button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={closeEditModal}>Close</button></div>
                        </form>
                    </div>
                </Modal>

                <ConfirmActionModal
                    show={Boolean(deactivateId)}
                    title="Delete this service?"
                    message="The service will be hidden from new bookings. Existing data is kept."
                    confirmText="Delete"
                    onClose={() => !deactivateBusy && setDeactivateId(null)}
                    processing={deactivateBusy}
                    onConfirm={() => {
                        if (!deactivateId) return;
                        setDeactivateBusy(true);
                        router.delete(route('services.destroy', deactivateId), {
                            onFinish: () => {
                                setDeactivateBusy(false);
                                setDeactivateId(null);
                            },
                        });
                    }}
                />
            </div>
        </AuthenticatedLayout>
    );
}


