import ConfirmActionModal from '@/Components/ConfirmActionModal';
import Modal from '@/Components/Modal';
import SearchableSelect from '@/Components/SearchableSelect';
import TablePagination from '@/Components/TablePagination';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;
const money = (value, currency = 'AED') => new Intl.NumberFormat(undefined, { style: 'currency', currency, minimumFractionDigits: 2 }).format(Number(value || 0));
const ROWS_PER_PAGE = 10;
const matchesSearch = (values, term) => values.filter(Boolean).join(' ').toLowerCase().includes(term);

export default function RentalsIndex({ agreements, settlements, customers, cost_centers, agreement_types, rental_models }) {
    const { flash, auth, app_currency_code: currencyCode = 'AED' } = usePage().props;
    const customerOptions = useMemo(() => ([
        { value: '', label: 'Manual partner only' },
        ...customers.map((customer) => ({ value: String(customer.id), label: `${customer.name}${customer.phone ? ` - ${customer.phone}` : ''}` })),
    ]), [customers]);

    const createForm = useForm({
        customer_id: '',
        partner_name: '',
        agreement_type: 'line',
        cost_center: 'permanent_makeup_rental',
        rental_model: 'fixed',
        fixed_rent_amount: '',
        commission_percent: '',
        start_date: new Date().toISOString().slice(0, 10),
        end_date: '',
        is_active: true,
        notes: '',
    });
    const editForm = useForm({
        customer_id: '',
        partner_name: '',
        agreement_type: 'line',
        cost_center: 'permanent_makeup_rental',
        rental_model: 'fixed',
        fixed_rent_amount: '',
        commission_percent: '',
        start_date: '',
        end_date: '',
        is_active: true,
        notes: '',
    });
    const settleForm = useForm({
        settlement_date: new Date().toISOString().slice(0, 10),
        gross_sales_amount: '',
        fixed_rent_amount: '',
        notes: '',
    });

    const [editingAgreement, setEditingAgreement] = useState(null);
    const [settlingAgreement, setSettlingAgreement] = useState(null);
    const [deactivateAgreementId, setDeactivateAgreementId] = useState(null);
    const [deactivateBusy, setDeactivateBusy] = useState(false);
    const [agreementSearch, setAgreementSearch] = useState('');
    const [agreementStatusFilter, setAgreementStatusFilter] = useState('');
    const [agreementPage, setAgreementPage] = useState(1);
    const [settlementSearch, setSettlementSearch] = useState('');
    const [settlementCostCenterFilter, setSettlementCostCenterFilter] = useState('');
    const [settlementPage, setSettlementPage] = useState(1);

    const startEdit = (agreement) => {
        setEditingAgreement(agreement);
        editForm.setData({
            customer_id: agreement.customer_id ? String(agreement.customer_id) : '',
            partner_name: agreement.partner_name,
            agreement_type: agreement.agreement_type,
            cost_center: agreement.cost_center,
            rental_model: agreement.rental_model,
            fixed_rent_amount: String(agreement.fixed_rent_amount ?? ''),
            commission_percent: agreement.commission_percent ?? '',
            start_date: agreement.start_date || '',
            end_date: agreement.end_date || '',
            is_active: Boolean(agreement.is_active),
            notes: agreement.notes || '',
        });
        editForm.clearErrors();
    };

    const startSettlement = (agreement) => {
        setSettlingAgreement(agreement);
        settleForm.setData({
            settlement_date: new Date().toISOString().slice(0, 10),
            gross_sales_amount: '',
            fixed_rent_amount: String(agreement.fixed_rent_amount ?? ''),
            notes: '',
        });
        settleForm.clearErrors();
    };
    const filteredAgreements = useMemo(() => {
        const term = agreementSearch.trim().toLowerCase();

        return (agreements || []).filter((agreement) => {
            if (agreementStatusFilter === 'active' && !agreement.is_active) {
                return false;
            }

            if (agreementStatusFilter === 'inactive' && agreement.is_active) {
                return false;
            }

            if (!term) {
                return true;
            }

            return matchesSearch([
                agreement.partner_name,
                agreement.customer_name,
                agreement_types[agreement.agreement_type] || agreement.agreement_type,
                cost_centers[agreement.cost_center] || agreement.cost_center,
                rental_models[agreement.rental_model] || agreement.rental_model,
                agreement.fixed_rent_amount,
                agreement.commission_percent,
                agreement.start_date,
                agreement.end_date,
                agreement.is_active ? 'active' : 'inactive',
            ], term);
        });
    }, [agreementSearch, agreementStatusFilter, agreement_types, agreements, cost_centers, rental_models]);
    const filteredSettlements = useMemo(() => {
        const term = settlementSearch.trim().toLowerCase();

        return (settlements || []).filter((settlement) => {
            if (settlementCostCenterFilter && settlement.cost_center !== settlementCostCenterFilter) {
                return false;
            }

            if (!term) {
                return true;
            }

            return matchesSearch([
                settlement.settlement_date,
                settlement.partner_name,
                cost_centers[settlement.cost_center] || settlement.cost_center,
                settlement.gross_sales_amount,
                settlement.fixed_rent_amount,
                settlement.commission_amount,
                settlement.total_amount,
                settlement.invoice_number,
            ], term);
        });
    }, [cost_centers, settlementCostCenterFilter, settlementSearch, settlements]);
    const agreementTotalPages = Math.max(1, Math.ceil(filteredAgreements.length / ROWS_PER_PAGE));
    const settlementTotalPages = Math.max(1, Math.ceil(filteredSettlements.length / ROWS_PER_PAGE));
    const agreementPageRows = useMemo(
        () => filteredAgreements.slice((agreementPage - 1) * ROWS_PER_PAGE, agreementPage * ROWS_PER_PAGE),
        [agreementPage, filteredAgreements],
    );
    const settlementPageRows = useMemo(
        () => filteredSettlements.slice((settlementPage - 1) * ROWS_PER_PAGE, settlementPage * ROWS_PER_PAGE),
        [filteredSettlements, settlementPage],
    );

    useEffect(() => {
        setAgreementPage(1);
    }, [agreementSearch, agreementStatusFilter]);

    useEffect(() => {
        if (agreementPage > agreementTotalPages) {
            setAgreementPage(agreementTotalPages);
        }
    }, [agreementPage, agreementTotalPages]);

    useEffect(() => {
        setSettlementPage(1);
    }, [settlementCostCenterFilter, settlementSearch]);

    useEffect(() => {
        if (settlementPage > settlementTotalPages) {
            setSettlementPage(settlementTotalPages);
        }
    }, [settlementPage, settlementTotalPages]);

    return (
        <AuthenticatedLayout header="Rental Income">
            <Head title="Rental Income" />

            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <div className="flex flex-wrap gap-3">
                    <Link href={route('finance.index')} className="text-sm text-indigo-600 hover:underline">
                        {'<-'} Finance overview
                    </Link>
                </div>

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Create rental agreement</h3>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            createForm.post(route('finance.rentals.store'), {
                                onSuccess: () => createForm.reset('customer_id', 'partner_name', 'fixed_rent_amount', 'commission_percent', 'end_date', 'notes'),
                            });
                        }}
                        className="grid gap-3 md:grid-cols-3"
                    >
                        <div><SearchableSelect label="Customer (optional)" value={createForm.data.customer_id} onChange={(value) => createForm.setData('customer_id', value)} options={customerOptions} placeholder="Search customer" />{fieldError(createForm, 'customer_id')}</div>
                        <div><label className="ta-field-label">Partner / specialist</label><input className="ta-input" value={createForm.data.partner_name} onChange={(e) => createForm.setData('partner_name', e.target.value)} required />{fieldError(createForm, 'partner_name')}</div>
                        <div><label className="ta-field-label">Agreement type</label><select className="ta-input" value={createForm.data.agreement_type} onChange={(e) => createForm.setData('agreement_type', e.target.value)}>{Object.entries(agreement_types).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></div>
                        <div><label className="ta-field-label">Cost center</label><select className="ta-input" value={createForm.data.cost_center} onChange={(e) => createForm.setData('cost_center', e.target.value)}>{Object.entries(cost_centers).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select>{fieldError(createForm, 'cost_center')}</div>
                        <div><label className="ta-field-label">Rental model</label><select className="ta-input" value={createForm.data.rental_model} onChange={(e) => createForm.setData('rental_model', e.target.value)}>{Object.entries(rental_models).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></div>
                        <div><label className="ta-field-label">Fixed rent amount</label><input type="number" min="0" step="0.01" className="ta-input" value={createForm.data.fixed_rent_amount} onChange={(e) => createForm.setData('fixed_rent_amount', e.target.value)} />{fieldError(createForm, 'fixed_rent_amount')}</div>
                        <div><label className="ta-field-label">Commission percent</label><input type="number" min="0" max="100" step="0.01" className="ta-input" value={createForm.data.commission_percent} onChange={(e) => createForm.setData('commission_percent', e.target.value)} />{fieldError(createForm, 'commission_percent')}</div>
                        <div><label className="ta-field-label">Start date</label><input type="date" className="ta-input" value={createForm.data.start_date} onChange={(e) => createForm.setData('start_date', e.target.value)} required />{fieldError(createForm, 'start_date')}</div>
                        <div><label className="ta-field-label">End date</label><input type="date" className="ta-input" value={createForm.data.end_date} onChange={(e) => createForm.setData('end_date', e.target.value)} />{fieldError(createForm, 'end_date')}</div>
                        <div className="md:col-span-3"><label className="ta-field-label">Notes</label><input className="ta-input" value={createForm.data.notes} onChange={(e) => createForm.setData('notes', e.target.value)} placeholder="Room details, payment terms, settlement logic..." />{fieldError(createForm, 'notes')}</div>
                        <button className="ta-btn-primary" disabled={createForm.processing || !(auth?.permissions?.can_manage_finance ?? auth?.user)}>{createForm.processing ? 'Saving...' : 'Create agreement'}</button>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4">
                        <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                            <h3 className="text-sm font-semibold text-slate-700">Rental agreements</h3>
                            <div className="grid w-full gap-2 sm:grid-cols-2 lg:max-w-xl">
                                <div>
                                    <label className="ta-field-label">Search</label>
                                    <input className="ta-input" value={agreementSearch} onChange={(e) => setAgreementSearch(e.target.value)} placeholder="Partner, customer, terms" />
                                </div>
                                <div>
                                    <label className="ta-field-label">Status</label>
                                    <select className="ta-input" value={agreementStatusFilter} onChange={(e) => setAgreementStatusFilter(e.target.value)}>
                                        <option value="">All statuses</option>
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Partner</th><th className="px-5 py-3">Type</th><th className="px-5 py-3">Terms</th><th className="px-5 py-3">Dates</th><th className="px-5 py-3">Settlements</th><th className="px-5 py-3">Status</th><th className="px-5 py-3 text-right">Actions</th></tr></thead>
                            <tbody>
                                {agreementPageRows.map((agreement) => (
                                    <tr key={agreement.id} className="border-t border-slate-100 align-top">
                                        <td className="px-5 py-3"><div className="font-medium text-slate-800">{agreement.partner_name}</div><div className="text-xs text-slate-500">{agreement.customer_name || 'Manual partner record'}</div></td>
                                        <td className="px-5 py-3 text-slate-600"><div>{agreement_types[agreement.agreement_type] || agreement.agreement_type}</div><div className="text-xs text-slate-500">{cost_centers[agreement.cost_center] || agreement.cost_center}</div></td>
                                        <td className="px-5 py-3 text-slate-600"><div>{rental_models[agreement.rental_model] || agreement.rental_model}</div><div className="text-xs text-slate-500">Fixed {money(agreement.fixed_rent_amount, currencyCode)}{agreement.commission_percent !== null ? ` · ${agreement.commission_percent}% commission` : ''}</div></td>
                                        <td className="px-5 py-3 text-slate-600"><div>{agreement.start_date}</div><div className="text-xs text-slate-500">{agreement.end_date || 'Open-ended'}</div></td>
                                        <td className="px-5 py-3 text-slate-600">{agreement.settlements_count}</td>
                                        <td className="px-5 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${agreement.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700'}`}>{agreement.is_active ? 'Active' : 'Inactive'}</span></td>
                                        <td className="px-5 py-3">
                                            <div className="flex justify-end gap-2">
                                                <button type="button" className="rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700" onClick={() => startSettlement(agreement)}>Settle</button>
                                                <button type="button" className="ta-btn-secondary" onClick={() => startEdit(agreement)}>Edit</button>
                                                <button type="button" className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700" onClick={() => setDeactivateAgreementId(agreement.id)}>Deactivate</button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {agreementPageRows.length === 0 ? (
                                    <tr className="border-t border-slate-100"><td className="px-5 py-6 text-sm text-slate-500" colSpan="7">No rental agreements match the current filters.</td></tr>
                                ) : null}
                            </tbody>
                        </table>
                    </div>
                    <TablePagination page={agreementPage} totalPages={agreementTotalPages} totalItems={filteredAgreements.length} pageSize={ROWS_PER_PAGE} onPageChange={setAgreementPage} itemLabel="agreements" />
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4">
                        <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                            <h3 className="text-sm font-semibold text-slate-700">Recent settlements</h3>
                            <div className="grid w-full gap-2 sm:grid-cols-2 lg:max-w-xl">
                                <div>
                                    <label className="ta-field-label">Search</label>
                                    <input className="ta-input" value={settlementSearch} onChange={(e) => setSettlementSearch(e.target.value)} placeholder="Partner, invoice, amount" />
                                </div>
                                <div>
                                    <label className="ta-field-label">Cost center</label>
                                    <select className="ta-input" value={settlementCostCenterFilter} onChange={(e) => setSettlementCostCenterFilter(e.target.value)}>
                                        <option value="">All cost centers</option>
                                        {Object.entries(cost_centers).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Date</th><th className="px-5 py-3">Partner</th><th className="px-5 py-3">Gross sales</th><th className="px-5 py-3">Fixed</th><th className="px-5 py-3">Commission</th><th className="px-5 py-3">Total</th><th className="px-5 py-3">Invoice</th></tr></thead>
                            <tbody>
                                {settlementPageRows.map((settlement) => (
                                    <tr key={settlement.id} className="border-t border-slate-100">
                                        <td className="px-5 py-3 text-slate-600">{settlement.settlement_date}</td>
                                        <td className="px-5 py-3 text-slate-600"><div>{settlement.partner_name}</div><div className="text-xs text-slate-500">{cost_centers[settlement.cost_center] || settlement.cost_center}</div></td>
                                        <td className="px-5 py-3 text-slate-600">{settlement.gross_sales_amount !== null ? money(settlement.gross_sales_amount, currencyCode) : '-'}</td>
                                        <td className="px-5 py-3 text-slate-600">{money(settlement.fixed_rent_amount, currencyCode)}</td>
                                        <td className="px-5 py-3 text-slate-600">{money(settlement.commission_amount, currencyCode)}</td>
                                        <td className="px-5 py-3 font-medium text-slate-800">{money(settlement.total_amount, currencyCode)}</td>
                                        <td className="px-5 py-3">{settlement.invoice_id ? <Link href={route('finance.invoices.show', settlement.invoice_id)} className="text-indigo-600 hover:underline">{settlement.invoice_number}</Link> : '-'}</td>
                                    </tr>
                                ))}
                                {settlementPageRows.length === 0 ? (
                                    <tr className="border-t border-slate-100"><td className="px-5 py-6 text-sm text-slate-500" colSpan="7">No settlements match the current filters.</td></tr>
                                ) : null}
                            </tbody>
                        </table>
                    </div>
                    <TablePagination page={settlementPage} totalPages={settlementTotalPages} totalItems={filteredSettlements.length} pageSize={ROWS_PER_PAGE} onPageChange={setSettlementPage} itemLabel="settlements" />
                </section>

                <Modal show={Boolean(editingAgreement)} onClose={() => setEditingAgreement(null)} maxWidth="2xl">
                    <div className="p-6">
                        <h3 className="mb-4 text-base font-semibold text-slate-800">Edit rental agreement</h3>
                        <form onSubmit={(e) => { e.preventDefault(); editForm.put(route('finance.rentals.update', editingAgreement.id), { onSuccess: () => setEditingAgreement(null) }); }} className="grid gap-3 md:grid-cols-2">
                            <div><SearchableSelect label="Customer (optional)" value={editForm.data.customer_id} onChange={(value) => editForm.setData('customer_id', value)} options={customerOptions} placeholder="Search customer" />{fieldError(editForm, 'customer_id')}</div>
                            <div><label className="ta-field-label">Partner / specialist</label><input className="ta-input" value={editForm.data.partner_name} onChange={(e) => editForm.setData('partner_name', e.target.value)} required />{fieldError(editForm, 'partner_name')}</div>
                            <div><label className="ta-field-label">Agreement type</label><select className="ta-input" value={editForm.data.agreement_type} onChange={(e) => editForm.setData('agreement_type', e.target.value)}>{Object.entries(agreement_types).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></div>
                            <div><label className="ta-field-label">Cost center</label><select className="ta-input" value={editForm.data.cost_center} onChange={(e) => editForm.setData('cost_center', e.target.value)}>{Object.entries(cost_centers).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></div>
                            <div><label className="ta-field-label">Rental model</label><select className="ta-input" value={editForm.data.rental_model} onChange={(e) => editForm.setData('rental_model', e.target.value)}>{Object.entries(rental_models).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></div>
                            <div><label className="ta-field-label">Fixed rent amount</label><input type="number" min="0" step="0.01" className="ta-input" value={editForm.data.fixed_rent_amount} onChange={(e) => editForm.setData('fixed_rent_amount', e.target.value)} /></div>
                            <div><label className="ta-field-label">Commission percent</label><input type="number" min="0" max="100" step="0.01" className="ta-input" value={editForm.data.commission_percent} onChange={(e) => editForm.setData('commission_percent', e.target.value)} /></div>
                            <div><label className="ta-field-label">Start date</label><input type="date" className="ta-input" value={editForm.data.start_date} onChange={(e) => editForm.setData('start_date', e.target.value)} required /></div>
                            <div><label className="ta-field-label">End date</label><input type="date" className="ta-input" value={editForm.data.end_date} onChange={(e) => editForm.setData('end_date', e.target.value)} /></div>
                            <div className="md:col-span-2"><label className="ta-field-label">Notes</label><input className="ta-input" value={editForm.data.notes} onChange={(e) => editForm.setData('notes', e.target.value)} /></div>
                            <div className="md:col-span-2 flex gap-2"><button className="ta-btn-primary" disabled={editForm.processing}>Save</button><button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setEditingAgreement(null)}>Close</button></div>
                        </form>
                    </div>
                </Modal>

                <Modal show={Boolean(settlingAgreement)} onClose={() => setSettlingAgreement(null)} maxWidth="2xl">
                    <div className="p-6">
                        <h3 className="mb-2 text-base font-semibold text-slate-800">Post rental settlement</h3>
                        <p className="mb-4 text-sm text-slate-500">This will create finance invoice lines for rental income and commission income.</p>
                        <form onSubmit={(e) => { e.preventDefault(); settleForm.post(route('finance.rentals.settle', settlingAgreement.id), { onSuccess: () => setSettlingAgreement(null) }); }} className="grid gap-3 md:grid-cols-2">
                            <div><label className="ta-field-label">Settlement date</label><input type="date" className="ta-input" value={settleForm.data.settlement_date} onChange={(e) => settleForm.setData('settlement_date', e.target.value)} required />{fieldError(settleForm, 'settlement_date')}</div>
                            <div><label className="ta-field-label">Fixed rent amount</label><input type="number" min="0" step="0.01" className="ta-input" value={settleForm.data.fixed_rent_amount} onChange={(e) => settleForm.setData('fixed_rent_amount', e.target.value)} />{fieldError(settleForm, 'fixed_rent_amount')}</div>
                            <div><label className="ta-field-label">Gross specialist sales</label><input type="number" min="0" step="0.01" className="ta-input" value={settleForm.data.gross_sales_amount} onChange={(e) => settleForm.setData('gross_sales_amount', e.target.value)} />{fieldError(settleForm, 'gross_sales_amount')}</div>
                            <div><label className="ta-field-label">Notes</label><input className="ta-input" value={settleForm.data.notes} onChange={(e) => settleForm.setData('notes', e.target.value)} placeholder="Period covered, reconciliation notes..." />{fieldError(settleForm, 'notes')}</div>
                            <div className="md:col-span-2 flex gap-2"><button className="ta-btn-primary" disabled={settleForm.processing}>Post settlement</button><button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setSettlingAgreement(null)}>Close</button></div>
                        </form>
                    </div>
                </Modal>

                <ConfirmActionModal
                    show={Boolean(deactivateAgreementId)}
                    title="Deactivate this rental agreement?"
                    message="The agreement will stop showing as active for future settlements."
                    confirmText="Deactivate"
                    onClose={() => !deactivateBusy && setDeactivateAgreementId(null)}
                    processing={deactivateBusy}
                    onConfirm={() => {
                        if (!deactivateAgreementId) return;
                        setDeactivateBusy(true);
                        router.delete(route('finance.rentals.destroy', deactivateAgreementId), {
                            onFinish: () => {
                                setDeactivateBusy(false);
                                setDeactivateAgreementId(null);
                            },
                        });
                    }}
                />
            </div>
        </AuthenticatedLayout>
    );
}
