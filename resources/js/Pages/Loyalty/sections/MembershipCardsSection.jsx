import { useRef } from 'react';

export default function MembershipCardsSection({
    fieldError,
    canManage,
    cardTypes,
    customers,
    membershipCards,
    nfcLookupResult,
    nfcBridgeLoadingTarget,
    createCardTypeForm,
    editingCardTypeId,
    startEditCardType,
    editCardTypeForm,
    setEditingCardTypeId,
    issueInventoryForm,
    linkInventoryForm,
    assignCardForm,
    nfcLookupForm,
    nfcBindForm,
    readUidFromBridge,
    importCsv,
    exportCsv,
}) {
    const importFileRef = useRef(null);

    const copyNfcPortalUrl = async (nfcUid) => {
        if (!nfcUid) {
            return;
        }

        const url = `${window.location.origin}/portal/nfc/${encodeURIComponent(String(nfcUid).trim())}`;

        try {
            await navigator.clipboard.writeText(url);
            window.alert(`Copied NFC URL:\n${url}`);
        } catch {
            window.prompt('Copy NFC URL:', url);
        }
    };

    const openNfcPortalUrl = (nfcUid) => {
        if (!nfcUid) {
            return;
        }

        const url = `${window.location.origin}/portal/nfc/${encodeURIComponent(String(nfcUid).trim())}`;
        window.open(url, '_blank', 'noopener,noreferrer');
    };

    return (
        <div className="space-y-6">
            <section className="ta-card p-4">
                <div className="flex items-center gap-2">
                    <input ref={importFileRef} type="file" accept=".csv,text/csv" className="hidden" onChange={(e) => {
                        const file = e.target.files?.[0];
                        importCsv?.('membership_cards', file, () => {
                            if (importFileRef.current) importFileRef.current.value = '';
                        });
                    }} />
                    <button type="button" className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700 disabled:opacity-50" disabled={!canManage} onClick={() => importFileRef.current?.click()}>Import CSV</button>
                    <button type="button" className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700" onClick={() => window.location.href = route('data-transfer.template', { entity: 'membership_cards' })}>Template CSV</button>
                    <button type="button" className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700" onClick={() => exportCsv?.('membership_cards')}>Export CSV</button>
                </div>
            </section>

            <p className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                Default physical tiers from the Vina loyalty card PDF are Queen, Titanium, and Gold (slugs <span className="font-mono text-slate-800">queen</span>, <span className="font-mono text-slate-800">titanium</span>, <span className="font-mono text-slate-800">gold</span>); seeders align prices and min-points with that document.
            </p>
            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Create membership card type</h3>
                <form onSubmit={(e) => { e.preventDefault(); createCardTypeForm.post(route('loyalty.card-types.store'), { onSuccess: () => createCardTypeForm.reset('name', 'slug', 'direct_purchase_price', 'validity_days') }); }} className="grid gap-3 md:grid-cols-6">
                    <div><label className="ta-field-label">Name</label><input className="ta-input" value={createCardTypeForm.data.name} onChange={(e) => createCardTypeForm.setData('name', e.target.value)} required />{fieldError(createCardTypeForm, 'name')}</div>
                    <div><label className="ta-field-label">Slug</label><input className="ta-input" value={createCardTypeForm.data.slug} onChange={(e) => createCardTypeForm.setData('slug', e.target.value)} required />{fieldError(createCardTypeForm, 'slug')}</div>
                    <div><label className="ta-field-label">Kind</label><select className="ta-input" value={createCardTypeForm.data.kind} onChange={(e) => createCardTypeForm.setData('kind', e.target.value)}><option value="physical">Physical</option><option value="virtual">Virtual</option><option value="gift">Gift</option></select>{fieldError(createCardTypeForm, 'kind')}</div>
                    <div><label className="ta-field-label">Min points</label><input className="ta-input" type="number" min="0" value={createCardTypeForm.data.min_points} onChange={(e) => createCardTypeForm.setData('min_points', e.target.value)} required />{fieldError(createCardTypeForm, 'min_points')}</div>
                    <div><label className="ta-field-label">Direct price</label><input className="ta-input" type="number" min="0" step="0.01" value={createCardTypeForm.data.direct_purchase_price} onChange={(e) => createCardTypeForm.setData('direct_purchase_price', e.target.value)} />{fieldError(createCardTypeForm, 'direct_purchase_price')}</div>
                    <div><label className="ta-field-label">Validity days</label><input className="ta-input" type="number" min="1" value={createCardTypeForm.data.validity_days} onChange={(e) => createCardTypeForm.setData('validity_days', e.target.value)} />{fieldError(createCardTypeForm, 'validity_days')}</div>
                    <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={createCardTypeForm.data.is_active} onChange={(e) => createCardTypeForm.setData('is_active', e.target.checked)} />Active</label>
                    <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={createCardTypeForm.data.is_transferable} onChange={(e) => createCardTypeForm.setData('is_transferable', e.target.checked)} />Transferable</label>
                    <button className="ta-btn-primary" disabled={createCardTypeForm.processing || !canManage}>Add card type</button>
                </form>
            </section>

            <section className="ta-card overflow-hidden">
                <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Membership card types</h3></div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Name</th><th className="px-5 py-3">Kind</th><th className="px-5 py-3">Min points</th><th className="px-5 py-3">Validity</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Actions</th></tr></thead>
                        <tbody>{cardTypes.map((cardType) => <tr key={cardType.id} className="border-t border-slate-100"><td className="px-5 py-3 font-medium text-slate-700">{cardType.name}</td><td className="px-5 py-3 text-slate-600">{cardType.kind}</td><td className="px-5 py-3 text-slate-600">{cardType.min_points}</td><td className="px-5 py-3 text-slate-600">{cardType.validity_days ? `${cardType.validity_days} days` : 'No expiry'}</td><td className="px-5 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${cardType.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700'}`}>{cardType.is_active ? 'Active' : 'Inactive'}</span></td><td className="px-5 py-3"><button type="button" className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs text-indigo-700" onClick={() => startEditCardType(cardType)}>Edit</button></td></tr>)}</tbody>
                    </table>
                </div>
            </section>

            {editingCardTypeId && (
                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Edit card type #{editingCardTypeId}</h3>
                    <form onSubmit={(e) => { e.preventDefault(); editCardTypeForm.put(route('loyalty.card-types.update', editingCardTypeId), { onSuccess: () => setEditingCardTypeId(null) }); }} className="grid gap-3 md:grid-cols-6">
                        <div><label className="ta-field-label">Name</label><input className="ta-input" value={editCardTypeForm.data.name} onChange={(e) => editCardTypeForm.setData('name', e.target.value)} required />{fieldError(editCardTypeForm, 'name')}</div>
                        <div><label className="ta-field-label">Slug</label><input className="ta-input" value={editCardTypeForm.data.slug} onChange={(e) => editCardTypeForm.setData('slug', e.target.value)} required />{fieldError(editCardTypeForm, 'slug')}</div>
                        <div><label className="ta-field-label">Kind</label><select className="ta-input" value={editCardTypeForm.data.kind} onChange={(e) => editCardTypeForm.setData('kind', e.target.value)}><option value="physical">Physical</option><option value="virtual">Virtual</option><option value="gift">Gift</option></select>{fieldError(editCardTypeForm, 'kind')}</div>
                        <div><label className="ta-field-label">Min points</label><input className="ta-input" type="number" min="0" value={editCardTypeForm.data.min_points} onChange={(e) => editCardTypeForm.setData('min_points', e.target.value)} required />{fieldError(editCardTypeForm, 'min_points')}</div>
                        <div><label className="ta-field-label">Direct price</label><input className="ta-input" type="number" min="0" step="0.01" value={editCardTypeForm.data.direct_purchase_price ?? ''} onChange={(e) => editCardTypeForm.setData('direct_purchase_price', e.target.value)} />{fieldError(editCardTypeForm, 'direct_purchase_price')}</div>
                        <div><label className="ta-field-label">Validity days</label><input className="ta-input" type="number" min="1" value={editCardTypeForm.data.validity_days ?? ''} onChange={(e) => editCardTypeForm.setData('validity_days', e.target.value)} />{fieldError(editCardTypeForm, 'validity_days')}</div>
                        <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={editCardTypeForm.data.is_active} onChange={(e) => editCardTypeForm.setData('is_active', e.target.checked)} />Active</label>
                        <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={editCardTypeForm.data.is_transferable} onChange={(e) => editCardTypeForm.setData('is_transferable', e.target.checked)} />Transferable</label>
                        <div className="md:col-span-6 flex gap-2"><button className="ta-btn-primary" disabled={editCardTypeForm.processing || !canManage}>Save</button><button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setEditingCardTypeId(null)}>Cancel</button></div>
                    </form>
                </section>
            )}

            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Pre-issue card (inventory / printing)</h3>
                <p className="mb-3 text-xs text-slate-600">Generate a numeric card number before you know the customer—use for bulk printing. Auto-generated numbers are sequential per card type (starting at 100000000001). Link the card to a customer when they purchase.</p>
                <form onSubmit={(e) => { e.preventDefault(); issueInventoryForm.post(route('loyalty.cards.issue-inventory'), { onSuccess: () => issueInventoryForm.reset('card_number', 'nfc_uid', 'notes') }); }} className="grid gap-3 md:grid-cols-6">
                    <div><label className="ta-field-label">Card type</label><select className="ta-input" value={issueInventoryForm.data.membership_card_type_id} onChange={(e) => issueInventoryForm.setData('membership_card_type_id', e.target.value)} required><option value="">Select card type</option>{cardTypes.filter((type) => type.is_active).map((cardType) => <option key={cardType.id} value={cardType.id}>{cardType.name}</option>)}</select>{fieldError(issueInventoryForm, 'membership_card_type_id')}</div>
                    <div><label className="ta-field-label">Card number</label><input className="ta-input" inputMode="numeric" pattern="[0-9]*" value={issueInventoryForm.data.card_number} onChange={(e) => issueInventoryForm.setData('card_number', e.target.value)} placeholder="Digits only, auto if blank" />{fieldError(issueInventoryForm, 'card_number')}</div>
                    <div><label className="ta-field-label">NFC UID</label><input className="ta-input" value={issueInventoryForm.data.nfc_uid} onChange={(e) => issueInventoryForm.setData('nfc_uid', e.target.value)} />{fieldError(issueInventoryForm, 'nfc_uid')}</div>
                    <button type="button" className="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-medium text-sky-700 disabled:opacity-50" onClick={() => readUidFromBridge('issue_inventory')} disabled={!canManage || nfcBridgeLoadingTarget !== null}>{nfcBridgeLoadingTarget === 'issue_inventory' ? 'Reading...' : 'Read UID'}</button>
                    <div><label className="ta-field-label">Status</label><select className="ta-input" value={issueInventoryForm.data.status} onChange={(e) => issueInventoryForm.setData('status', e.target.value)}><option value="pending">pending (inventory)</option><option value="active">active</option><option value="inactive">inactive</option></select>{fieldError(issueInventoryForm, 'status')}</div>
                    <button className="ta-btn-primary" disabled={issueInventoryForm.processing || !canManage}>Pre-issue card</button>
                    <div className="md:col-span-6"><label className="ta-field-label">Notes</label><input className="ta-input" value={issueInventoryForm.data.notes} onChange={(e) => issueInventoryForm.setData('notes', e.target.value)} placeholder="Optional batch / print run notes" />{fieldError(issueInventoryForm, 'notes')}</div>
                </form>
            </section>

            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Link pre-issued card to customer</h3>
                <form onSubmit={(e) => { e.preventDefault(); linkInventoryForm.post(route('loyalty.cards.link-customer'), { onSuccess: () => linkInventoryForm.reset('customer_membership_card_id', 'notes') }); }} className="grid gap-3 md:grid-cols-6">
                    <div><label className="ta-field-label">Customer</label><select className="ta-input" value={linkInventoryForm.data.customer_id} onChange={(e) => linkInventoryForm.setData('customer_id', e.target.value)} required><option value="">Select customer</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name} ({customer.points} pts)</option>)}</select>{fieldError(linkInventoryForm, 'customer_id')}</div>
                    <div className="md:col-span-2"><label className="ta-field-label">Pre-issued card</label><select className="ta-input" value={linkInventoryForm.data.customer_membership_card_id} onChange={(e) => linkInventoryForm.setData('customer_membership_card_id', e.target.value)} required><option value="">Select unassigned card</option>{membershipCards.filter((c) => c.customer_id == null).map((card) => <option key={card.id} value={card.id}>#{card.card_number || card.id} — {card.card_type_name}</option>)}</select>{fieldError(linkInventoryForm, 'customer_membership_card_id')}</div>
                    <div><label className="ta-field-label">Status after link</label><select className="ta-input" value={linkInventoryForm.data.status} onChange={(e) => linkInventoryForm.setData('status', e.target.value)}><option value="active">active</option><option value="pending">pending</option><option value="inactive">inactive</option></select>{fieldError(linkInventoryForm, 'status')}</div>
                    <button className="ta-btn-primary" disabled={linkInventoryForm.processing || !canManage}>Link to customer</button>
                    <div className="md:col-span-6"><label className="ta-field-label">Notes</label><input className="ta-input" value={linkInventoryForm.data.notes} onChange={(e) => linkInventoryForm.setData('notes', e.target.value)} placeholder="Optional (e.g. sale reference)" />{fieldError(linkInventoryForm, 'notes')}</div>
                </form>
            </section>

            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Assign new card to customer (one step)</h3>
                <p className="mb-3 text-xs text-slate-600">Creates a new membership row and ties it to the customer immediately. Card numbers are numeric only (auto-generated if left blank).</p>
                <form onSubmit={(e) => { e.preventDefault(); assignCardForm.post(route('loyalty.cards.assign'), { onSuccess: () => assignCardForm.reset('card_number', 'nfc_uid', 'notes') }); }} className="grid gap-3 md:grid-cols-6">
                    <div><label className="ta-field-label">Customer</label><select className="ta-input" value={assignCardForm.data.customer_id} onChange={(e) => assignCardForm.setData('customer_id', e.target.value)} required><option value="">Select customer</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name} ({customer.points} pts)</option>)}</select>{fieldError(assignCardForm, 'customer_id')}</div>
                    <div><label className="ta-field-label">Card type</label><select className="ta-input" value={assignCardForm.data.membership_card_type_id} onChange={(e) => assignCardForm.setData('membership_card_type_id', e.target.value)} required><option value="">Select card type</option>{cardTypes.filter((type) => type.is_active).map((cardType) => <option key={cardType.id} value={cardType.id}>{cardType.name}</option>)}</select>{fieldError(assignCardForm, 'membership_card_type_id')}</div>
                    <div><label className="ta-field-label">Card number</label><input className="ta-input" inputMode="numeric" pattern="[0-9]*" value={assignCardForm.data.card_number} onChange={(e) => assignCardForm.setData('card_number', e.target.value)} placeholder="Digits only, auto if blank" />{fieldError(assignCardForm, 'card_number')}</div>
                    <div><label className="ta-field-label">NFC UID</label><input className="ta-input" value={assignCardForm.data.nfc_uid} onChange={(e) => assignCardForm.setData('nfc_uid', e.target.value)} />{fieldError(assignCardForm, 'nfc_uid')}</div>
                    <button type="button" className="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-medium text-sky-700 disabled:opacity-50" onClick={() => readUidFromBridge('assign')} disabled={!canManage || nfcBridgeLoadingTarget !== null}>{nfcBridgeLoadingTarget === 'assign' ? 'Reading...' : 'Read UID'}</button>
                    <div><label className="ta-field-label">Status</label><select className="ta-input" value={assignCardForm.data.status} onChange={(e) => assignCardForm.setData('status', e.target.value)}><option value="active">active</option><option value="pending">pending</option><option value="inactive">inactive</option><option value="expired">expired</option></select>{fieldError(assignCardForm, 'status')}</div>
                    <button className="ta-btn-primary" disabled={assignCardForm.processing || !canManage}>Assign card</button>
                    <div className="md:col-span-6"><label className="ta-field-label">Notes</label><input className="ta-input" value={assignCardForm.data.notes} onChange={(e) => assignCardForm.setData('notes', e.target.value)} placeholder="Optional assignment notes" />{fieldError(assignCardForm, 'notes')}</div>
                </form>
            </section>

            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">NFC scan lookup</h3>
                <form onSubmit={(e) => { e.preventDefault(); nfcLookupForm.post(route('loyalty.cards.nfc-lookup')); }} className="grid gap-3 md:grid-cols-4">
                    <div className="md:col-span-2"><label className="ta-field-label">NFC UID</label><input className="ta-input" value={nfcLookupForm.data.nfc_uid} onChange={(e) => nfcLookupForm.setData('nfc_uid', e.target.value)} placeholder="Paste or scan NFC UID" required />{fieldError(nfcLookupForm, 'nfc_uid')}</div>
                    <button type="button" className="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-medium text-sky-700 disabled:opacity-50" onClick={() => readUidFromBridge('lookup')} disabled={!canManage || nfcBridgeLoadingTarget !== null}>{nfcBridgeLoadingTarget === 'lookup' ? 'Reading...' : 'Read UID'}</button>
                    <button className="ta-btn-primary" disabled={nfcLookupForm.processing || !canManage}>Lookup card</button>
                </form>
                {nfcLookupResult && (
                    <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
                        <div className="font-semibold text-slate-700">{nfcLookupResult.is_unassigned ? 'Unassigned (inventory)' : nfcLookupResult.customer_name}</div>
                        <div className="mt-1 text-slate-600">Card: {nfcLookupResult.card_number} ({nfcLookupResult.card_type_name})</div>
                        <div className="mt-1 text-slate-600">Status: {nfcLookupResult.card_status}</div>
                        <div className="mt-1 text-slate-600">Phone: {nfcLookupResult.customer_phone || 'N/A'}</div>
                        <div className="mt-1 text-slate-600">NFC UID: {nfcLookupResult.nfc_uid}</div>
                    </div>
                )}
            </section>

            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Bind / replace NFC UID</h3>
                <form onSubmit={(e) => { e.preventDefault(); nfcBindForm.post(route('loyalty.cards.nfc-bind'), { onSuccess: () => nfcBindForm.reset('nfc_uid', 'replace_existing') }); }} className="grid gap-3 md:grid-cols-4">
                    <div><label className="ta-field-label">Membership card</label><select className="ta-input" value={nfcBindForm.data.customer_membership_card_id} onChange={(e) => nfcBindForm.setData('customer_membership_card_id', e.target.value)} required><option value="">Select card</option>{membershipCards.map((card) => <option key={card.id} value={card.id}>{card.customer_id == null ? 'Inventory' : card.customer_name} · {card.card_number || 'No number'} ({card.card_type_name})</option>)}</select>{fieldError(nfcBindForm, 'customer_membership_card_id')}</div>
                    <div><label className="ta-field-label">NFC UID</label><input className="ta-input" value={nfcBindForm.data.nfc_uid} onChange={(e) => nfcBindForm.setData('nfc_uid', e.target.value)} placeholder="Scan new UID" required />{fieldError(nfcBindForm, 'nfc_uid')}</div>
                    <button type="button" className="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-medium text-sky-700 disabled:opacity-50" onClick={() => readUidFromBridge('bind')} disabled={!canManage || nfcBridgeLoadingTarget !== null}>{nfcBridgeLoadingTarget === 'bind' ? 'Reading...' : 'Read UID'}</button>
                    <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={nfcBindForm.data.replace_existing} onChange={(e) => nfcBindForm.setData('replace_existing', e.target.checked)} />Replace existing binding if UID is already linked</label>
                    <button className="ta-btn-primary" disabled={nfcBindForm.processing || !canManage}>Bind NFC UID</button>
                </form>
            </section>

            <section className="ta-card overflow-hidden">
                <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">NFC card registry</h3></div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Card</th><th className="px-5 py-3">Type</th><th className="px-5 py-3">NFC UID</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Actions</th></tr></thead>
                        <tbody>{membershipCards.map((card) => <tr key={card.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-700">{card.customer_id == null ? <span className="text-amber-700">Inventory (unassigned)</span> : card.customer_name}<div className="text-xs text-slate-500">{card.customer_id == null ? '—' : card.customer_phone || 'No phone'}</div></td><td className="px-5 py-3 text-slate-600">{card.card_number || '—'}</td><td className="px-5 py-3 text-slate-600">{card.card_type_name}</td><td className="px-5 py-3 text-slate-600">{card.nfc_uid || 'Unbound'}</td><td className="px-5 py-3 text-slate-600">{card.status}</td><td className="px-5 py-3"><div className="flex gap-2"><button type="button" className="rounded-lg border border-sky-200 bg-sky-50 px-2.5 py-1 text-xs font-medium text-sky-700 disabled:cursor-not-allowed disabled:opacity-50" onClick={() => copyNfcPortalUrl(card.nfc_uid)} disabled={!card.nfc_uid}>Copy NFC URL</button><button type="button" className="rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 disabled:cursor-not-allowed disabled:opacity-50" onClick={() => openNfcPortalUrl(card.nfc_uid)} disabled={!card.nfc_uid}>Open NFC URL</button></div></td></tr>)}</tbody>
                    </table>
                </div>
            </section>

            <section className="ta-card overflow-hidden">
                <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Customer membership snapshot</h3></div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Points</th><th className="px-5 py-3">Tier</th><th className="px-5 py-3">Current card</th><th className="px-5 py-3">Eligible card</th><th className="px-5 py-3">Expiry</th></tr></thead>
                        <tbody>{customers.map((customer) => <tr key={customer.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-700">{customer.name}<div className="text-xs text-slate-500">{customer.phone}</div></td><td className="px-5 py-3 text-slate-600">{customer.points}</td><td className="px-5 py-3 text-slate-600">{customer.tier || 'None'}</td><td className="px-5 py-3 text-slate-600">{customer.current_card ? `${customer.current_card} (${customer.current_card_status || 'n/a'})` : 'None'}</td><td className="px-5 py-3 text-slate-600">{customer.eligible_card || 'None'}</td><td className="px-5 py-3 text-slate-600">{customer.card_expires_at ? new Date(customer.card_expires_at).toLocaleDateString() : 'No expiry'}</td></tr>)}</tbody>
                    </table>
                </div>
            </section>
        </div>
    );
}
