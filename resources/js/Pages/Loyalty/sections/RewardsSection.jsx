export default function RewardsSection({
    fieldError,
    canManage,
    rewardForm,
    editRewardForm,
    editingRewardId,
    setEditingRewardId,
    startEditReward,
    redeemForm,
    customers,
    rewards,
    recentRedemptions,
    salonServices,
    appointmentsForRedeem,
}) {
    const selectedRedeemReward = rewards.find((r) => String(r.id) === String(redeemForm.data.loyalty_reward_id));
    const redeemQuantityMax = Math.min(20, selectedRedeemReward?.max_units_per_redemption ?? 20);
    const redeemAppointments = (appointmentsForRedeem || []).filter((a) => String(a.customer_id) === String(redeemForm.data.customer_id));
    const allowedServiceIdsForRedeem = (selectedRedeemReward?.allowed_salon_services || []).map((s) => s.id);
    const filteredRedeemAppointments =
        allowedServiceIdsForRedeem.length > 0
            ? redeemAppointments.filter((a) => allowedServiceIdsForRedeem.includes(a.service_id))
            : redeemAppointments;

    return (
        <div className="space-y-6">
            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Reward catalog</h3>
                <form onSubmit={(e) => { e.preventDefault(); rewardForm.post(route('loyalty.rewards.store'), { onSuccess: () => rewardForm.reset('name', 'description', 'max_units_per_redemption', 'max_redemptions_per_calendar_month', 'min_days_between_redemptions', 'salon_service_ids') }); }} className="grid gap-3 md:grid-cols-3 lg:grid-cols-6">
                    <div><label className="ta-field-label">Reward name</label><input className="ta-input" placeholder="Reward name" value={rewardForm.data.name} onChange={(e) => rewardForm.setData('name', e.target.value)} required />{fieldError(rewardForm, 'name')}</div>
                    <div><label className="ta-field-label">Description</label><input className="ta-input" placeholder="Description" value={rewardForm.data.description} onChange={(e) => rewardForm.setData('description', e.target.value)} />{fieldError(rewardForm, 'description')}</div>
                    <div><label className="ta-field-label">Points cost</label><input className="ta-input" type="number" min="1" placeholder="Points cost" value={rewardForm.data.points_cost} onChange={(e) => rewardForm.setData('points_cost', e.target.value)} required />{fieldError(rewardForm, 'points_cost')}</div>
                    <div><label className="ta-field-label">Stock</label><input className="ta-input" type="number" min="0" placeholder="Stock (optional)" value={rewardForm.data.stock_quantity ?? ''} onChange={(e) => rewardForm.setData('stock_quantity', e.target.value === '' ? null : e.target.value)} />{fieldError(rewardForm, 'stock_quantity')}</div>
                    <div><label className="ta-field-label">Max units / redemption</label><input className="ta-input" type="number" min="1" max="20" placeholder="Default 20" value={rewardForm.data.max_units_per_redemption ?? ''} onChange={(e) => rewardForm.setData('max_units_per_redemption', e.target.value)} />{fieldError(rewardForm, 'max_units_per_redemption')}</div>
                    <div><label className="ta-field-label">Max qty / calendar month</label><input className="ta-input" type="number" min="1" placeholder="Unlimited if empty" value={rewardForm.data.max_redemptions_per_calendar_month ?? ''} onChange={(e) => rewardForm.setData('max_redemptions_per_calendar_month', e.target.value)} />{fieldError(rewardForm, 'max_redemptions_per_calendar_month')}</div>
                    <div><label className="ta-field-label">Min days between</label><input className="ta-input" type="number" min="1" placeholder="No cooldown if empty" value={rewardForm.data.min_days_between_redemptions ?? ''} onChange={(e) => rewardForm.setData('min_days_between_redemptions', e.target.value)} />{fieldError(rewardForm, 'min_days_between_redemptions')}</div>
                    <label className="flex items-center text-sm text-slate-600 lg:col-span-2"><input type="checkbox" className="mr-2" checked={rewardForm.data.requires_appointment_id} onChange={(e) => rewardForm.setData('requires_appointment_id', e.target.checked)} />Require visit (appointment) to redeem — one redemption per appointment</label>
                    <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={rewardForm.data.is_active} onChange={(e) => rewardForm.setData('is_active', e.target.checked)} />Active</label>
                    <div className="md:col-span-3 lg:col-span-6">
                        <label className="ta-field-label">Eligible services (optional)</label>
                        <p className="mb-1 text-xs text-slate-500">If you pick any, staff must link redemption to a visit using one of these services (one redemption per visit).</p>
                        <select
                            multiple
                            className="ta-input min-h-[120px] py-2"
                            value={rewardForm.data.salon_service_ids.map(String)}
                            onChange={(e) => {
                                const ids = Array.from(e.target.selectedOptions).map((o) => parseInt(o.value, 10));
                                rewardForm.setData('salon_service_ids', ids);
                            }}
                        >
                            {(salonServices || []).map((svc) => (
                                <option key={svc.id} value={svc.id}>
                                    {svc.name}
                                    {svc.category ? ` (${svc.category})` : ''}
                                </option>
                            ))}
                        </select>
                        {fieldError(rewardForm, 'salon_service_ids')}
                    </div>
                    <button className="ta-btn-primary md:col-span-2 lg:col-span-6" disabled={rewardForm.processing || !canManage}>Add reward</button>
                </form>

                <div className="mt-4 overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-2">Reward</th><th className="px-4 py-2">Points</th><th className="px-4 py-2">Rules</th><th className="px-4 py-2">Stock</th><th className="px-4 py-2">Status</th><th className="px-4 py-2">Actions</th></tr></thead>
                        <tbody>{rewards.map((reward) => <tr key={reward.id} className="border-t border-slate-100"><td className="px-4 py-2 text-slate-700">{reward.name}</td><td className="px-4 py-2 text-slate-600">{reward.points_cost}</td><td className="px-4 py-2 text-xs text-slate-600"><div>Max/redeem: {reward.max_units_per_redemption ?? '20'}</div><div>Max/mo: {reward.max_redemptions_per_calendar_month ?? '—'}</div><div>Gap: {reward.min_days_between_redemptions != null ? `${reward.min_days_between_redemptions}d` : '—'}</div>{reward.requires_appointment_id ? <div className="mt-0.5 font-medium text-amber-700">Per visit</div> : null}{(reward.allowed_salon_services || []).length ? <div className="mt-0.5 text-sky-800">Services: {(reward.allowed_salon_services || []).map((s) => s.name).join(', ')}</div> : null}</td><td className="px-4 py-2 text-slate-600">{reward.stock_quantity ?? 'Unlimited'}</td><td className="px-4 py-2"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${reward.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700'}`}>{reward.is_active ? 'Active' : 'Inactive'}</span></td><td className="px-4 py-2"><button type="button" className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs text-indigo-700" onClick={() => startEditReward(reward)}>Edit</button></td></tr>)}</tbody>
                    </table>
                </div>
            </section>

            {editingRewardId && (
                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Edit reward #{editingRewardId}</h3>
                    <form onSubmit={(e) => { e.preventDefault(); editRewardForm.put(route('loyalty.rewards.update', editingRewardId), { onSuccess: () => setEditingRewardId(null) }); }} className="grid gap-3 md:grid-cols-3 lg:grid-cols-6">
                        <div><label className="ta-field-label">Name</label><input className="ta-input" value={editRewardForm.data.name} onChange={(e) => editRewardForm.setData('name', e.target.value)} required />{fieldError(editRewardForm, 'name')}</div>
                        <div><label className="ta-field-label">Description</label><input className="ta-input" value={editRewardForm.data.description} onChange={(e) => editRewardForm.setData('description', e.target.value)} />{fieldError(editRewardForm, 'description')}</div>
                        <div><label className="ta-field-label">Points cost</label><input className="ta-input" type="number" min="1" value={editRewardForm.data.points_cost} onChange={(e) => editRewardForm.setData('points_cost', e.target.value)} required />{fieldError(editRewardForm, 'points_cost')}</div>
                        <div><label className="ta-field-label">Stock quantity</label><input className="ta-input" type="number" min="0" value={editRewardForm.data.stock_quantity ?? ''} onChange={(e) => editRewardForm.setData('stock_quantity', e.target.value === '' ? null : e.target.value)} />{fieldError(editRewardForm, 'stock_quantity')}</div>
                        <div><label className="ta-field-label">Max units / redemption</label><input className="ta-input" type="number" min="1" max="20" value={editRewardForm.data.max_units_per_redemption ?? ''} onChange={(e) => editRewardForm.setData('max_units_per_redemption', e.target.value)} />{fieldError(editRewardForm, 'max_units_per_redemption')}</div>
                        <div><label className="ta-field-label">Max qty / calendar month</label><input className="ta-input" type="number" min="1" value={editRewardForm.data.max_redemptions_per_calendar_month ?? ''} onChange={(e) => editRewardForm.setData('max_redemptions_per_calendar_month', e.target.value)} />{fieldError(editRewardForm, 'max_redemptions_per_calendar_month')}</div>
                        <div><label className="ta-field-label">Min days between</label><input className="ta-input" type="number" min="1" value={editRewardForm.data.min_days_between_redemptions ?? ''} onChange={(e) => editRewardForm.setData('min_days_between_redemptions', e.target.value)} />{fieldError(editRewardForm, 'min_days_between_redemptions')}</div>
                        <label className="flex items-center text-sm text-slate-600 lg:col-span-2"><input type="checkbox" checked={editRewardForm.data.requires_appointment_id} onChange={(e) => editRewardForm.setData('requires_appointment_id', e.target.checked)} className="mr-2" />Require visit (appointment)</label>
                        <div className="flex items-center"><label className="text-sm text-slate-600"><input type="checkbox" checked={editRewardForm.data.is_active} onChange={(e) => editRewardForm.setData('is_active', e.target.checked)} className="mr-2" />Active</label></div>
                        <div className="md:col-span-6">
                            <label className="ta-field-label">Eligible services (optional)</label>
                            <select
                                multiple
                                className="ta-input min-h-[120px] py-2"
                                value={editRewardForm.data.salon_service_ids.map(String)}
                                onChange={(e) => {
                                    const ids = Array.from(e.target.selectedOptions).map((o) => parseInt(o.value, 10));
                                    editRewardForm.setData('salon_service_ids', ids);
                                }}
                            >
                                {(salonServices || []).map((svc) => (
                                    <option key={svc.id} value={svc.id}>
                                        {svc.name}
                                        {svc.category ? ` (${svc.category})` : ''}
                                    </option>
                                ))}
                            </select>
                            {fieldError(editRewardForm, 'salon_service_ids')}
                        </div>
                        <div className="md:col-span-6 flex gap-2"><button className="ta-btn-primary" disabled={editRewardForm.processing || !canManage}>Save</button><button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setEditingRewardId(null)}>Cancel</button></div>
                    </form>
                </section>
            )}

            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Redeem reward</h3>
                <form onSubmit={(e) => { e.preventDefault(); redeemForm.post(route('loyalty.redeem'), { onSuccess: () => redeemForm.reset('quantity', 'appointment_id') }); }} className="grid gap-3 md:grid-cols-2 lg:grid-cols-5">
                    <div><label className="ta-field-label">Customer</label><select className="ta-input" value={redeemForm.data.customer_id} onChange={(e) => { redeemForm.setData('customer_id', e.target.value); redeemForm.setData('appointment_id', ''); }} required><option value="">Select customer</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name} ({customer.points} pts)</option>)}</select>{fieldError(redeemForm, 'customer_id')}</div>
                    <div><label className="ta-field-label">Loyalty reward</label><select className="ta-input" value={redeemForm.data.loyalty_reward_id} onChange={(e) => { redeemForm.setData('loyalty_reward_id', e.target.value); redeemForm.setData('appointment_id', ''); }} required><option value="">Select reward</option>{rewards.filter((r) => r.is_active).map((reward) => <option key={reward.id} value={reward.id}>{reward.name} ({reward.points_cost} pts)</option>)}</select>{fieldError(redeemForm, 'loyalty_reward_id')}</div>
                    <div><label className="ta-field-label">Visit {(selectedRedeemReward?.requires_appointment_id || allowedServiceIdsForRedeem.length > 0) ? <span className="text-red-600">*</span> : <span className="text-slate-400">(optional)</span>}</label><select className="ta-input" value={redeemForm.data.appointment_id} onChange={(e) => redeemForm.setData('appointment_id', e.target.value)} disabled={!redeemForm.data.customer_id}><option value="">{redeemForm.data.customer_id ? 'Select appointment (last 120 days)' : 'Select customer first'}</option>{filteredRedeemAppointments.map((a) => <option key={a.id} value={a.id}>{a.label}</option>)}</select>{fieldError(redeemForm, 'appointment_id')}</div>
                    <div><label className="ta-field-label">Quantity</label><input className="ta-input" type="number" min="1" max={redeemQuantityMax} value={redeemForm.data.quantity} onChange={(e) => redeemForm.setData('quantity', e.target.value)} required />{fieldError(redeemForm, 'quantity')}</div>
                    <button className="ta-btn-primary self-end" disabled={redeemForm.processing || !canManage}>Redeem</button>
                </form>
            </section>

            <section className="ta-card overflow-hidden">
                <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Recent redemptions</h3></div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Date</th><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Reward</th><th className="px-5 py-3">Visit</th><th className="px-5 py-3">Points</th><th className="px-5 py-3">By</th></tr></thead>
                        <tbody>{recentRedemptions.map((row) => <tr key={row.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-600">{new Date(row.created_at).toLocaleString()}</td><td className="px-5 py-3 text-slate-700">{row.customer_name}</td><td className="px-5 py-3 text-slate-600">{row.reward_name}{row.quantity > 1 ? ` ×${row.quantity}` : ''}</td><td className="px-5 py-3 text-slate-600">{row.visit_label || '—'}</td><td className="px-5 py-3 font-semibold text-red-600">-{row.points_spent}</td><td className="px-5 py-3 text-slate-600">{row.redeemed_by || '-'}</td></tr>)}</tbody>
                    </table>
                </div>
            </section>
        </div>
    );
}
