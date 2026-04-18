export default function PointsSection({
    fieldError,
    canManage,
    bonusForm,
    pointsForm,
    customers,
    recentLedgers,
}) {
    return (
        <div className="space-y-6">
            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Award configured bonus</h3>
                <p className="mb-3 text-xs text-slate-600">Uses bonus point amounts from Program and tiers → Auto earn rules (referral, review, birthday).</p>
                <form onSubmit={(e) => { e.preventDefault(); bonusForm.post(route('loyalty.bonus.award')); }} className="grid gap-3 md:grid-cols-3">
                    <div><label className="ta-field-label">Customer</label><select className="ta-input" value={bonusForm.data.customer_id} onChange={(e) => bonusForm.setData('customer_id', e.target.value)} required><option value="">Select customer</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name} ({customer.points} pts)</option>)}</select>{fieldError(bonusForm, 'customer_id')}</div>
                    <div><label className="ta-field-label">Bonus type</label><select className="ta-input" value={bonusForm.data.bonus_type} onChange={(e) => bonusForm.setData('bonus_type', e.target.value)}><option value="referral">Referral</option><option value="review">Review</option><option value="birthday">Birthday</option></select>{fieldError(bonusForm, 'bonus_type')}</div>
                    <button className="ta-btn-primary" disabled={bonusForm.processing || !canManage}>Award bonus</button>
                </form>
            </section>

            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Add / deduct points</h3>
                <form onSubmit={(e) => { e.preventDefault(); pointsForm.post(route('loyalty.ledger.store'), { onSuccess: () => pointsForm.reset('points_change', 'reason', 'reference', 'notes') }); }} className="grid gap-3 md:grid-cols-5">
                    <div><label className="ta-field-label">Customer</label><select className="ta-input" value={pointsForm.data.customer_id} onChange={(e) => pointsForm.setData('customer_id', e.target.value)} required><option value="">Select customer</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name} ({customer.points} pts)</option>)}</select>{fieldError(pointsForm, 'customer_id')}</div>
                    <div><label className="ta-field-label">Points</label><input className="ta-input" type="number" placeholder="Points (+/-)" value={pointsForm.data.points_change} onChange={(e) => pointsForm.setData('points_change', e.target.value)} required />{fieldError(pointsForm, 'points_change')}</div>
                    <div><label className="ta-field-label">Reason</label><input className="ta-input" placeholder="Reason" value={pointsForm.data.reason} onChange={(e) => pointsForm.setData('reason', e.target.value)} required />{fieldError(pointsForm, 'reason')}</div>
                    <div><label className="ta-field-label">Reference</label><input className="ta-input" placeholder="Reference" value={pointsForm.data.reference} onChange={(e) => pointsForm.setData('reference', e.target.value)} />{fieldError(pointsForm, 'reference')}</div>
                    <button className="ta-btn-primary" disabled={pointsForm.processing || !canManage}>Apply</button>
                </form>
            </section>

            <section className="ta-card overflow-hidden">
                <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Recent loyalty ledger</h3></div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Date</th><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Change</th><th className="px-5 py-3">Balance</th><th className="px-5 py-3">Reason</th><th className="px-5 py-3">By</th></tr></thead>
                        <tbody>{recentLedgers.map((row) => <tr key={row.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-600">{new Date(row.created_at).toLocaleString()}</td><td className="px-5 py-3 text-slate-700">{row.customer_name}</td><td className={`px-5 py-3 font-semibold ${row.points_change >= 0 ? 'text-emerald-600' : 'text-red-600'}`}>{row.points_change >= 0 ? `+${row.points_change}` : row.points_change}</td><td className="px-5 py-3 text-slate-700">{row.balance_after}</td><td className="px-5 py-3 text-slate-600">{row.reason}</td><td className="px-5 py-3 text-slate-600">{row.created_by || '-'}</td></tr>)}</tbody>
                    </table>
                </div>
            </section>
        </div>
    );
}
