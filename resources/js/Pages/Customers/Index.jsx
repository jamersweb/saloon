import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;
const formatDate = (value) => value ? new Date(value).toLocaleDateString() : 'N/A';
const formatDateTime = (value) => value ? new Date(value).toLocaleString() : 'N/A';
const formatCurrency = (value) => value === null || value === undefined ? 'N/A' : new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(Number(value));

export default function CustomersIndex({ customers, selectedCustomer, history, filters, acquisitionSources }) {
    const { flash } = usePage().props;
    const importFileRef = useRef(null);

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
    const portalForm = useForm({});

    useEffect(() => {
        editForm.setData({
            name: selectedCustomer?.name || '',
            phone: selectedCustomer?.phone || '',
            email: selectedCustomer?.email || '',
            birthday: selectedCustomer?.birthday || '',
            allergies: selectedCustomer?.allergies || '',
            notes: selectedCustomer?.notes || '',
            acquisition_source: selectedCustomer?.acquisition_source || '',
        });
    }, [selectedCustomer?.id]);

    const search = (q) => router.get(route('customers.index'), { q }, { preserveState: true, replace: true });
    const openCustomer = (id) => router.get(route('customers.index'), { q: filters?.q || '', customer_id: id }, { preserveState: true });
    const handleCustomersImport = (event) => {
        const file = event.target.files?.[0];
        if (!file) return;
        router.post(route('data-transfer.import', { entity: 'customers' }), { csv_file: file }, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                if (importFileRef.current) importFileRef.current.value = '';
            },
        });
    };

    return (
        <AuthenticatedLayout header="Customer CRM">
            <Head title="Customers" />

            <div className="space-y-6 lg:grid lg:grid-cols-3 lg:gap-6 lg:space-y-0">
                <section className="ta-card p-5">
                    {flash?.status && <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}
                    <div className="mb-4 flex items-center gap-2">
                        <input ref={importFileRef} type="file" accept=".csv,text/csv" className="hidden" onChange={handleCustomersImport} />
                        <button type="button" className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700" onClick={() => importFileRef.current?.click()}>Import CSV</button>
                        <button type="button" className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700" onClick={() => { window.location.href = route('data-transfer.template', { entity: 'customers' }); }}>Template CSV</button>
                        <button type="button" className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700" onClick={() => { window.location.href = route('data-transfer.export', { entity: 'customers' }); }}>Export CSV</button>
                    </div>

                    <input className="ta-input mb-4" defaultValue={filters?.q || ''} placeholder="Search name / phone / code" onChange={(e) => search(e.target.value)} />

                    <div className="mb-5 max-h-72 overflow-auto rounded-xl border border-slate-200">
                        {customers.map((c) => (
                            <button key={c.id} className={`block w-full border-b border-slate-100 p-3 text-left text-sm ${selectedCustomer?.id === c.id ? 'bg-indigo-50' : 'hover:bg-slate-50'}`} onClick={() => openCustomer(c.id)} type="button">
                                <div className="font-semibold text-slate-700">{c.name}</div>
                                <div className="text-xs text-slate-500">{c.phone} {c.customer_code ? `| ${c.customer_code}` : ''}</div>
                                <div className="mt-1 text-xs text-slate-400">Points: {c.points} | Card: {c.current_card || 'None'}</div>
                            </button>
                        ))}
                    </div>

                    <h3 className="mb-3 text-sm font-semibold text-slate-700">Create Customer</h3>
                    <form className="space-y-2" onSubmit={(e) => { e.preventDefault(); createForm.post(route('customers.store'), { onSuccess: () => createForm.reset() }); }}>
                        <div><label className="ta-field-label">Name</label><input className="ta-input" placeholder="Name" value={createForm.data.name} onChange={(e) => createForm.setData('name', e.target.value)} required />{fieldError(createForm, 'name')}</div>
                        <div><label className="ta-field-label">Phone</label><input className="ta-input" placeholder="Phone" value={createForm.data.phone} onChange={(e) => createForm.setData('phone', e.target.value)} required />{fieldError(createForm, 'phone')}</div>
                        <div><label className="ta-field-label">Email</label><input className="ta-input" placeholder="Email" value={createForm.data.email} onChange={(e) => createForm.setData('email', e.target.value)} />{fieldError(createForm, 'email')}</div>
                        <div>
                            <label className="ta-field-label">How They Found Us</label>
                            <select className="ta-input" value={createForm.data.acquisition_source} onChange={(e) => createForm.setData('acquisition_source', e.target.value)}>
                                <option value="">Select source</option>
                                {acquisitionSources.map((source) => <option key={source} value={source}>{source}</option>)}
                            </select>
                            {fieldError(createForm, 'acquisition_source')}
                        </div>
                        <button className="ta-btn-primary w-full" disabled={createForm.processing}>Create</button>
                    </form>
                </section>

                <section className="ta-card p-5 lg:col-span-2">
                    {!selectedCustomer && <p className="text-sm text-slate-500">Select a customer to view profile, portal, wallet, and visit history.</p>}

                    {selectedCustomer && (
                        <>
                            <div className="mb-4 border-b border-slate-100 pb-4">
                                <h3 className="text-lg font-semibold text-slate-800">{selectedCustomer.name}</h3>
                                <p className="text-sm text-slate-500">{selectedCustomer.customer_code || 'No code'} | {selectedCustomer.phone}</p>
                            </div>

                            <div className="mb-6 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Loyalty</div>
                                    <div className="mt-2 text-2xl font-semibold text-slate-800">{selectedCustomer.points}</div>
                                    <p className="text-sm text-slate-500">{selectedCustomer.tier || 'No tier assigned'}</p>
                                </div>
                                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Membership Card</div>
                                    <div className="mt-2 text-lg font-semibold text-slate-800">{selectedCustomer.current_card || 'No active card'}</div>
                                    <p className="text-sm text-slate-500">{selectedCustomer.card_status || 'Not assigned'}</p>
                                    <p className="mt-1 text-xs text-slate-400">Expires: {formatDate(selectedCustomer.card_expires_at)}</p>
                                </div>
                                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Active Packages</div>
                                    <div className="mt-2 text-2xl font-semibold text-slate-800">{selectedCustomer.active_packages?.length || 0}</div>
                                    <p className="text-sm text-slate-500">Prepaid sessions and package value.</p>
                                </div>
                                <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                    <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Gift Cards</div>
                                    <div className="mt-2 text-2xl font-semibold text-slate-800">{selectedCustomer.gift_cards?.length || 0}</div>
                                    <p className="text-sm text-slate-500">Stored balances for future visits.</p>
                                </div>
                            </div>

                            <div className="mb-6 rounded-xl border border-slate-200 bg-slate-50 p-4">
                                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                    <div>
                                        <h4 className="text-sm font-semibold text-slate-700">Customer Portal</h4>
                                        <p className="text-sm text-slate-500">Issue a private customer link with loyalty, package, gift card, and visit summary access.</p>
                                    </div>
                                    <button
                                        className="ta-btn-primary"
                                        disabled={portalForm.processing}
                                        onClick={() => portalForm.post(route('customers.portal-token.store', selectedCustomer.id))}
                                        type="button"
                                    >
                                        {selectedCustomer.portal_url ? 'Refresh Portal Link' : 'Generate Portal Link'}
                                    </button>
                                </div>
                                <div className="mt-4 rounded-lg border border-dashed border-slate-300 bg-white p-3 text-sm">
                                    {selectedCustomer.portal_url ? (
                                        <>
                                            <p className="font-medium text-slate-700">Active portal link</p>
                                            <a className="mt-1 block break-all text-indigo-600 hover:text-indigo-700" href={selectedCustomer.portal_url} rel="noreferrer" target="_blank">
                                                {selectedCustomer.portal_url}
                                            </a>
                                            <p className="mt-1 text-xs text-slate-500">Expires: {formatDateTime(selectedCustomer.portal_expires_at)}</p>
                                        </>
                                    ) : (
                                        <p className="text-slate-500">No portal link issued yet.</p>
                                    )}
                                </div>
                            </div>

                            <form className="grid gap-3 md:grid-cols-2" onSubmit={(e) => { e.preventDefault(); editForm.put(route('customers.update', selectedCustomer.id)); }}>
                                <div><label className="ta-field-label">Name</label><input className="ta-input" value={editForm.data.name} onChange={(e) => editForm.setData('name', e.target.value)} required />{fieldError(editForm, 'name')}</div>
                                <div><label className="ta-field-label">Phone</label><input className="ta-input" value={editForm.data.phone} onChange={(e) => editForm.setData('phone', e.target.value)} required />{fieldError(editForm, 'phone')}</div>
                                <div><label className="ta-field-label">Email</label><input className="ta-input" value={editForm.data.email} onChange={(e) => editForm.setData('email', e.target.value)} placeholder="Email" />{fieldError(editForm, 'email')}</div>
                                <div><label className="ta-field-label">Birthday</label><input className="ta-input" type="date" value={editForm.data.birthday || ''} onChange={(e) => editForm.setData('birthday', e.target.value)} />{fieldError(editForm, 'birthday')}</div>
                                <div className="md:col-span-2">
                                    <label className="ta-field-label">How They Found Us</label>
                                    <select className="ta-input" value={editForm.data.acquisition_source || ''} onChange={(e) => editForm.setData('acquisition_source', e.target.value)}>
                                        <option value="">Select source</option>
                                        {acquisitionSources.map((source) => <option key={source} value={source}>{source}</option>)}
                                    </select>
                                    {fieldError(editForm, 'acquisition_source')}
                                </div>
                                <div className="md:col-span-2"><textarea className="ta-input min-h-[90px]" value={editForm.data.allergies || ''} onChange={(e) => editForm.setData('allergies', e.target.value)} placeholder="Allergies / sensitivities" />{fieldError(editForm, 'allergies')}</div>
                                <div className="md:col-span-2"><textarea className="ta-input min-h-[90px]" value={editForm.data.notes || ''} onChange={(e) => editForm.setData('notes', e.target.value)} placeholder="Notes" />{fieldError(editForm, 'notes')}</div>
                                <div className="md:col-span-2 flex gap-2">
                                    <button className="ta-btn-primary" disabled={editForm.processing}>Save Profile</button>
                                    <button
                                        type="button"
                                        className="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm font-semibold text-red-700"
                                        onClick={() => {
                                            if (!selectedCustomer?.id) return;
                                            if (!window.confirm('Delete this customer? This will hide them from customer lists.')) return;
                                            router.delete(route('customers.destroy', selectedCustomer.id));
                                        }}
                                    >
                                        Delete Customer
                                    </button>
                                </div>
                            </form>

                            <div className="mt-6 grid gap-4 lg:grid-cols-2">
                                <div className="overflow-hidden rounded-xl border border-slate-200">
                                    <div className="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700">Package Balances</div>
                                    <div className="divide-y divide-slate-100">
                                        {(selectedCustomer.active_packages || []).length === 0 && <div className="px-4 py-3 text-sm text-slate-500">No active packages.</div>}
                                        {(selectedCustomer.active_packages || []).map((pkg, index) => (
                                            <div key={`${pkg.name}-${index}`} className="px-4 py-3 text-sm">
                                                <div className="font-medium text-slate-700">{pkg.name || 'Unnamed package'}</div>
                                                <div className="mt-1 text-slate-500">Sessions: {pkg.remaining_sessions ?? 'N/A'} | Value: {formatCurrency(pkg.remaining_value)}</div>
                                                <div className="mt-1 text-xs text-slate-400">Expires: {formatDate(pkg.expires_at)}</div>
                                            </div>
                                        ))}
                                    </div>
                                </div>

                                <div className="overflow-hidden rounded-xl border border-slate-200">
                                    <div className="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700">Gift Cards</div>
                                    <div className="divide-y divide-slate-100">
                                        {(selectedCustomer.gift_cards || []).length === 0 && <div className="px-4 py-3 text-sm text-slate-500">No gift cards assigned.</div>}
                                        {(selectedCustomer.gift_cards || []).map((giftCard) => (
                                            <div key={giftCard.code} className="px-4 py-3 text-sm">
                                                <div className="font-medium text-slate-700">{giftCard.code}</div>
                                                <div className="mt-1 text-slate-500">Remaining: {formatCurrency(giftCard.remaining_value)} | Status: {giftCard.status}</div>
                                                <div className="mt-1 text-xs text-slate-400">Expires: {formatDate(giftCard.expires_at)}</div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>

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
