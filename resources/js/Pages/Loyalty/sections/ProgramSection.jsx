export default function ProgramSection({
    fieldError,
    canManage,
    settingsForm,
    createTierForm,
    tiers,
    editingTierId,
    startEditTier,
    editTierForm,
    setEditingTierId,
}) {
    return (
        <div className="space-y-6">
            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Auto earn rules</h3>
                <p className="mb-3 text-xs text-slate-600">How customers earn points from visits and spend. These apply when appointments are completed.</p>
                <p className="mb-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
                    Vina loyalty card PDF: tiers are Queen (10% / 1×), Titanium (15% / 1.5×), Gold (30% / 3×). Match that here under loyalty tiers. For ~1 point per AED 10 net before the tier multiplier, set{' '}
                    <span className="font-medium text-slate-700">points per currency</span> to <span className="font-mono text-slate-800">0.1</span> (seeded default). PDF welcome bonuses (100 / 200 / 300 pts) are not auto-issued yet—record them once from the Points section if you use them.
                </p>
                <form onSubmit={(e) => { e.preventDefault(); settingsForm.patch(route('loyalty.settings.update')); }} className="grid gap-3 md:grid-cols-5">
                    <div className="flex items-center"><label className="text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={settingsForm.data.auto_earn_enabled} onChange={(e) => settingsForm.setData('auto_earn_enabled', e.target.checked)} />Enable auto earn on completed appointment</label>{fieldError(settingsForm, 'auto_earn_enabled')}</div>
                    <div><label className="ta-field-label">Points per currency</label><input className="ta-input" type="number" min="0" step="0.01" placeholder="Points per currency" value={settingsForm.data.points_per_currency} onChange={(e) => settingsForm.setData('points_per_currency', e.target.value)} required />{fieldError(settingsForm, 'points_per_currency')}</div>
                    <div><label className="ta-field-label">Points per visit</label><input className="ta-input" type="number" min="0" step="1" placeholder="Points per visit" value={settingsForm.data.points_per_visit} onChange={(e) => settingsForm.setData('points_per_visit', e.target.value)} required />{fieldError(settingsForm, 'points_per_visit')}</div>
                    <div><label className="ta-field-label">Minimum spend</label><input className="ta-input" type="number" min="0" step="0.01" placeholder="Minimum spend" value={settingsForm.data.minimum_spend} onChange={(e) => settingsForm.setData('minimum_spend', e.target.value)} required />{fieldError(settingsForm, 'minimum_spend')}</div>
                    <div><label className="ta-field-label">Rounding mode</label><select className="ta-input" value={settingsForm.data.rounding_mode} onChange={(e) => settingsForm.setData('rounding_mode', e.target.value)}><option value="floor">Floor</option><option value="round">Round</option><option value="ceil">Ceil</option></select>{fieldError(settingsForm, 'rounding_mode')}</div>
                    <div><label className="ta-field-label">Birthday bonus</label><input className="ta-input" type="number" min="0" step="1" placeholder="Birthday bonus" value={settingsForm.data.birthday_bonus_points} onChange={(e) => settingsForm.setData('birthday_bonus_points', e.target.value)} required />{fieldError(settingsForm, 'birthday_bonus_points')}</div>
                    <div><label className="ta-field-label">Referral bonus</label><input className="ta-input" type="number" min="0" step="1" placeholder="Referral bonus" value={settingsForm.data.referral_bonus_points} onChange={(e) => settingsForm.setData('referral_bonus_points', e.target.value)} required />{fieldError(settingsForm, 'referral_bonus_points')}</div>
                    <div><label className="ta-field-label">Review bonus</label><input className="ta-input" type="number" min="0" step="1" placeholder="Review bonus" value={settingsForm.data.review_bonus_points} onChange={(e) => settingsForm.setData('review_bonus_points', e.target.value)} required />{fieldError(settingsForm, 'review_bonus_points')}</div>
                    <button className="ta-btn-primary" disabled={settingsForm.processing || !canManage}>Save rules</button>
                </form>
            </section>

            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Create loyalty tier</h3>
                <form onSubmit={(e) => { e.preventDefault(); createTierForm.post(route('loyalty.tiers.store'), { onSuccess: () => createTierForm.reset('name') }); }} className="grid gap-3 md:grid-cols-5">
                    <div><label className="ta-field-label">Tier name</label><input className="ta-input" placeholder="Tier name" value={createTierForm.data.name} onChange={(e) => createTierForm.setData('name', e.target.value)} required />{fieldError(createTierForm, 'name')}</div>
                    <div><label className="ta-field-label">Min points</label><input className="ta-input" type="number" min="0" placeholder="Min points" value={createTierForm.data.min_points} onChange={(e) => createTierForm.setData('min_points', e.target.value)} required />{fieldError(createTierForm, 'min_points')}</div>
                    <div><label className="ta-field-label">Discount %</label><input className="ta-input" type="number" min="0" max="100" step="0.01" placeholder="Discount %" value={createTierForm.data.discount_percent} onChange={(e) => createTierForm.setData('discount_percent', e.target.value)} required />{fieldError(createTierForm, 'discount_percent')}</div>
                    <div><label className="ta-field-label">Earn multiplier</label><input className="ta-input" type="number" min="0.1" max="5" step="0.01" placeholder="Earn multiplier" value={createTierForm.data.earn_multiplier} onChange={(e) => createTierForm.setData('earn_multiplier', e.target.value)} required />{fieldError(createTierForm, 'earn_multiplier')}</div>
                    <div className="flex items-center"><label className="text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={createTierForm.data.is_active} onChange={(e) => createTierForm.setData('is_active', e.target.checked)} />Active</label></div>
                    <button className="ta-btn-primary" disabled={createTierForm.processing || !canManage}>Add tier</button>
                </form>
            </section>

            <section className="ta-card overflow-hidden">
                <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Tier rules</h3></div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Tier</th><th className="px-5 py-3">Min points</th><th className="px-5 py-3">Discount</th><th className="px-5 py-3">Multiplier</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Actions</th></tr></thead>
                        <tbody>
                            {tiers.map((tier) => (
                                <tr key={tier.id} className="border-t border-slate-100"><td className="px-5 py-3 font-medium text-slate-700">{tier.name}</td><td className="px-5 py-3 text-slate-600">{tier.min_points}</td><td className="px-5 py-3 text-slate-600">{tier.discount_percent}%</td><td className="px-5 py-3 text-slate-600">{tier.earn_multiplier}x</td><td className="px-5 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${tier.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700'}`}>{tier.is_active ? 'Active' : 'Inactive'}</span></td><td className="px-5 py-3"><button type="button" className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 disabled:opacity-50" disabled={!canManage} onClick={() => startEditTier(tier)}>Edit</button></td></tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>

            {editingTierId && (
                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Edit tier #{editingTierId}</h3>
                    <form onSubmit={(e) => { e.preventDefault(); editTierForm.put(route('loyalty.tiers.update', editingTierId), { onSuccess: () => setEditingTierId(null) }); }} className="grid gap-3 md:grid-cols-5">
                        <div><label className="ta-field-label">Name</label><input className="ta-input" value={editTierForm.data.name} onChange={(e) => editTierForm.setData('name', e.target.value)} required />{fieldError(editTierForm, 'name')}</div>
                        <div><label className="ta-field-label">Min points</label><input className="ta-input" type="number" min="0" value={editTierForm.data.min_points} onChange={(e) => editTierForm.setData('min_points', e.target.value)} required />{fieldError(editTierForm, 'min_points')}</div>
                        <div><label className="ta-field-label">Discount percent</label><input className="ta-input" type="number" min="0" max="100" step="0.01" value={editTierForm.data.discount_percent} onChange={(e) => editTierForm.setData('discount_percent', e.target.value)} required />{fieldError(editTierForm, 'discount_percent')}</div>
                        <div><label className="ta-field-label">Earn multiplier</label><input className="ta-input" type="number" min="0.1" max="5" step="0.01" value={editTierForm.data.earn_multiplier} onChange={(e) => editTierForm.setData('earn_multiplier', e.target.value)} required />{fieldError(editTierForm, 'earn_multiplier')}</div>
                        <div className="flex items-center"><label className="text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={editTierForm.data.is_active} onChange={(e) => editTierForm.setData('is_active', e.target.checked)} />Active</label></div>
                        <div className="flex gap-2"><button className="ta-btn-primary" disabled={editTierForm.processing || !canManage}>Save</button><button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setEditingTierId(null)}>Cancel</button></div>
                    </form>
                </section>
            )}
        </div>
    );
}
