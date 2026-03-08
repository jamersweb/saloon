import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';

const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;

export default function CustomersIndex({ customers, selectedCustomer, history, filters }) {
    const { flash } = usePage().props;

    const createForm = useForm({ name: '', phone: '', email: '', birthday: '', allergies: '', notes: '', acquisition_source: '' });
    const editForm = useForm({
        name: selectedCustomer?.name || '',
        phone: selectedCustomer?.phone || '',
        email: selectedCustomer?.email || '',
        birthday: selectedCustomer?.birthday || '',
        allergies: selectedCustomer?.allergies || '',
        notes: selectedCustomer?.notes || '',
        acquisition_source: selectedCustomer?.acquisition_source || '',
    });

    const search = (q) => router.get(route('customers.index'), { q }, { preserveState: true, replace: true });
    const openCustomer = (id) => router.get(route('customers.index'), { q: filters?.q || '', customer_id: id }, { preserveState: true });

    return (
        <AuthenticatedLayout header="Customer CRM">
            <Head title="Customers" />

            <div className="space-y-6 lg:grid lg:grid-cols-3 lg:gap-6 lg:space-y-0">
                <section className="ta-card p-5">
                    {flash?.status && <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                    <input className="ta-input mb-4" defaultValue={filters?.q || ''} placeholder="Search name / phone / code" onChange={(e) => search(e.target.value)} />

                    <div className="mb-5 max-h-72 overflow-auto rounded-xl border border-slate-200">
                        {customers.map((c) => (
                            <button key={c.id} className={`block w-full border-b border-slate-100 p-3 text-left text-sm ${selectedCustomer?.id === c.id ? 'bg-indigo-50' : 'hover:bg-slate-50'}`} onClick={() => openCustomer(c.id)}>
                                <div className="font-semibold text-slate-700">{c.name}</div>
                                <div className="text-xs text-slate-500">{c.phone} {c.customer_code ? `• ${c.customer_code}` : ''}</div>
                            </button>
                        ))}
                    </div>

                    <h3 className="mb-3 text-sm font-semibold text-slate-700">Create Customer</h3>
                    <form className="space-y-2" onSubmit={(e) => { e.preventDefault(); createForm.post(route('customers.store'), { onSuccess: () => createForm.reset() }); }}>
                        <div><label className="ta-field-label">Name</label><input className="ta-input" placeholder="Name" value={createForm.data.name} onChange={(e) => createForm.setData('name', e.target.value)} required />{fieldError(createForm, 'name')}</div>
                        <div><label className="ta-field-label">Phone</label><input className="ta-input" placeholder="Phone" value={createForm.data.phone} onChange={(e) => createForm.setData('phone', e.target.value)} required />{fieldError(createForm, 'phone')}</div>
                        <div><label className="ta-field-label">Email</label><input className="ta-input" placeholder="Email" value={createForm.data.email} onChange={(e) => createForm.setData('email', e.target.value)} />{fieldError(createForm, 'email')}</div>
                        <button className="ta-btn-primary w-full" disabled={createForm.processing}>Create</button>
                    </form>
                </section>

                <section className="ta-card p-5 lg:col-span-2">
                    {!selectedCustomer && <p className="text-sm text-slate-500">Select a customer to view profile and visit history.</p>}

                    {selectedCustomer && (
                        <>
                            <div className="mb-4 border-b border-slate-100 pb-4">
                                <h3 className="text-lg font-semibold text-slate-800">{selectedCustomer.name}</h3>
                                <p className="text-sm text-slate-500">{selectedCustomer.customer_code || 'No code'} • {selectedCustomer.phone}</p>
                            </div>

                            <form className="grid gap-3 md:grid-cols-2" onSubmit={(e) => { e.preventDefault(); editForm.put(route('customers.update', selectedCustomer.id)); }}>
                                <div><label className="ta-field-label">Name</label><input className="ta-input" value={editForm.data.name} onChange={(e) => editForm.setData('name', e.target.value)} required />{fieldError(editForm, 'name')}</div>
                                <div><label className="ta-field-label">Phone</label><input className="ta-input" value={editForm.data.phone} onChange={(e) => editForm.setData('phone', e.target.value)} required />{fieldError(editForm, 'phone')}</div>
                                <div><label className="ta-field-label">Email</label><input className="ta-input" value={editForm.data.email} onChange={(e) => editForm.setData('email', e.target.value)} placeholder="Email" />{fieldError(editForm, 'email')}</div>
                                <div><label className="ta-field-label">Birthday</label><input className="ta-input" type="date" value={editForm.data.birthday || ''} onChange={(e) => editForm.setData('birthday', e.target.value)} />{fieldError(editForm, 'birthday')}</div>
                                <div className="md:col-span-2"><label className="ta-field-label">Acquisition Source</label><input className="ta-input" value={editForm.data.acquisition_source || ''} onChange={(e) => editForm.setData('acquisition_source', e.target.value)} placeholder="Acquisition source" />{fieldError(editForm, 'acquisition_source')}</div>
                                <div className="md:col-span-2"><textarea className="ta-input min-h-[90px]" value={editForm.data.allergies || ''} onChange={(e) => editForm.setData('allergies', e.target.value)} placeholder="Allergies / sensitivities" />{fieldError(editForm, 'allergies')}</div>
                                <div className="md:col-span-2"><textarea className="ta-input min-h-[90px]" value={editForm.data.notes || ''} onChange={(e) => editForm.setData('notes', e.target.value)} placeholder="Notes" />{fieldError(editForm, 'notes')}</div>
                                <button className="ta-btn-primary md:col-span-2" disabled={editForm.processing}>Save Profile</button>
                            </form>

                            <div className="mt-6 overflow-hidden rounded-xl border border-slate-200">
                                <div className="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700">Visit History</div>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full text-sm">
                                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                            <tr><th className="px-4 py-2">Date</th><th className="px-4 py-2">Service</th><th className="px-4 py-2">Staff</th><th className="px-4 py-2">Status</th><th className="px-4 py-2">Notes</th></tr>
                                        </thead>
                                        <tbody>
                                            {history.length === 0 && <tr><td className="px-4 py-3 text-slate-500" colSpan="5">No visit history.</td></tr>}
                                            {history.map((h) => (
                                                <tr key={h.id} className="border-t border-slate-100"><td className="px-4 py-2">{new Date(h.scheduled_start).toLocaleString()}</td><td className="px-4 py-2">{h.service_name || 'N/A'}</td><td className="px-4 py-2">{h.staff_name || 'N/A'}</td><td className="px-4 py-2">{h.status}</td><td className="px-4 py-2">{h.notes || '-'}</td></tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </>
                    )}
                </section>
            </div>
        </AuthenticatedLayout>
    );
}










