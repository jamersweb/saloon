import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;

export default function LoyaltyIndex({ tiers, cardTypes, packages, customers, customerPackages, giftCards, recentGiftTransactions, recentLedgers, rewards, recentRedemptions, settings }) {
    const { flash, auth } = usePage().props;
    const canManage = Boolean(auth?.permissions?.can_manage_loyalty);
    const [editingTierId, setEditingTierId] = useState(null);
    const [editingCardTypeId, setEditingCardTypeId] = useState(null);
    const [editingRewardId, setEditingRewardId] = useState(null);

    const createTierForm = useForm({ name: '', min_points: 0, discount_percent: 0, earn_multiplier: 1, is_active: true });
    const editTierForm = useForm({ name: '', min_points: 0, discount_percent: 0, earn_multiplier: 1, is_active: true });
    const createCardTypeForm = useForm({ name: '', slug: '', kind: 'physical', min_points: 0, direct_purchase_price: '', validity_days: '', is_active: true, is_transferable: false });
    const editCardTypeForm = useForm({ name: '', slug: '', kind: 'physical', min_points: 0, direct_purchase_price: '', validity_days: '', is_active: true, is_transferable: false });
    const assignCardForm = useForm({ customer_id: '', membership_card_type_id: '', card_number: '', nfc_uid: '', status: 'active', notes: '' });
    const packageForm = useForm({ name: '', description: '', usage_limit: '', initial_value: '', validity_days: '', is_active: true });
    const assignPackageForm = useForm({ customer_id: '', service_package_id: '', notes: '' });
    const consumePackageForm = useForm({ customer_package_id: '', sessions_used: 0, value_used: 0, notes: '' });
    const giftCardForm = useForm({ assigned_customer_id: '', initial_value: '', notes: '' });
    const consumeGiftCardForm = useForm({ gift_card_id: '', amount: '', reason: '', notes: '' });
    const pointsForm = useForm({ customer_id: '', points_change: '', reason: '', reference: '', notes: '' });
    const rewardForm = useForm({ name: '', description: '', points_cost: 50, stock_quantity: '', is_active: true });
    const editRewardForm = useForm({ name: '', description: '', points_cost: 50, stock_quantity: '', is_active: true });
    const redeemForm = useForm({ customer_id: '', loyalty_reward_id: '', quantity: 1 });
    const bonusForm = useForm({ customer_id: '', bonus_type: 'referral' });
    const settingsForm = useForm({
        auto_earn_enabled: Boolean(settings?.auto_earn_enabled),
        points_per_currency: settings?.points_per_currency ?? 1,
        points_per_visit: settings?.points_per_visit ?? 0,
        birthday_bonus_points: settings?.birthday_bonus_points ?? 0,
        referral_bonus_points: settings?.referral_bonus_points ?? 0,
        review_bonus_points: settings?.review_bonus_points ?? 0,
        minimum_spend: settings?.minimum_spend ?? 0,
        rounding_mode: settings?.rounding_mode ?? 'floor',
    });

    const startEditTier = (tier) => {
        setEditingTierId(tier.id);
        editTierForm.setData({
            name: tier.name,
            min_points: tier.min_points,
            discount_percent: tier.discount_percent,
            earn_multiplier: tier.earn_multiplier ?? 1,
            is_active: Boolean(tier.is_active),
        });
        editTierForm.clearErrors();
    };

    const startEditReward = (reward) => {
        setEditingRewardId(reward.id);
        editRewardForm.setData({
            name: reward.name,
            description: reward.description || '',
            points_cost: reward.points_cost,
            stock_quantity: reward.stock_quantity ?? '',
            is_active: Boolean(reward.is_active),
        });
        editRewardForm.clearErrors();
    };

    const startEditCardType = (cardType) => {
        setEditingCardTypeId(cardType.id);
        editCardTypeForm.setData({
            name: cardType.name,
            slug: cardType.slug,
            kind: cardType.kind,
            min_points: cardType.min_points,
            direct_purchase_price: cardType.direct_purchase_price ?? '',
            validity_days: cardType.validity_days ?? '',
            is_active: Boolean(cardType.is_active),
            is_transferable: Boolean(cardType.is_transferable),
        });
        editCardTypeForm.clearErrors();
    };

    return (
        <AuthenticatedLayout header="Loyalty Program">
            <Head title="Loyalty" />

            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Auto Earn Rules</h3>
                    <form onSubmit={(e) => { e.preventDefault(); settingsForm.patch(route('loyalty.settings.update')); }} className="grid gap-3 md:grid-cols-5">
                        <div className="flex items-center"><label className="text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={settingsForm.data.auto_earn_enabled} onChange={(e) => settingsForm.setData('auto_earn_enabled', e.target.checked)} />Enable auto earn on completed appointment</label>{fieldError(settingsForm, 'auto_earn_enabled')}</div>
                        <div><label className="ta-field-label">Points Per Currency</label><input className="ta-input" type="number" min="0" step="0.01" placeholder="Points per currency" value={settingsForm.data.points_per_currency} onChange={(e) => settingsForm.setData('points_per_currency', e.target.value)} required />{fieldError(settingsForm, 'points_per_currency')}</div>
                        <div><label className="ta-field-label">Points Per Visit</label><input className="ta-input" type="number" min="0" step="1" placeholder="Points per visit" value={settingsForm.data.points_per_visit} onChange={(e) => settingsForm.setData('points_per_visit', e.target.value)} required />{fieldError(settingsForm, 'points_per_visit')}</div>
                        <div><label className="ta-field-label">Minimum Spend</label><input className="ta-input" type="number" min="0" step="0.01" placeholder="Minimum spend" value={settingsForm.data.minimum_spend} onChange={(e) => settingsForm.setData('minimum_spend', e.target.value)} required />{fieldError(settingsForm, 'minimum_spend')}</div>
                        <div><label className="ta-field-label">Rounding Mode</label><select className="ta-input" value={settingsForm.data.rounding_mode} onChange={(e) => settingsForm.setData('rounding_mode', e.target.value)}><option value="floor">Floor</option><option value="round">Round</option><option value="ceil">Ceil</option></select>{fieldError(settingsForm, 'rounding_mode')}</div>
                        <div><label className="ta-field-label">Birthday Bonus</label><input className="ta-input" type="number" min="0" step="1" placeholder="Birthday bonus" value={settingsForm.data.birthday_bonus_points} onChange={(e) => settingsForm.setData('birthday_bonus_points', e.target.value)} required />{fieldError(settingsForm, 'birthday_bonus_points')}</div>
                        <div><label className="ta-field-label">Referral Bonus</label><input className="ta-input" type="number" min="0" step="1" placeholder="Referral bonus" value={settingsForm.data.referral_bonus_points} onChange={(e) => settingsForm.setData('referral_bonus_points', e.target.value)} required />{fieldError(settingsForm, 'referral_bonus_points')}</div>
                        <div><label className="ta-field-label">Review Bonus</label><input className="ta-input" type="number" min="0" step="1" placeholder="Review bonus" value={settingsForm.data.review_bonus_points} onChange={(e) => settingsForm.setData('review_bonus_points', e.target.value)} required />{fieldError(settingsForm, 'review_bonus_points')}</div>
                        <button className="ta-btn-primary" disabled={settingsForm.processing || !canManage}>Save Rules</button>
                    </form>
                </section>

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Create Loyalty Tier</h3>
                    <form onSubmit={(e) => { e.preventDefault(); createTierForm.post(route('loyalty.tiers.store'), { onSuccess: () => createTierForm.reset('name') }); }} className="grid gap-3 md:grid-cols-5">
                        <div><label className="ta-field-label">Tier Name</label><input className="ta-input" placeholder="Tier name" value={createTierForm.data.name} onChange={(e) => createTierForm.setData('name', e.target.value)} required />{fieldError(createTierForm, 'name')}</div>
                        <div><label className="ta-field-label">Min Points</label><input className="ta-input" type="number" min="0" placeholder="Min points" value={createTierForm.data.min_points} onChange={(e) => createTierForm.setData('min_points', e.target.value)} required />{fieldError(createTierForm, 'min_points')}</div>
                        <div><label className="ta-field-label">Discount %</label><input className="ta-input" type="number" min="0" max="100" step="0.01" placeholder="Discount %" value={createTierForm.data.discount_percent} onChange={(e) => createTierForm.setData('discount_percent', e.target.value)} required />{fieldError(createTierForm, 'discount_percent')}</div>
                        <div><label className="ta-field-label">Earn Multiplier</label><input className="ta-input" type="number" min="0.1" max="5" step="0.01" placeholder="Earn multiplier" value={createTierForm.data.earn_multiplier} onChange={(e) => createTierForm.setData('earn_multiplier', e.target.value)} required />{fieldError(createTierForm, 'earn_multiplier')}</div>
                        <div className="flex items-center"><label className="text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={createTierForm.data.is_active} onChange={(e) => createTierForm.setData('is_active', e.target.checked)} />Active</label></div>
                        <button className="ta-btn-primary" disabled={createTierForm.processing || !canManage}>Add Tier</button>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Tier Rules</h3></div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Tier</th><th className="px-5 py-3">Min Points</th><th className="px-5 py-3">Discount</th><th className="px-5 py-3">Multiplier</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Actions</th></tr></thead>
                            <tbody>
                                {tiers.map((tier) => (
                                    <tr key={tier.id} className="border-t border-slate-100"><td className="px-5 py-3 font-medium text-slate-700">{tier.name}</td><td className="px-5 py-3 text-slate-600">{tier.min_points}</td><td className="px-5 py-3 text-slate-600">{tier.discount_percent}%</td><td className="px-5 py-3 text-slate-600">{tier.earn_multiplier}x</td><td className="px-5 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${tier.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700'}`}>{tier.is_active ? 'Active' : 'Inactive'}</span></td><td className="px-5 py-3"><button className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 disabled:opacity-50" disabled={!canManage} onClick={() => startEditTier(tier)}>Edit</button></td></tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                {editingTierId && (
                    <section className="ta-card p-5">
                        <h3 className="mb-4 text-sm font-semibold text-slate-700">Edit Tier #{editingTierId}</h3>
                        <form onSubmit={(e) => { e.preventDefault(); editTierForm.put(route('loyalty.tiers.update', editingTierId), { onSuccess: () => setEditingTierId(null) }); }} className="grid gap-3 md:grid-cols-5">
                            <div><label className="ta-field-label">Name</label><input className="ta-input" value={editTierForm.data.name} onChange={(e) => editTierForm.setData('name', e.target.value)} required />{fieldError(editTierForm, 'name')}</div>
                            <div><label className="ta-field-label">Min Points</label><input className="ta-input" type="number" min="0" value={editTierForm.data.min_points} onChange={(e) => editTierForm.setData('min_points', e.target.value)} required />{fieldError(editTierForm, 'min_points')}</div>
                            <div><label className="ta-field-label">Discount Percent</label><input className="ta-input" type="number" min="0" max="100" step="0.01" value={editTierForm.data.discount_percent} onChange={(e) => editTierForm.setData('discount_percent', e.target.value)} required />{fieldError(editTierForm, 'discount_percent')}</div>
                            <div><label className="ta-field-label">Earn Multiplier</label><input className="ta-input" type="number" min="0.1" max="5" step="0.01" value={editTierForm.data.earn_multiplier} onChange={(e) => editTierForm.setData('earn_multiplier', e.target.value)} required />{fieldError(editTierForm, 'earn_multiplier')}</div>
                            <div className="flex items-center"><label className="text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={editTierForm.data.is_active} onChange={(e) => editTierForm.setData('is_active', e.target.checked)} />Active</label></div>
                            <div className="flex gap-2"><button className="ta-btn-primary" disabled={editTierForm.processing || !canManage}>Save</button><button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setEditingTierId(null)}>Cancel</button></div>
                        </form>
                    </section>
                )}

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Create Membership Card Type</h3>
                    <form onSubmit={(e) => { e.preventDefault(); createCardTypeForm.post(route('loyalty.card-types.store'), { onSuccess: () => createCardTypeForm.reset('name', 'slug', 'direct_purchase_price', 'validity_days') }); }} className="grid gap-3 md:grid-cols-6">
                        <div><label className="ta-field-label">Name</label><input className="ta-input" value={createCardTypeForm.data.name} onChange={(e) => createCardTypeForm.setData('name', e.target.value)} required />{fieldError(createCardTypeForm, 'name')}</div>
                        <div><label className="ta-field-label">Slug</label><input className="ta-input" value={createCardTypeForm.data.slug} onChange={(e) => createCardTypeForm.setData('slug', e.target.value)} required />{fieldError(createCardTypeForm, 'slug')}</div>
                        <div><label className="ta-field-label">Kind</label><select className="ta-input" value={createCardTypeForm.data.kind} onChange={(e) => createCardTypeForm.setData('kind', e.target.value)}><option value="physical">Physical</option><option value="virtual">Virtual</option><option value="gift">Gift</option></select>{fieldError(createCardTypeForm, 'kind')}</div>
                        <div><label className="ta-field-label">Min Points</label><input className="ta-input" type="number" min="0" value={createCardTypeForm.data.min_points} onChange={(e) => createCardTypeForm.setData('min_points', e.target.value)} required />{fieldError(createCardTypeForm, 'min_points')}</div>
                        <div><label className="ta-field-label">Direct Price</label><input className="ta-input" type="number" min="0" step="0.01" value={createCardTypeForm.data.direct_purchase_price} onChange={(e) => createCardTypeForm.setData('direct_purchase_price', e.target.value)} />{fieldError(createCardTypeForm, 'direct_purchase_price')}</div>
                        <div><label className="ta-field-label">Validity Days</label><input className="ta-input" type="number" min="1" value={createCardTypeForm.data.validity_days} onChange={(e) => createCardTypeForm.setData('validity_days', e.target.value)} />{fieldError(createCardTypeForm, 'validity_days')}</div>
                        <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={createCardTypeForm.data.is_active} onChange={(e) => createCardTypeForm.setData('is_active', e.target.checked)} />Active</label>
                        <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={createCardTypeForm.data.is_transferable} onChange={(e) => createCardTypeForm.setData('is_transferable', e.target.checked)} />Transferable</label>
                        <button className="ta-btn-primary" disabled={createCardTypeForm.processing || !canManage}>Add Card Type</button>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Membership Card Types</h3></div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Name</th><th className="px-5 py-3">Kind</th><th className="px-5 py-3">Min Points</th><th className="px-5 py-3">Validity</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Actions</th></tr></thead>
                            <tbody>{cardTypes.map((cardType) => <tr key={cardType.id} className="border-t border-slate-100"><td className="px-5 py-3 font-medium text-slate-700">{cardType.name}</td><td className="px-5 py-3 text-slate-600">{cardType.kind}</td><td className="px-5 py-3 text-slate-600">{cardType.min_points}</td><td className="px-5 py-3 text-slate-600">{cardType.validity_days ? `${cardType.validity_days} days` : 'No expiry'}</td><td className="px-5 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${cardType.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700'}`}>{cardType.is_active ? 'Active' : 'Inactive'}</span></td><td className="px-5 py-3"><button className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs text-indigo-700" onClick={() => startEditCardType(cardType)}>Edit</button></td></tr>)}</tbody>
                        </table>
                    </div>
                </section>

                {editingCardTypeId && (
                    <section className="ta-card p-5">
                        <h3 className="mb-4 text-sm font-semibold text-slate-700">Edit Card Type #{editingCardTypeId}</h3>
                        <form onSubmit={(e) => { e.preventDefault(); editCardTypeForm.put(route('loyalty.card-types.update', editingCardTypeId), { onSuccess: () => setEditingCardTypeId(null) }); }} className="grid gap-3 md:grid-cols-6">
                            <div><label className="ta-field-label">Name</label><input className="ta-input" value={editCardTypeForm.data.name} onChange={(e) => editCardTypeForm.setData('name', e.target.value)} required />{fieldError(editCardTypeForm, 'name')}</div>
                            <div><label className="ta-field-label">Slug</label><input className="ta-input" value={editCardTypeForm.data.slug} onChange={(e) => editCardTypeForm.setData('slug', e.target.value)} required />{fieldError(editCardTypeForm, 'slug')}</div>
                            <div><label className="ta-field-label">Kind</label><select className="ta-input" value={editCardTypeForm.data.kind} onChange={(e) => editCardTypeForm.setData('kind', e.target.value)}><option value="physical">Physical</option><option value="virtual">Virtual</option><option value="gift">Gift</option></select>{fieldError(editCardTypeForm, 'kind')}</div>
                            <div><label className="ta-field-label">Min Points</label><input className="ta-input" type="number" min="0" value={editCardTypeForm.data.min_points} onChange={(e) => editCardTypeForm.setData('min_points', e.target.value)} required />{fieldError(editCardTypeForm, 'min_points')}</div>
                            <div><label className="ta-field-label">Direct Price</label><input className="ta-input" type="number" min="0" step="0.01" value={editCardTypeForm.data.direct_purchase_price ?? ''} onChange={(e) => editCardTypeForm.setData('direct_purchase_price', e.target.value)} />{fieldError(editCardTypeForm, 'direct_purchase_price')}</div>
                            <div><label className="ta-field-label">Validity Days</label><input className="ta-input" type="number" min="1" value={editCardTypeForm.data.validity_days ?? ''} onChange={(e) => editCardTypeForm.setData('validity_days', e.target.value)} />{fieldError(editCardTypeForm, 'validity_days')}</div>
                            <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={editCardTypeForm.data.is_active} onChange={(e) => editCardTypeForm.setData('is_active', e.target.checked)} />Active</label>
                            <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={editCardTypeForm.data.is_transferable} onChange={(e) => editCardTypeForm.setData('is_transferable', e.target.checked)} />Transferable</label>
                            <div className="md:col-span-6 flex gap-2"><button className="ta-btn-primary" disabled={editCardTypeForm.processing || !canManage}>Save</button><button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setEditingCardTypeId(null)}>Cancel</button></div>
                        </form>
                    </section>
                )}

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Assign Membership Card</h3>
                    <form onSubmit={(e) => { e.preventDefault(); assignCardForm.post(route('loyalty.cards.assign'), { onSuccess: () => assignCardForm.reset('card_number', 'nfc_uid', 'notes') }); }} className="grid gap-3 md:grid-cols-6">
                        <div><label className="ta-field-label">Customer</label><select className="ta-input" value={assignCardForm.data.customer_id} onChange={(e) => assignCardForm.setData('customer_id', e.target.value)} required><option value="">Select customer</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name} ({customer.points} pts)</option>)}</select>{fieldError(assignCardForm, 'customer_id')}</div>
                        <div><label className="ta-field-label">Card Type</label><select className="ta-input" value={assignCardForm.data.membership_card_type_id} onChange={(e) => assignCardForm.setData('membership_card_type_id', e.target.value)} required><option value="">Select card type</option>{cardTypes.filter((type) => type.is_active).map((cardType) => <option key={cardType.id} value={cardType.id}>{cardType.name}</option>)}</select>{fieldError(assignCardForm, 'membership_card_type_id')}</div>
                        <div><label className="ta-field-label">Card Number</label><input className="ta-input" value={assignCardForm.data.card_number} onChange={(e) => assignCardForm.setData('card_number', e.target.value)} placeholder="Auto if blank" />{fieldError(assignCardForm, 'card_number')}</div>
                        <div><label className="ta-field-label">NFC UID</label><input className="ta-input" value={assignCardForm.data.nfc_uid} onChange={(e) => assignCardForm.setData('nfc_uid', e.target.value)} />{fieldError(assignCardForm, 'nfc_uid')}</div>
                        <div><label className="ta-field-label">Status</label><select className="ta-input" value={assignCardForm.data.status} onChange={(e) => assignCardForm.setData('status', e.target.value)}><option value="active">active</option><option value="pending">pending</option><option value="inactive">inactive</option><option value="expired">expired</option></select>{fieldError(assignCardForm, 'status')}</div>
                        <button className="ta-btn-primary" disabled={assignCardForm.processing || !canManage}>Assign Card</button>
                        <div className="md:col-span-6"><label className="ta-field-label">Notes</label><input className="ta-input" value={assignCardForm.data.notes} onChange={(e) => assignCardForm.setData('notes', e.target.value)} placeholder="Optional assignment notes" />{fieldError(assignCardForm, 'notes')}</div>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Customer Membership Snapshot</h3></div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Points</th><th className="px-5 py-3">Tier</th><th className="px-5 py-3">Current Card</th><th className="px-5 py-3">Eligible Card</th><th className="px-5 py-3">Expiry</th></tr></thead>
                            <tbody>{customers.map((customer) => <tr key={customer.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-700">{customer.name}<div className="text-xs text-slate-500">{customer.phone}</div></td><td className="px-5 py-3 text-slate-600">{customer.points}</td><td className="px-5 py-3 text-slate-600">{customer.tier || 'None'}</td><td className="px-5 py-3 text-slate-600">{customer.current_card ? `${customer.current_card} (${customer.current_card_status || 'n/a'})` : 'None'}</td><td className="px-5 py-3 text-slate-600">{customer.eligible_card || 'None'}</td><td className="px-5 py-3 text-slate-600">{customer.card_expires_at ? new Date(customer.card_expires_at).toLocaleDateString() : 'No expiry'}</td></tr>)}</tbody>
                        </table>
                    </div>
                </section>

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Create Service Package</h3>
                    <form onSubmit={(e) => { e.preventDefault(); packageForm.post(route('loyalty.packages.store'), { onSuccess: () => packageForm.reset('name', 'description', 'usage_limit', 'initial_value', 'validity_days') }); }} className="grid gap-3 md:grid-cols-6">
                        <div><label className="ta-field-label">Name</label><input className="ta-input" value={packageForm.data.name} onChange={(e) => packageForm.setData('name', e.target.value)} required />{fieldError(packageForm, 'name')}</div>
                        <div><label className="ta-field-label">Description</label><input className="ta-input" value={packageForm.data.description} onChange={(e) => packageForm.setData('description', e.target.value)} />{fieldError(packageForm, 'description')}</div>
                        <div><label className="ta-field-label">Usage Limit</label><input className="ta-input" type="number" min="1" value={packageForm.data.usage_limit} onChange={(e) => packageForm.setData('usage_limit', e.target.value)} />{fieldError(packageForm, 'usage_limit')}</div>
                        <div><label className="ta-field-label">Initial Value</label><input className="ta-input" type="number" min="0" step="0.01" value={packageForm.data.initial_value} onChange={(e) => packageForm.setData('initial_value', e.target.value)} />{fieldError(packageForm, 'initial_value')}</div>
                        <div><label className="ta-field-label">Validity Days</label><input className="ta-input" type="number" min="1" value={packageForm.data.validity_days} onChange={(e) => packageForm.setData('validity_days', e.target.value)} />{fieldError(packageForm, 'validity_days')}</div>
                        <button className="ta-btn-primary" disabled={packageForm.processing || !canManage}>Create Package</button>
                    </form>
                </section>

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Assign Package</h3>
                    <form onSubmit={(e) => { e.preventDefault(); assignPackageForm.post(route('loyalty.packages.assign'), { onSuccess: () => assignPackageForm.reset('notes') }); }} className="grid gap-3 md:grid-cols-4">
                        <div><label className="ta-field-label">Customer</label><select className="ta-input" value={assignPackageForm.data.customer_id} onChange={(e) => assignPackageForm.setData('customer_id', e.target.value)} required><option value="">Select customer</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}</select>{fieldError(assignPackageForm, 'customer_id')}</div>
                        <div><label className="ta-field-label">Package</label><select className="ta-input" value={assignPackageForm.data.service_package_id} onChange={(e) => assignPackageForm.setData('service_package_id', e.target.value)} required><option value="">Select package</option>{packages.filter((pkg) => pkg.is_active).map((pkg) => <option key={pkg.id} value={pkg.id}>{pkg.name}</option>)}</select>{fieldError(assignPackageForm, 'service_package_id')}</div>
                        <div><label className="ta-field-label">Notes</label><input className="ta-input" value={assignPackageForm.data.notes} onChange={(e) => assignPackageForm.setData('notes', e.target.value)} />{fieldError(assignPackageForm, 'notes')}</div>
                        <button className="ta-btn-primary" disabled={assignPackageForm.processing || !canManage}>Assign Package</button>
                    </form>
                </section>

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Consume Package Balance</h3>
                    <form onSubmit={(e) => { e.preventDefault(); consumePackageForm.post(route('loyalty.packages.consume', consumePackageForm.data.customer_package_id), { onSuccess: () => consumePackageForm.reset('sessions_used', 'value_used', 'notes') }); }} className="grid gap-3 md:grid-cols-5">
                        <div><label className="ta-field-label">Customer Package</label><select className="ta-input" value={consumePackageForm.data.customer_package_id} onChange={(e) => consumePackageForm.setData('customer_package_id', e.target.value)} required><option value="">Select active package</option>{customerPackages.filter((pkg) => pkg.status === 'active').map((pkg) => <option key={pkg.id} value={pkg.id}>{pkg.customer_name} - {pkg.package_name}</option>)}</select>{fieldError(consumePackageForm, 'customer_package_id')}</div>
                        <div><label className="ta-field-label">Sessions Used</label><input className="ta-input" type="number" min="0" value={consumePackageForm.data.sessions_used} onChange={(e) => consumePackageForm.setData('sessions_used', e.target.value)} />{fieldError(consumePackageForm, 'sessions_used')}</div>
                        <div><label className="ta-field-label">Value Used</label><input className="ta-input" type="number" min="0" step="0.01" value={consumePackageForm.data.value_used} onChange={(e) => consumePackageForm.setData('value_used', e.target.value)} />{fieldError(consumePackageForm, 'value_used')}</div>
                        <div><label className="ta-field-label">Notes</label><input className="ta-input" value={consumePackageForm.data.notes} onChange={(e) => consumePackageForm.setData('notes', e.target.value)} />{fieldError(consumePackageForm, 'notes')}</div>
                        <button className="ta-btn-primary" disabled={consumePackageForm.processing || !canManage || !consumePackageForm.data.customer_package_id}>Consume Package</button>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Customer Packages</h3></div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Package</th><th className="px-5 py-3">Sessions</th><th className="px-5 py-3">Value</th><th className="px-5 py-3">Status</th></tr></thead>
                            <tbody>{customerPackages.map((pkg) => <tr key={pkg.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-700">{pkg.customer_name}</td><td className="px-5 py-3 text-slate-600">{pkg.package_name}</td><td className="px-5 py-3 text-slate-600">{pkg.remaining_sessions ?? 'n/a'}</td><td className="px-5 py-3 text-slate-600">{pkg.remaining_value ?? 'n/a'}</td><td className="px-5 py-3 text-slate-600">{pkg.status}</td></tr>)}</tbody>
                        </table>
                    </div>
                </section>

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Issue Gift Card</h3>
                    <form onSubmit={(e) => { e.preventDefault(); giftCardForm.post(route('loyalty.gift-cards.store'), { onSuccess: () => giftCardForm.reset('assigned_customer_id', 'initial_value', 'notes') }); }} className="grid gap-3 md:grid-cols-4">
                        <div><label className="ta-field-label">Customer</label><select className="ta-input" value={giftCardForm.data.assigned_customer_id} onChange={(e) => giftCardForm.setData('assigned_customer_id', e.target.value)}><option value="">Unassigned</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}</select>{fieldError(giftCardForm, 'assigned_customer_id')}</div>
                        <div><label className="ta-field-label">Initial Value</label><input className="ta-input" type="number" min="0.01" step="0.01" value={giftCardForm.data.initial_value} onChange={(e) => giftCardForm.setData('initial_value', e.target.value)} required />{fieldError(giftCardForm, 'initial_value')}</div>
                        <div><label className="ta-field-label">Notes</label><input className="ta-input" value={giftCardForm.data.notes} onChange={(e) => giftCardForm.setData('notes', e.target.value)} />{fieldError(giftCardForm, 'notes')}</div>
                        <button className="ta-btn-primary" disabled={giftCardForm.processing || !canManage}>Issue Gift Card</button>
                    </form>
                </section>

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Consume Gift Card</h3>
                    <form onSubmit={(e) => { e.preventDefault(); consumeGiftCardForm.post(route('loyalty.gift-cards.consume', consumeGiftCardForm.data.gift_card_id), { onSuccess: () => consumeGiftCardForm.reset('amount', 'reason', 'notes') }); }} className="grid gap-3 md:grid-cols-5">
                        <div><label className="ta-field-label">Gift Card</label><select className="ta-input" value={consumeGiftCardForm.data.gift_card_id} onChange={(e) => consumeGiftCardForm.setData('gift_card_id', e.target.value)} required><option value="">Select gift card</option>{giftCards.filter((card) => card.status === 'active').map((card) => <option key={card.id} value={card.id}>{card.code} ({card.remaining_value})</option>)}</select>{fieldError(consumeGiftCardForm, 'gift_card_id')}</div>
                        <div><label className="ta-field-label">Amount</label><input className="ta-input" type="number" min="0.01" step="0.01" value={consumeGiftCardForm.data.amount} onChange={(e) => consumeGiftCardForm.setData('amount', e.target.value)} required />{fieldError(consumeGiftCardForm, 'amount')}</div>
                        <div><label className="ta-field-label">Reason</label><input className="ta-input" value={consumeGiftCardForm.data.reason} onChange={(e) => consumeGiftCardForm.setData('reason', e.target.value)} required />{fieldError(consumeGiftCardForm, 'reason')}</div>
                        <div><label className="ta-field-label">Notes</label><input className="ta-input" value={consumeGiftCardForm.data.notes} onChange={(e) => consumeGiftCardForm.setData('notes', e.target.value)} />{fieldError(consumeGiftCardForm, 'notes')}</div>
                        <button className="ta-btn-primary" disabled={consumeGiftCardForm.processing || !canManage || !consumeGiftCardForm.data.gift_card_id}>Consume Gift Card</button>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Gift Cards</h3></div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Code</th><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Initial</th><th className="px-5 py-3">Remaining</th><th className="px-5 py-3">Status</th></tr></thead>
                            <tbody>{giftCards.map((card) => <tr key={card.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-700">{card.code}</td><td className="px-5 py-3 text-slate-600">{card.customer_name || 'Unassigned'}</td><td className="px-5 py-3 text-slate-600">{card.initial_value}</td><td className="px-5 py-3 text-slate-600">{card.remaining_value}</td><td className="px-5 py-3 text-slate-600">{card.status}</td></tr>)}</tbody>
                        </table>
                    </div>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Gift Card Transactions</h3></div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Date</th><th className="px-5 py-3">Gift Card</th><th className="px-5 py-3">Amount</th><th className="px-5 py-3">Balance</th><th className="px-5 py-3">Reason</th></tr></thead>
                            <tbody>{recentGiftTransactions.map((row) => <tr key={row.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-600">{new Date(row.created_at).toLocaleString()}</td><td className="px-5 py-3 text-slate-700">{row.gift_code}</td><td className="px-5 py-3 text-red-600">{row.amount_change}</td><td className="px-5 py-3 text-slate-700">{row.balance_after}</td><td className="px-5 py-3 text-slate-600">{row.reason}</td></tr>)}</tbody>
                        </table>
                    </div>
                </section>

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Reward Catalog</h3>
                    <form onSubmit={(e) => { e.preventDefault(); rewardForm.post(route('loyalty.rewards.store'), { onSuccess: () => rewardForm.reset('name', 'description') }); }} className="grid gap-3 md:grid-cols-5">
                        <div><label className="ta-field-label">Reward Name</label><input className="ta-input" placeholder="Reward name" value={rewardForm.data.name} onChange={(e) => rewardForm.setData('name', e.target.value)} required />{fieldError(rewardForm, 'name')}</div>
                        <div><label className="ta-field-label">Description</label><input className="ta-input" placeholder="Description" value={rewardForm.data.description} onChange={(e) => rewardForm.setData('description', e.target.value)} />{fieldError(rewardForm, 'description')}</div>
                        <div><label className="ta-field-label">Points Cost</label><input className="ta-input" type="number" min="1" placeholder="Points cost" value={rewardForm.data.points_cost} onChange={(e) => rewardForm.setData('points_cost', e.target.value)} required />{fieldError(rewardForm, 'points_cost')}</div>
                        <div><label className="ta-field-label">Stock</label><input className="ta-input" type="number" min="0" placeholder="Stock (optional)" value={rewardForm.data.stock_quantity ?? ''} onChange={(e) => rewardForm.setData('stock_quantity', e.target.value === '' ? null : e.target.value)} />{fieldError(rewardForm, 'stock_quantity')}</div>
                        <button className="ta-btn-primary" disabled={rewardForm.processing || !canManage}>Add Reward</button>
                    </form>

                    <div className="mt-4 overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-2">Reward</th><th className="px-4 py-2">Points</th><th className="px-4 py-2">Stock</th><th className="px-4 py-2">Status</th><th className="px-4 py-2">Actions</th></tr></thead>
                            <tbody>{rewards.map((reward) => <tr key={reward.id} className="border-t border-slate-100"><td className="px-4 py-2 text-slate-700">{reward.name}</td><td className="px-4 py-2 text-slate-600">{reward.points_cost}</td><td className="px-4 py-2 text-slate-600">{reward.stock_quantity ?? 'Unlimited'}</td><td className="px-4 py-2"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${reward.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700'}`}>{reward.is_active ? 'Active' : 'Inactive'}</span></td><td className="px-4 py-2"><button className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs text-indigo-700" onClick={() => startEditReward(reward)}>Edit</button></td></tr>)}</tbody>
                        </table>
                    </div>
                </section>

                {editingRewardId && (
                    <section className="ta-card p-5">
                        <h3 className="mb-4 text-sm font-semibold text-slate-700">Edit Reward #{editingRewardId}</h3>
                        <form onSubmit={(e) => { e.preventDefault(); editRewardForm.put(route('loyalty.rewards.update', editingRewardId), { onSuccess: () => setEditingRewardId(null) }); }} className="grid gap-3 md:grid-cols-5">
                            <div><label className="ta-field-label">Name</label><input className="ta-input" value={editRewardForm.data.name} onChange={(e) => editRewardForm.setData('name', e.target.value)} required />{fieldError(editRewardForm, 'name')}</div>
                            <div><label className="ta-field-label">Description</label><input className="ta-input" value={editRewardForm.data.description} onChange={(e) => editRewardForm.setData('description', e.target.value)} />{fieldError(editRewardForm, 'description')}</div>
                            <div><label className="ta-field-label">Points Cost</label><input className="ta-input" type="number" min="1" value={editRewardForm.data.points_cost} onChange={(e) => editRewardForm.setData('points_cost', e.target.value)} required />{fieldError(editRewardForm, 'points_cost')}</div>
                            <div><label className="ta-field-label">Stock Quantity</label><input className="ta-input" type="number" min="0" value={editRewardForm.data.stock_quantity ?? ''} onChange={(e) => editRewardForm.setData('stock_quantity', e.target.value === '' ? null : e.target.value)} />{fieldError(editRewardForm, 'stock_quantity')}</div>
                            <div className="flex items-center"><label className="text-sm text-slate-600"><input type="checkbox" checked={editRewardForm.data.is_active} onChange={(e) => editRewardForm.setData('is_active', e.target.checked)} className="mr-2" />Active</label></div>
                            <div className="md:col-span-5 flex gap-2"><button className="ta-btn-primary" disabled={editRewardForm.processing || !canManage}>Save</button><button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setEditingRewardId(null)}>Cancel</button></div>
                        </form>
                    </section>
                )}

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Redeem Reward</h3>
                    <form onSubmit={(e) => { e.preventDefault(); redeemForm.post(route('loyalty.redeem'), { onSuccess: () => redeemForm.reset('quantity') }); }} className="grid gap-3 md:grid-cols-4">
                        <div><label className="ta-field-label">Customer</label><select className="ta-input" value={redeemForm.data.customer_id} onChange={(e) => redeemForm.setData('customer_id', e.target.value)} required><option value="">Select customer</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name} ({customer.points} pts)</option>)}</select>{fieldError(redeemForm, 'customer_id')}</div>
                        <div><label className="ta-field-label">Loyalty Reward</label><select className="ta-input" value={redeemForm.data.loyalty_reward_id} onChange={(e) => redeemForm.setData('loyalty_reward_id', e.target.value)} required><option value="">Select reward</option>{rewards.filter((r) => r.is_active).map((reward) => <option key={reward.id} value={reward.id}>{reward.name} ({reward.points_cost} pts)</option>)}</select>{fieldError(redeemForm, 'loyalty_reward_id')}</div>
                        <div><label className="ta-field-label">Quantity</label><input className="ta-input" type="number" min="1" value={redeemForm.data.quantity} onChange={(e) => redeemForm.setData('quantity', e.target.value)} required />{fieldError(redeemForm, 'quantity')}</div>
                        <button className="ta-btn-primary" disabled={redeemForm.processing || !canManage}>Redeem</button>
                    </form>
                </section>

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Award Configured Bonus</h3>
                    <form onSubmit={(e) => { e.preventDefault(); bonusForm.post(route('loyalty.bonus.award')); }} className="grid gap-3 md:grid-cols-3">
                        <div><label className="ta-field-label">Customer</label><select className="ta-input" value={bonusForm.data.customer_id} onChange={(e) => bonusForm.setData('customer_id', e.target.value)} required><option value="">Select customer</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name} ({customer.points} pts)</option>)}</select>{fieldError(bonusForm, 'customer_id')}</div>
                        <div><label className="ta-field-label">Bonus Type</label><select className="ta-input" value={bonusForm.data.bonus_type} onChange={(e) => bonusForm.setData('bonus_type', e.target.value)}><option value="referral">Referral</option><option value="review">Review</option><option value="birthday">Birthday</option></select>{fieldError(bonusForm, 'bonus_type')}</div>
                        <button className="ta-btn-primary" disabled={bonusForm.processing || !canManage}>Award Bonus</button>
                    </form>
                </section>

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Add / Deduct Points</h3>
                    <form onSubmit={(e) => { e.preventDefault(); pointsForm.post(route('loyalty.ledger.store'), { onSuccess: () => pointsForm.reset('points_change', 'reason', 'reference', 'notes') }); }} className="grid gap-3 md:grid-cols-5">
                        <div><label className="ta-field-label">Customer</label><select className="ta-input" value={pointsForm.data.customer_id} onChange={(e) => pointsForm.setData('customer_id', e.target.value)} required><option value="">Select customer</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name} ({customer.points} pts)</option>)}</select>{fieldError(pointsForm, 'customer_id')}</div>
                        <div><label className="ta-field-label">Points</label><input className="ta-input" type="number" placeholder="Points (+/-)" value={pointsForm.data.points_change} onChange={(e) => pointsForm.setData('points_change', e.target.value)} required />{fieldError(pointsForm, 'points_change')}</div>
                        <div><label className="ta-field-label">Reason</label><input className="ta-input" placeholder="Reason" value={pointsForm.data.reason} onChange={(e) => pointsForm.setData('reason', e.target.value)} required />{fieldError(pointsForm, 'reason')}</div>
                        <div><label className="ta-field-label">Reference</label><input className="ta-input" placeholder="Reference" value={pointsForm.data.reference} onChange={(e) => pointsForm.setData('reference', e.target.value)} />{fieldError(pointsForm, 'reference')}</div>
                        <button className="ta-btn-primary" disabled={pointsForm.processing || !canManage}>Apply</button>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Recent Redemptions</h3></div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Date</th><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Reward</th><th className="px-5 py-3">Points</th><th className="px-5 py-3">By</th></tr></thead>
                            <tbody>{recentRedemptions.map((row) => <tr key={row.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-600">{new Date(row.created_at).toLocaleString()}</td><td className="px-5 py-3 text-slate-700">{row.customer_name}</td><td className="px-5 py-3 text-slate-600">{row.reward_name}</td><td className="px-5 py-3 font-semibold text-red-600">-{row.points_spent}</td><td className="px-5 py-3 text-slate-600">{row.redeemed_by || '-'}</td></tr>)}</tbody>
                        </table>
                    </div>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Recent Loyalty Ledger</h3></div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Date</th><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Change</th><th className="px-5 py-3">Balance</th><th className="px-5 py-3">Reason</th><th className="px-5 py-3">By</th></tr></thead>
                            <tbody>{recentLedgers.map((row) => <tr key={row.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-600">{new Date(row.created_at).toLocaleString()}</td><td className="px-5 py-3 text-slate-700">{row.customer_name}</td><td className={`px-5 py-3 font-semibold ${row.points_change >= 0 ? 'text-emerald-600' : 'text-red-600'}`}>{row.points_change >= 0 ? `+${row.points_change}` : row.points_change}</td><td className="px-5 py-3 text-slate-700">{row.balance_after}</td><td className="px-5 py-3 text-slate-600">{row.reason}</td><td className="px-5 py-3 text-slate-600">{row.created_by || '-'}</td></tr>)}</tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}









