import ConfirmActionModal from '@/Components/ConfirmActionModal';
import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;

export default function ServicesIndex({ services }) {
    const ROWS_PER_PAGE = 10;
    const { flash } = usePage().props;
    const [editingId, setEditingId] = useState(null);
    const [deactivateId, setDeactivateId] = useState(null);
    const [deactivateBusy, setDeactivateBusy] = useState(false);
    const [searchText, setSearchText] = useState('');
    const [categoryFilter, setCategoryFilter] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [minPrice, setMinPrice] = useState('');
    const [maxPrice, setMaxPrice] = useState('');
    const [minDuration, setMinDuration] = useState('');
    const [maxDuration, setMaxDuration] = useState('');
    const [currentPage, setCurrentPage] = useState(1);

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

    const categories = useMemo(
        () => Array.from(new Set((services || []).map((service) => String(service.category || '').trim()).filter(Boolean))).sort((a, b) => a.localeCompare(b)),
        [services],
    );

    const filteredServices = useMemo(() => {
        const q = searchText.trim().toLowerCase();
        const minP = minPrice === '' ? null : Number(minPrice);
        const maxP = maxPrice === '' ? null : Number(maxPrice);
        const minD = minDuration === '' ? null : Number(minDuration);
        const maxD = maxDuration === '' ? null : Number(maxDuration);

        return (services || []).filter((service) => {
            if (q) {
                const haystack = `${service.name || ''} ${service.category || ''}`.toLowerCase();
                if (!haystack.includes(q)) return false;
            }

            if (categoryFilter && String(service.category || '').trim() !== categoryFilter) return false;
            if (statusFilter === 'active' && !service.is_active) return false;
            if (statusFilter === 'inactive' && service.is_active) return false;

            const price = Number(service.price || 0);
            const duration = Number(service.duration_minutes || 0);
            if (minP !== null && !Number.isNaN(minP) && price < minP) return false;
            if (maxP !== null && !Number.isNaN(maxP) && price > maxP) return false;
            if (minD !== null && !Number.isNaN(minD) && duration < minD) return false;
            if (maxD !== null && !Number.isNaN(maxD) && duration > maxD) return false;

            return true;
        });
    }, [services, searchText, categoryFilter, statusFilter, minPrice, maxPrice, minDuration, maxDuration]);

    const totalPages = Math.max(1, Math.ceil(filteredServices.length / ROWS_PER_PAGE));
    const pagedServices = useMemo(
        () => filteredServices.slice((currentPage - 1) * ROWS_PER_PAGE, currentPage * ROWS_PER_PAGE),
        [filteredServices, currentPage],
    );

    useEffect(() => {
        setCurrentPage(1);
    }, [searchText, categoryFilter, statusFilter, minPrice, maxPrice, minDuration, maxDuration]);

    useEffect(() => {
        if (currentPage > totalPages) {
            setCurrentPage(totalPages);
        }
    }, [currentPage, totalPages]);

    const clearFilters = () => {
        setSearchText('');
        setCategoryFilter('');
        setStatusFilter('all');
        setMinPrice('');
        setMaxPrice('');
        setMinDuration('');
        setMaxDuration('');
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
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Service Catalog</h3></div>
                    <div className="grid gap-3 border-b border-slate-200 bg-slate-50 px-5 py-4 md:grid-cols-6">
                        <div className="md:col-span-2">
                            <label className="ta-field-label">Search</label>
                            <input className="ta-input" placeholder="Service name or category" value={searchText} onChange={(e) => setSearchText(e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Category</label>
                            <select className="ta-input" value={categoryFilter} onChange={(e) => setCategoryFilter(e.target.value)}>
                                <option value="">All categories</option>
                                {categories.map((category) => <option key={category} value={category}>{category}</option>)}
                            </select>
                        </div>
                        <div>
                            <label className="ta-field-label">Status</label>
                            <select className="ta-input" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
                                <option value="all">All services</option>
                                <option value="active">Active only</option>
                                <option value="inactive">Inactive only</option>
                            </select>
                        </div>
                        <div>
                            <label className="ta-field-label">Min price</label>
                            <input className="ta-input" type="number" min="0" step="0.01" value={minPrice} onChange={(e) => setMinPrice(e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Max price</label>
                            <input className="ta-input" type="number" min="0" step="0.01" value={maxPrice} onChange={(e) => setMaxPrice(e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Min duration</label>
                            <input className="ta-input" type="number" min="0" value={minDuration} onChange={(e) => setMinDuration(e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Max duration</label>
                            <input className="ta-input" type="number" min="0" value={maxDuration} onChange={(e) => setMaxDuration(e.target.value)} />
                        </div>
                        <div className="md:col-span-6 flex items-center justify-between">
                            <p className="text-xs text-slate-500">Showing {filteredServices.length} of {(services || []).length} services</p>
                            <button type="button" className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700" onClick={clearFilters}>Reset filters</button>
                        </div>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Name</th><th className="px-5 py-3">Category</th><th className="px-5 py-3">Duration</th><th className="px-5 py-3">Buffer</th><th className="px-5 py-3">Repeat</th><th className="px-5 py-3">Price</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Actions</th></tr></thead>
                            <tbody>{pagedServices.map((s) => <tr key={s.id} className="border-t border-slate-100"><td className="px-5 py-3 font-medium text-slate-700">{s.name}</td><td className="px-5 py-3 text-slate-600">{s.category || '-'}</td><td className="px-5 py-3 text-slate-600">{s.duration_minutes}m</td><td className="px-5 py-3 text-slate-600">{s.buffer_minutes}m</td><td className="px-5 py-3 text-slate-600">{s.repeat_after_days ? `${s.repeat_after_days}d` : '-'}</td><td className="px-5 py-3 text-slate-600">{s.price}</td><td className="px-5 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${s.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700'}`}>{s.is_active ? 'Active' : 'Inactive'}</span></td><td className="px-5 py-3"><div className="flex gap-2"><button type="button" className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700" onClick={() => startEdit(s)}>Edit</button><button type="button" className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700" onClick={() => setDeactivateId(s.id)}>Delete</button></div></td></tr>)}
                            {filteredServices.length === 0 && <tr><td className="px-5 py-3 text-slate-500" colSpan="8">No services match the selected filters.</td></tr>}
                            </tbody>
                        </table>
                    </div>
                    <div className="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-xs text-slate-600">
                        <span>Page {currentPage} of {totalPages}</span>
                        <div className="flex gap-2">
                            <button type="button" className="rounded-lg border border-slate-200 px-2 py-1 disabled:opacity-50" disabled={currentPage <= 1} onClick={() => setCurrentPage((prev) => Math.max(1, prev - 1))}>Previous</button>
                            <button type="button" className="rounded-lg border border-slate-200 px-2 py-1 disabled:opacity-50" disabled={currentPage >= totalPages} onClick={() => setCurrentPage((prev) => Math.min(totalPages, prev + 1))}>Next</button>
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





