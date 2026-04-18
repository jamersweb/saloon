export default function GiftCardsSection({
    fieldError,
    canManage,
    giftCardForm,
    consumeGiftCardForm,
    giftNfcLookupForm,
    giftNfcLookupResult,
    giftNfcBindForm,
    customers,
    giftCards,
    recentGiftTransactions,
    appointmentsForRedeem,
    nfcBridgeLoadingTarget,
    readUidFromBridge,
}) {
    const selectedConsumeGiftCard = giftCards.find((c) => String(c.id) === String(consumeGiftCardForm.data.gift_card_id));
    const giftConsumeAppointments = (appointmentsForRedeem || []).filter((a) => {
        if (!selectedConsumeGiftCard?.assigned_customer_id) {
            return true;
        }
        return String(a.customer_id) === String(selectedConsumeGiftCard.assigned_customer_id);
    });

    return (
        <div className="space-y-6">
            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Issue gift card</h3>
                <form onSubmit={(e) => { e.preventDefault(); giftCardForm.post(route('loyalty.gift-cards.store'), { onSuccess: () => giftCardForm.reset('assigned_customer_id', 'initial_value', 'nfc_uid', 'notes') }); }} className="grid gap-3 md:grid-cols-6">
                    <div><label className="ta-field-label">Customer</label><select className="ta-input" value={giftCardForm.data.assigned_customer_id} onChange={(e) => giftCardForm.setData('assigned_customer_id', e.target.value)}><option value="">Unassigned</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}</select>{fieldError(giftCardForm, 'assigned_customer_id')}</div>
                    <div><label className="ta-field-label">Initial value</label><input className="ta-input" type="number" min="0.01" step="0.01" value={giftCardForm.data.initial_value} onChange={(e) => giftCardForm.setData('initial_value', e.target.value)} required />{fieldError(giftCardForm, 'initial_value')}</div>
                    <div><label className="ta-field-label">NFC UID</label><input className="ta-input" value={giftCardForm.data.nfc_uid} onChange={(e) => giftCardForm.setData('nfc_uid', e.target.value)} placeholder="Optional — physical NFC gift card" />{fieldError(giftCardForm, 'nfc_uid')}</div>
                    <button type="button" className="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-medium text-sky-700 disabled:opacity-50" onClick={() => readUidFromBridge('gift_issue')} disabled={!canManage || nfcBridgeLoadingTarget !== null}>{nfcBridgeLoadingTarget === 'gift_issue' ? 'Reading...' : 'Read UID'}</button>
                    <div><label className="ta-field-label">Notes</label><input className="ta-input" value={giftCardForm.data.notes} onChange={(e) => giftCardForm.setData('notes', e.target.value)} />{fieldError(giftCardForm, 'notes')}</div>
                    <button className="ta-btn-primary" disabled={giftCardForm.processing || !canManage}>Issue gift card</button>
                </form>
            </section>

            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Consume gift card</h3>
                <form onSubmit={(e) => { e.preventDefault(); consumeGiftCardForm.post(route('loyalty.gift-cards.consume', consumeGiftCardForm.data.gift_card_id), { onSuccess: () => consumeGiftCardForm.reset('amount', 'reason', 'notes', 'appointment_id') }); }} className="grid gap-3 md:grid-cols-2 lg:grid-cols-6">
                    <div><label className="ta-field-label">Gift card</label><select className="ta-input" value={consumeGiftCardForm.data.gift_card_id} onChange={(e) => { consumeGiftCardForm.setData('gift_card_id', e.target.value); consumeGiftCardForm.setData('appointment_id', ''); }} required><option value="">Select gift card</option>{giftCards.filter((card) => card.status === 'active').map((card) => <option key={card.id} value={card.id}>{card.code} ({card.remaining_value}){card.assigned_customer_id ? '' : ' — unassigned'}</option>)}</select>{fieldError(consumeGiftCardForm, 'gift_card_id')}</div>
                    <div className="lg:col-span-2">
                        <label className="ta-field-label">Link to visit (optional)</label>
                        <select className="ta-input" value={consumeGiftCardForm.data.appointment_id} onChange={(e) => consumeGiftCardForm.setData('appointment_id', e.target.value)} disabled={!consumeGiftCardForm.data.gift_card_id}>
                            <option value="">No visit link</option>
                            {giftConsumeAppointments.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}
                        </select>
                        <p className="mt-1 text-xs text-slate-500">If set, completing that visit will not auto-earn loyalty points (gift card payment).</p>
                        {fieldError(consumeGiftCardForm, 'appointment_id')}
                    </div>
                    <div><label className="ta-field-label">Amount</label><input className="ta-input" type="number" min="0.01" step="0.01" value={consumeGiftCardForm.data.amount} onChange={(e) => consumeGiftCardForm.setData('amount', e.target.value)} required />{fieldError(consumeGiftCardForm, 'amount')}</div>
                    <div><label className="ta-field-label">Reason</label><input className="ta-input" value={consumeGiftCardForm.data.reason} onChange={(e) => consumeGiftCardForm.setData('reason', e.target.value)} required />{fieldError(consumeGiftCardForm, 'reason')}</div>
                    <div><label className="ta-field-label">Notes</label><input className="ta-input" value={consumeGiftCardForm.data.notes} onChange={(e) => consumeGiftCardForm.setData('notes', e.target.value)} />{fieldError(consumeGiftCardForm, 'notes')}</div>
                    <button className="ta-btn-primary lg:col-span-6" disabled={consumeGiftCardForm.processing || !canManage || !consumeGiftCardForm.data.gift_card_id}>Consume gift card</button>
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
                    <div><label className="ta-field-label">Gift card</label><select className="ta-input" value={giftNfcBindForm.data.gift_card_id} onChange={(e) => giftNfcBindForm.setData('gift_card_id', e.target.value)} required><option value="">Select gift card</option>{giftCards.map((card) => <option key={card.id} value={card.id}>{card.code} ({card.remaining_value})</option>)}</select>{fieldError(giftNfcBindForm, 'gift_card_id')}</div>
                    <div><label className="ta-field-label">NFC UID</label><input className="ta-input" value={giftNfcBindForm.data.nfc_uid} onChange={(e) => giftNfcBindForm.setData('nfc_uid', e.target.value)} placeholder="Scan new UID" required />{fieldError(giftNfcBindForm, 'nfc_uid')}</div>
                    <button type="button" className="rounded-lg border border-sky-200 bg-sky-50 px-3 py-2 text-xs font-medium text-sky-700 disabled:opacity-50" onClick={() => readUidFromBridge('gift_bind')} disabled={!canManage || nfcBridgeLoadingTarget !== null}>{nfcBridgeLoadingTarget === 'gift_bind' ? 'Reading...' : 'Read UID'}</button>
                    <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={giftNfcBindForm.data.replace_existing} onChange={(e) => giftNfcBindForm.setData('replace_existing', e.target.checked)} />Replace existing binding if UID is already linked to another gift card</label>
                    <button className="ta-btn-primary" disabled={giftNfcBindForm.processing || !canManage}>Bind NFC UID</button>
                </form>
            </section>

            <section className="ta-card overflow-hidden">
                <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Gift cards</h3></div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Code</th><th className="px-5 py-3">NFC UID</th><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Initial</th><th className="px-5 py-3">Remaining</th><th className="px-5 py-3">Status</th></tr></thead>
                        <tbody>{giftCards.map((card) => <tr key={card.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-700">{card.code}</td><td className="px-5 py-3 text-slate-600">{card.nfc_uid || 'Unbound'}</td><td className="px-5 py-3 text-slate-600">{card.customer_name || 'Unassigned'}</td><td className="px-5 py-3 text-slate-600">{card.initial_value}</td><td className="px-5 py-3 text-slate-600">{card.remaining_value}</td><td className="px-5 py-3 text-slate-600">{card.status}</td></tr>)}</tbody>
                    </table>
                </div>
            </section>

            <section className="ta-card overflow-hidden">
                <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Gift card transactions</h3></div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Date</th><th className="px-5 py-3">Gift card</th><th className="px-5 py-3">Amount</th><th className="px-5 py-3">Balance</th><th className="px-5 py-3">Reason</th></tr></thead>
                        <tbody>{recentGiftTransactions.map((row) => <tr key={row.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-600">{new Date(row.created_at).toLocaleString()}</td><td className="px-5 py-3 text-slate-700">{row.gift_code}</td><td className="px-5 py-3 text-red-600">{row.amount_change}</td><td className="px-5 py-3 text-slate-700">{row.balance_after}</td><td className="px-5 py-3 text-slate-600">{row.reason}</td></tr>)}</tbody>
                    </table>
                </div>
            </section>
        </div>
    );
}
