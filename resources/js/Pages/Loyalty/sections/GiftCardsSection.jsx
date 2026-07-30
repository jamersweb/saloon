import SearchableSelect from '@/Components/SearchableSelect';
import Modal from '@/Components/Modal';
import { router } from '@inertiajs/react';
import { useRef, useState } from 'react';

export default function GiftCardsSection({
    fieldError,
    canManage,
    giftCardForm,
    assignGiftCardForm,
    consumeGiftCardForm,
    giftNfcLookupForm,
    giftNfcLookupResult,
    giftNfcBindForm,
    customers,
    giftCards,
    recentGiftTransactions,
    invoicesForGiftCards,
    appointmentsForRedeem,
    nfcBridgeLoadingTarget,
    readUidFromBridge,
    importCsv,
    exportCsv,
}) {
    const importFileRef = useRef(null);
    const [editingGiftCard, setEditingGiftCard] = useState(null);
    const [editGiftCardData, setEditGiftCardData] = useState({
        assigned_customer_id: '',
        nfc_uid: '',
        expires_at: '',
        status: 'active',
        notes: '',
    });
    const selectedConsumeGiftCard = giftCards.find((c) => String(c.id) === String(consumeGiftCardForm.data.gift_card_id));
    const giftConsumeAppointments = (appointmentsForRedeem || []).filter((a) => {
        if (!selectedConsumeGiftCard?.assigned_customer_id) {
            return true;
        }
        return String(a.customer_id) === String(selectedConsumeGiftCard.assigned_customer_id);
    });
    const giftConsumeInvoices = (invoicesForGiftCards || []).filter((invoice) => {
        if (!selectedConsumeGiftCard?.assigned_customer_id) {
            return true;
        }
        return String(invoice.customer_id) === String(selectedConsumeGiftCard.assigned_customer_id);
    });
    const customerOptions = customers.map((customer) => ({
        value: String(customer.id),
        label: `${customer.name}${customer.phone ? ` - ${customer.phone}` : ''}`,
    }));
    const giftCardOptions = giftCards.map((card) => ({
        value: String(card.id),
        label: `${card.code} (${card.remaining_value})${card.customer_name ? ` - ${card.customer_name}` : ' - unassigned'}`,
    }));
    const assignableGiftCardOptions = giftCards
        .filter((card) => !card.assigned_customer_id && card.status === 'active')
        .map((card) => ({
            value: String(card.id),
            label: `${card.code} (${card.remaining_value}) - unassigned`,
        }));
    const activeGiftCardOptions = giftCards
        .filter((card) => card.status === 'active')
        .map((card) => ({
            value: String(card.id),
            label: `${card.code} (${card.remaining_value})${card.assigned_customer_id ? '' : ' - unassigned'}`,
        }));
    const openEditGiftCard = (card) => {
        setEditingGiftCard(card);
        setEditGiftCardData({
            assigned_customer_id: card.assigned_customer_id ? String(card.assigned_customer_id) : '',
            nfc_uid: card.nfc_uid || '',
            expires_at: card.expires_at ? String(card.expires_at).slice(0, 10) : '',
            status: card.status || 'active',
            notes: card.notes || '',
        });
    };
    const updateEditGiftCardData = (field, value) => {
        setEditGiftCardData((current) => ({ ...current, [field]: value }));
    };
    const saveGiftCard = (e) => {
        e.preventDefault();
        if (!editingGiftCard) return;
        router.put(route('loyalty.gift-cards.update', editingGiftCard.id), editGiftCardData, {
            preserveScroll: true,
            onSuccess: () => setEditingGiftCard(null),
        });
    };
    const deactivateGiftCard = (card) => {
        if (!window.confirm(`Deactivate gift card ${card.code}?`)) return;
        router.patch(route('loyalty.gift-cards.deactivate', card.id), {}, { preserveScroll: true });
    };

    return (
        <div className="space-y-6">
            <Modal show={Boolean(editingGiftCard)} onClose={() => setEditingGiftCard(null)} maxWidth="2xl">
                <form onSubmit={saveGiftCard} className="grid gap-4 p-6 md:grid-cols-2">
                    <div className="md:col-span-2">
                        <h3 className="text-base font-semibold text-slate-800">Edit gift card {editingGiftCard?.code}</h3>
                    </div>
                    <div>
                        <SearchableSelect
                            label="Customer"
                            value={editGiftCardData.assigned_customer_id}
                            onChange={(id) => updateEditGiftCardData('assigned_customer_id', id)}
                            options={[{ value: '', label: 'Unassigned' }, ...customerOptions]}
                            placeholder="Search customer"
                        />
                    </div>
                    <div>
                        <label className="ta-field-label">Status</label>
                        <select className="ta-input" value={editGiftCardData.status} onChange={(e) => updateEditGiftCardData('status', e.target.value)} required>
                            <option value="active">active</option>
                            <option value="inactive">inactive</option>
                            <option value="redeemed">redeemed</option>
                            <option value="expired">expired</option>
                        </select>
                    </div>
                    <div>
                        <label className="ta-field-label">NFC UID</label>
                        <input className="ta-input" value={editGiftCardData.nfc_uid} onChange={(e) => updateEditGiftCardData('nfc_uid', e.target.value)} />
                    </div>
                    <div>
                        <label className="ta-field-label">Expires at</label>
                        <input className="ta-input" type="date" value={editGiftCardData.expires_at} onChange={(e) => updateEditGiftCardData('expires_at', e.target.value)} />
                    </div>
                    <div className="md:col-span-2">
                        <label className="ta-field-label">Notes</label>
                        <textarea className="ta-input min-h-[96px]" value={editGiftCardData.notes} onChange={(e) => updateEditGiftCardData('notes', e.target.value)} />
                    </div>
                    <div className="md:col-span-2 flex flex-wrap gap-2">
                        <button className="ta-btn-primary" disabled={!canManage}>Save gift card</button>
                        <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setEditingGiftCard(null)}>Cancel</button>
                        {editingGiftCard?.status !== 'inactive' ? (
                            <button type="button" className="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700" onClick={() => deactivateGiftCard(editingGiftCard)}>Deactivate</button>
                        ) : null}
                    </div>
                </form>
            </Modal>

            <section className="ta-card p-4">
                <div className="flex items-center gap-2">
                    <input ref={importFileRef} type="file" accept=".csv,text/csv" className="hidden" onChange={(e) => {
                        const file = e.target.files?.[0];
                        importCsv?.('gift_cards', file, () => {
                            if (importFileRef.current) importFileRef.current.value = '';
                        });
                    }} />
                    <button type="button" className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700 disabled:opacity-50" disabled={!canManage} onClick={() => importFileRef.current?.click()}>Import CSV</button>
                    <button type="button" className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700" onClick={() => window.location.href = route('data-transfer.template', { entity: 'gift_cards' })}>Template CSV</button>
                    <button type="button" className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700" onClick={() => exportCsv?.('gift_cards')}>Export CSV</button>
                </div>
            </section>

            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Issue gift card</h3>
                <form onSubmit={(e) => { e.preventDefault(); giftCardForm.post(route('loyalty.gift-cards.store'), { onSuccess: () => giftCardForm.reset('assigned_customer_id', 'initial_value', 'random_voucher', 'nfc_uid', 'notes') }); }} className="grid gap-3 md:grid-cols-6">
                    <div><SearchableSelect label="Customer" value={giftCardForm.data.assigned_customer_id} onChange={(id) => giftCardForm.setData('assigned_customer_id', id)} options={[{ value: '', label: 'Unassigned' }, ...customerOptions]} placeholder="Search customer" />{fieldError(giftCardForm, 'assigned_customer_id')}</div>
                    <div><label className="ta-field-label">Initial value</label><input className="ta-input" type="number" min="0.01" step="0.01" value={giftCardForm.data.initial_value} onChange={(e) => giftCardForm.setData('initial_value', e.target.value)} required={!giftCardForm.data.random_voucher} disabled={giftCardForm.data.random_voucher} />{fieldError(giftCardForm, 'initial_value')}</div>
                    <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={giftCardForm.data.random_voucher} onChange={(e) => giftCardForm.setData((current) => ({ ...current, random_voucher: e.target.checked, initial_value: e.target.checked ? '' : current.initial_value }))} />Random voucher 100 / 200 / 300</label>
                    <div><label className="ta-field-label">NFC UID</label><input className="ta-input" value={giftCardForm.data.nfc_uid} onChange={(e) => giftCardForm.setData('nfc_uid', e.target.value)} placeholder="Optional physical NFC gift card" />{fieldError(giftCardForm, 'nfc_uid')}</div>
                    <button type="button" className="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-medium text-sky-700 disabled:opacity-50" onClick={() => readUidFromBridge('gift_issue')} disabled={!canManage || nfcBridgeLoadingTarget !== null}>{nfcBridgeLoadingTarget === 'gift_issue' ? 'Reading...' : 'Read UID'}</button>
                    <div><label className="ta-field-label">Notes</label><input className="ta-input" value={giftCardForm.data.notes} onChange={(e) => giftCardForm.setData('notes', e.target.value)} />{fieldError(giftCardForm, 'notes')}</div>
                    <button className="ta-btn-primary" disabled={giftCardForm.processing || !canManage}>Issue gift card</button>
                </form>
            </section>

            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Assign existing gift card to customer</h3>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        assignGiftCardForm.post(route('loyalty.gift-cards.assign'), {
                            onSuccess: () => assignGiftCardForm.reset('gift_card_id', 'assigned_customer_id'),
                        });
                    }}
                    className="grid gap-3 md:grid-cols-3"
                >
                    <div>
                        <SearchableSelect label="Gift card" value={assignGiftCardForm.data.gift_card_id} onChange={(id) => assignGiftCardForm.setData('gift_card_id', id)} options={assignableGiftCardOptions} placeholder="Search unassigned gift card" />
                        {fieldError(assignGiftCardForm, 'gift_card_id')}
                    </div>
                    <div>
                        <SearchableSelect label="Customer" value={assignGiftCardForm.data.assigned_customer_id} onChange={(id) => assignGiftCardForm.setData('assigned_customer_id', id)} options={customerOptions} placeholder="Search customer" />
                        {fieldError(assignGiftCardForm, 'assigned_customer_id')}
                    </div>
                    <button className="ta-btn-primary" disabled={assignGiftCardForm.processing || !canManage}>Assign gift card</button>
                </form>
            </section>

            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Redeem gift card</h3>
                <form onSubmit={(e) => { e.preventDefault(); consumeGiftCardForm.post(route('loyalty.gift-cards.consume', consumeGiftCardForm.data.gift_card_id), { onSuccess: () => consumeGiftCardForm.reset('amount', 'reason', 'notes', 'appointment_id', 'tax_invoice_id') }); }} className="grid gap-3 md:grid-cols-2 lg:grid-cols-6">
                    <div><SearchableSelect label="Gift card" value={consumeGiftCardForm.data.gift_card_id} onChange={(id) => { consumeGiftCardForm.setData('gift_card_id', id); consumeGiftCardForm.setData('appointment_id', ''); consumeGiftCardForm.setData('tax_invoice_id', ''); }} options={activeGiftCardOptions} placeholder="Search gift card" />{fieldError(consumeGiftCardForm, 'gift_card_id')}</div>
                    <div className="lg:col-span-2">
                        <label className="ta-field-label">Link to visit (optional)</label>
                        <select className="ta-input" value={consumeGiftCardForm.data.appointment_id} onChange={(e) => consumeGiftCardForm.setData('appointment_id', e.target.value)} disabled={!consumeGiftCardForm.data.gift_card_id}>
                            <option value="">No visit link</option>
                            {giftConsumeAppointments.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}
                        </select>
                        <p className="mt-1 text-xs text-slate-500">If set, completing that visit will not auto-earn loyalty points (gift card payment).</p>
                        {fieldError(consumeGiftCardForm, 'appointment_id')}
                    </div>
                    <div className="lg:col-span-2">
                        <label className="ta-field-label">Connect invoice (optional)</label>
                        <select className="ta-input" value={consumeGiftCardForm.data.tax_invoice_id} onChange={(e) => consumeGiftCardForm.setData('tax_invoice_id', e.target.value)} disabled={!consumeGiftCardForm.data.gift_card_id}>
                            <option value="">No invoice link</option>
                            {giftConsumeInvoices.map((invoice) => <option key={invoice.id} value={invoice.id}>{invoice.label}</option>)}
                        </select>
                        {fieldError(consumeGiftCardForm, 'tax_invoice_id')}
                    </div>
                    <div><label className="ta-field-label">Amount</label><input className="ta-input" type="number" min="0.01" step="0.01" value={consumeGiftCardForm.data.amount} onChange={(e) => consumeGiftCardForm.setData('amount', e.target.value)} required />{fieldError(consumeGiftCardForm, 'amount')}</div>
                    <div><label className="ta-field-label">Reason</label><input className="ta-input" value={consumeGiftCardForm.data.reason} onChange={(e) => consumeGiftCardForm.setData('reason', e.target.value)} required />{fieldError(consumeGiftCardForm, 'reason')}</div>
                    <div><label className="ta-field-label">Notes</label><input className="ta-input" value={consumeGiftCardForm.data.notes} onChange={(e) => consumeGiftCardForm.setData('notes', e.target.value)} />{fieldError(consumeGiftCardForm, 'notes')}</div>
                    <button className="ta-btn-primary lg:col-span-6" disabled={consumeGiftCardForm.processing || !canManage || !consumeGiftCardForm.data.gift_card_id}>Redeem gift card</button>
                </form>
            </section>

            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Gift card NFC scan lookup</h3>
                <form onSubmit={(e) => { e.preventDefault(); giftNfcLookupForm.post(route('loyalty.gift-cards.nfc-lookup')); }} className="grid gap-3 md:grid-cols-4">
                    <div className="md:col-span-2"><label className="ta-field-label">NFC UID</label><input className="ta-input" value={giftNfcLookupForm.data.gift_nfc_uid} onChange={(e) => giftNfcLookupForm.setData('gift_nfc_uid', e.target.value)} placeholder="Paste or scan NFC UID" required />{fieldError(giftNfcLookupForm, 'gift_nfc_uid')}</div>
                    <button type="button" className="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-medium text-sky-700 disabled:opacity-50" onClick={() => readUidFromBridge('gift_lookup')} disabled={!canManage || nfcBridgeLoadingTarget !== null}>{nfcBridgeLoadingTarget === 'gift_lookup' ? 'Reading...' : 'Read UID'}</button>
                    <button className="ta-btn-primary" disabled={giftNfcLookupForm.processing || !canManage}>Lookup gift card</button>
                </form>
                {giftNfcLookupResult && (
                    <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
                        <div className="font-semibold text-slate-700">{giftNfcLookupResult.code}</div>
                        <div className="mt-1 text-slate-600">Remaining: {giftNfcLookupResult.remaining_value} ({giftNfcLookupResult.status})</div>
                        <div className="mt-1 text-slate-600">Customer: {giftNfcLookupResult.customer_name || 'Unassigned'}</div>
                        <div className="mt-1 text-slate-600">Phone: {giftNfcLookupResult.customer_phone || 'N/A'}</div>
                        <div className="mt-1 text-slate-600">NFC UID: {giftNfcLookupResult.nfc_uid}</div>
                    </div>
                )}
            </section>

            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Bind / replace gift card NFC UID</h3>
                <form onSubmit={(e) => { e.preventDefault(); giftNfcBindForm.post(route('loyalty.gift-cards.nfc-bind'), { onSuccess: () => giftNfcBindForm.reset('nfc_uid', 'replace_existing') }); }} className="grid gap-3 md:grid-cols-4">
                    <div><SearchableSelect label="Gift card" value={giftNfcBindForm.data.gift_card_id} onChange={(id) => giftNfcBindForm.setData('gift_card_id', id)} options={giftCardOptions} placeholder="Search gift card" />{fieldError(giftNfcBindForm, 'gift_card_id')}</div>
                    <div><label className="ta-field-label">NFC UID</label><input className="ta-input" value={giftNfcBindForm.data.nfc_uid} onChange={(e) => giftNfcBindForm.setData('nfc_uid', e.target.value)} placeholder="Scan new UID" required />{fieldError(giftNfcBindForm, 'nfc_uid')}</div>
                    <button type="button" className="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-medium text-sky-700 disabled:opacity-50" onClick={() => readUidFromBridge('gift_bind')} disabled={!canManage || nfcBridgeLoadingTarget !== null}>{nfcBridgeLoadingTarget === 'gift_bind' ? 'Reading...' : 'Read UID'}</button>
                    <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={giftNfcBindForm.data.replace_existing} onChange={(e) => giftNfcBindForm.setData('replace_existing', e.target.checked)} />Replace existing binding if UID is already linked to another gift card</label>
                    <button className="ta-btn-primary" disabled={giftNfcBindForm.processing || !canManage}>Bind NFC UID</button>
                </form>
            </section>

            <section className="ta-card overflow-hidden">
                <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Gift Card Registry</h3></div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Code</th><th className="px-5 py-3">NFC UID</th><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Initial</th><th className="px-5 py-3">Remaining</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Actions</th></tr></thead>
                        <tbody>{giftCards.map((card) => <tr key={card.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-700">{card.code}</td><td className="px-5 py-3 text-slate-600">{card.nfc_uid || 'Unbound'}</td><td className="px-5 py-3 text-slate-600">{card.customer_name || 'Unassigned'}<div className="text-xs text-slate-500">{card.customer_phone || ''}</div></td><td className="px-5 py-3 text-slate-600">{card.initial_value}</td><td className="px-5 py-3 text-slate-600">{card.remaining_value}</td><td className="px-5 py-3 text-slate-600">{card.status}</td><td className="px-5 py-3"><div className="flex flex-wrap gap-2"><button type="button" className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700" disabled={!canManage} onClick={() => openEditGiftCard(card)}>Edit</button>{card.status !== 'inactive' ? <button type="button" className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700" disabled={!canManage} onClick={() => deactivateGiftCard(card)}>Deactivate</button> : null}</div></td></tr>)}</tbody>
                    </table>
                </div>
            </section>

            <section className="ta-card overflow-hidden">
                <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Transactions</h3></div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Date</th><th className="px-5 py-3">Gift card</th><th className="px-5 py-3">Amount</th><th className="px-5 py-3">Balance</th><th className="px-5 py-3">Reason</th><th className="px-5 py-3">Invoice</th></tr></thead>
                        <tbody>{recentGiftTransactions.map((row) => <tr key={row.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-600">{new Date(row.created_at).toLocaleString()}</td><td className="px-5 py-3 text-slate-700">{row.gift_code}</td><td className="px-5 py-3 text-red-600">{row.amount_change}</td><td className="px-5 py-3 text-slate-700">{row.balance_after}</td><td className="px-5 py-3 text-slate-600">{row.reason}</td><td className="px-5 py-3 text-slate-600">{row.invoice_label || '-'}</td></tr>)}</tbody>
                    </table>
                </div>
            </section>
        </div>
    );
}
