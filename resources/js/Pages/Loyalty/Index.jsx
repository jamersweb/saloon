import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { LOYALTY_SECTIONS } from './loyaltySections';
import ProgramSection from './sections/ProgramSection';
import MembershipCardsSection from './sections/MembershipCardsSection';
import PackagesSection from './sections/PackagesSection';
import GiftCardsSection from './sections/GiftCardsSection';
import RewardsSection from './sections/RewardsSection';
import PointsSection from './sections/PointsSection';

const fieldError = (form, field) => (form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null);

export default function LoyaltyIndex({
    section,
    tiers,
    cardTypes,
    packages,
    customers,
    membershipCards,
    nfcLookupResult,
    giftNfcLookupResult,
    customerPackages,
    giftCards,
    recentGiftTransactions,
    recentLedgers,
    rewards,
    recentRedemptions,
    appointmentsForRedeem,
    salonServices,
    settings,
}) {
    const { flash, auth } = usePage().props;
    const canManage = Boolean(auth?.permissions?.can_manage_loyalty);
    const [editingTierId, setEditingTierId] = useState(null);
    const [editingCardTypeId, setEditingCardTypeId] = useState(null);
    const [editingRewardId, setEditingRewardId] = useState(null);
    const [nfcBridgeStatus, setNfcBridgeStatus] = useState('');
    const [nfcBridgeLoadingTarget, setNfcBridgeLoadingTarget] = useState(null);
    const [nfcBridgeOnline, setNfcBridgeOnline] = useState(null);
    const [nfcBridgeChecking, setNfcBridgeChecking] = useState(false);
    const validSectionIds = LOYALTY_SECTIONS.map((entry) => entry.id);
    const activeSection = validSectionIds.includes(section) ? section : 'program';

    const createTierForm = useForm({ name: '', min_points: 0, discount_percent: 0, earn_multiplier: 1, is_active: true });
    const editTierForm = useForm({ name: '', min_points: 0, discount_percent: 0, earn_multiplier: 1, is_active: true });
    const createCardTypeForm = useForm({ name: '', slug: '', kind: 'physical', min_points: 0, direct_purchase_price: '', validity_days: '', is_active: true, is_transferable: false });
    const editCardTypeForm = useForm({ name: '', slug: '', kind: 'physical', min_points: 0, direct_purchase_price: '', validity_days: '', is_active: true, is_transferable: false });
    const assignCardForm = useForm({ customer_id: '', membership_card_type_id: '', card_number: '', nfc_uid: '', status: 'active', notes: '' });
    const issueInventoryForm = useForm({ membership_card_type_id: '', card_number: '', nfc_uid: '', status: 'pending', notes: '' });
    const linkInventoryForm = useForm({ customer_id: '', customer_membership_card_id: '', status: 'active', notes: '' });
    const nfcLookupForm = useForm({ nfc_uid: '' });
    const nfcBindForm = useForm({ customer_membership_card_id: '', nfc_uid: '', replace_existing: false });
    const packageForm = useForm({ name: '', description: '', usage_limit: '', initial_value: '', validity_days: '', is_active: true });
    const assignPackageForm = useForm({ customer_id: '', service_package_id: '', notes: '' });
    const consumePackageForm = useForm({ customer_package_id: '', sessions_used: 0, value_used: 0, notes: '' });
    const giftCardForm = useForm({ assigned_customer_id: '', initial_value: '', nfc_uid: '', notes: '' });
    const assignGiftCardForm = useForm({ gift_card_id: '', assigned_customer_id: '' });
    const giftNfcLookupForm = useForm({ gift_nfc_uid: '' });
    const giftNfcBindForm = useForm({ gift_card_id: '', nfc_uid: '', replace_existing: false });
    const consumeGiftCardForm = useForm({ gift_card_id: '', appointment_id: '', amount: '', reason: '', notes: '' });
    const pointsForm = useForm({ customer_id: '', points_change: '', reason: '', reference: '', notes: '' });
    const rewardForm = useForm({
        name: '',
        description: '',
        points_cost: 50,
        stock_quantity: '',
        max_units_per_redemption: '',
        max_redemptions_per_calendar_month: '',
        min_days_between_redemptions: '',
        requires_appointment_id: false,
        salon_service_ids: [],
        is_active: true,
    });
    const editRewardForm = useForm({
        name: '',
        description: '',
        points_cost: 50,
        stock_quantity: '',
        max_units_per_redemption: '',
        max_redemptions_per_calendar_month: '',
        min_days_between_redemptions: '',
        requires_appointment_id: false,
        salon_service_ids: [],
        is_active: true,
    });
    const redeemForm = useForm({ customer_id: '', loyalty_reward_id: '', appointment_id: '', quantity: 1 });
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
            max_units_per_redemption: reward.max_units_per_redemption ?? '',
            max_redemptions_per_calendar_month: reward.max_redemptions_per_calendar_month ?? '',
            min_days_between_redemptions: reward.min_days_between_redemptions ?? '',
            requires_appointment_id: Boolean(reward.requires_appointment_id),
            salon_service_ids: (reward.allowed_salon_services || []).map((s) => s.id),
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

    const setNfcUidForTarget = (target, uid) => {
        if (target === 'assign') {
            assignCardForm.setData('nfc_uid', uid);
            return;
        }
        if (target === 'issue_inventory') {
            issueInventoryForm.setData('nfc_uid', uid);
            return;
        }
        if (target === 'lookup') {
            nfcLookupForm.setData('nfc_uid', uid);
            return;
        }
        if (target === 'bind') {
            nfcBindForm.setData('nfc_uid', uid);
            return;
        }
        if (target === 'gift_issue') {
            giftCardForm.setData('nfc_uid', uid);
            return;
        }
        if (target === 'gift_lookup') {
            giftNfcLookupForm.setData('gift_nfc_uid', uid);
            return;
        }
        if (target === 'gift_bind') {
            giftNfcBindForm.setData('nfc_uid', uid);
        }
    };

    const readUidFromBridge = async (target) => {
        setNfcBridgeLoadingTarget(target);
        setNfcBridgeStatus('Reading NFC UID from local bridge...');
        try {
            const response = await fetch('http://127.0.0.1:35791/uid?consume=1');
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || !payload?.uid) {
                throw new Error(payload?.error || 'No UID available. Tap the card and try again.');
            }
            const uid = String(payload.uid).trim().toUpperCase();
            setNfcUidForTarget(target, uid);
            setNfcBridgeStatus(`Captured UID ${uid}${payload?.reader ? ` (${payload.reader})` : ''}.`);
        } catch (error) {
            const message = String(error?.message || '');
            if (message.toLowerCase().includes('failed to fetch')) {
                setNfcBridgeStatus('NFC bridge is not reachable. Start local bridge on http://127.0.0.1:35791.');
            } else {
                setNfcBridgeStatus(message || 'Unable to read UID from NFC bridge.');
            }
        } finally {
            setNfcBridgeLoadingTarget(null);
        }
    };

    const checkNfcBridge = async () => {
        setNfcBridgeChecking(true);
        setNfcBridgeStatus('Checking NFC bridge connection...');
        try {
            await fetch('http://127.0.0.1:35791/uid?consume=0');
            setNfcBridgeOnline(true);
            setNfcBridgeStatus('NFC bridge connected. You can scan a card now.');
        } catch (error) {
            setNfcBridgeOnline(false);
            setNfcBridgeStatus('NFC bridge is offline. Start local bridge on http://127.0.0.1:35791.');
        } finally {
            setNfcBridgeChecking(false);
        }
    };

    const importCsv = (entity, file, resetInput) => {
        if (!file) return;
        router.post(route('data-transfer.import', { entity }), { csv_file: file }, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                if (typeof resetInput === 'function') resetInput();
            },
        });
    };

    const exportCsv = (entity) => {
        window.location.href = route('data-transfer.export', { entity });
    };

    useEffect(() => {
        if (activeSection === 'membership-cards' || activeSection === 'gift-cards') {
            checkNfcBridge();
        } else {
            setNfcBridgeStatus('');
        }
    }, [activeSection]);

    const sectionLabel = LOYALTY_SECTIONS.find((s) => s.id === activeSection)?.label ?? 'Program & tiers';
    const showNfcControls = activeSection === 'membership-cards' || activeSection === 'gift-cards';

    return (
        <AuthenticatedLayout
            header={`Loyalty - ${sectionLabel}`}
            headerActions={
                showNfcControls ? (
                    <button
                        type="button"
                        onClick={checkNfcBridge}
                        disabled={nfcBridgeChecking}
                        className="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {nfcBridgeChecking ? 'Connecting...' : 'Connect NFC'}
                    </button>
                ) : null
            }
        >
            <Head title={`Loyalty - ${sectionLabel}`} />

            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}
                {showNfcControls && nfcBridgeOnline === false && (
                    <div className="ta-card border-amber-200 bg-amber-50 p-3 text-sm text-amber-700">
                        NFC bridge is offline. Keep the bridge running on the same device where the card reader is connected.
                    </div>
                )}
                {showNfcControls && nfcBridgeStatus && (
                    <div className="ta-card border-sky-200 bg-sky-50 p-3 text-sm text-sky-700">{nfcBridgeStatus}</div>
                )}

                {activeSection === 'program' && (
                    <ProgramSection
                        fieldError={fieldError}
                        canManage={canManage}
                        settingsForm={settingsForm}
                        createTierForm={createTierForm}
                        tiers={tiers}
                        editingTierId={editingTierId}
                        startEditTier={startEditTier}
                        editTierForm={editTierForm}
                        setEditingTierId={setEditingTierId}
                    />
                )}

                {activeSection === 'membership-cards' && (
                    <MembershipCardsSection
                        fieldError={fieldError}
                        canManage={canManage}
                        cardTypes={cardTypes}
                        customers={customers}
                        membershipCards={membershipCards}
                        nfcLookupResult={nfcLookupResult}
                        nfcBridgeLoadingTarget={nfcBridgeLoadingTarget}
                        createCardTypeForm={createCardTypeForm}
                        editingCardTypeId={editingCardTypeId}
                        startEditCardType={startEditCardType}
                        editCardTypeForm={editCardTypeForm}
                        setEditingCardTypeId={setEditingCardTypeId}
                        issueInventoryForm={issueInventoryForm}
                        linkInventoryForm={linkInventoryForm}
                        assignCardForm={assignCardForm}
                        nfcLookupForm={nfcLookupForm}
                        nfcBindForm={nfcBindForm}
                        recentLedgers={recentLedgers}
                        recentRedemptions={recentRedemptions}
                        appointmentsForRedeem={appointmentsForRedeem}
                        readUidFromBridge={readUidFromBridge}
                        importCsv={importCsv}
                        exportCsv={exportCsv}
                    />
                )}

                {activeSection === 'packages' && (
                    <PackagesSection
                        fieldError={fieldError}
                        canManage={canManage}
                        packageForm={packageForm}
                        assignPackageForm={assignPackageForm}
                        consumePackageForm={consumePackageForm}
                        customers={customers}
                        packages={packages}
                        customerPackages={customerPackages}
                    />
                )}

                {activeSection === 'gift-cards' && (
                    <GiftCardsSection
                        fieldError={fieldError}
                        canManage={canManage}
                        giftCardForm={giftCardForm}
                        assignGiftCardForm={assignGiftCardForm}
                        consumeGiftCardForm={consumeGiftCardForm}
                        giftNfcLookupForm={giftNfcLookupForm}
                        giftNfcLookupResult={giftNfcLookupResult}
                        giftNfcBindForm={giftNfcBindForm}
                        customers={customers}
                        giftCards={giftCards}
                        recentGiftTransactions={recentGiftTransactions}
                        appointmentsForRedeem={appointmentsForRedeem}
                        nfcBridgeLoadingTarget={nfcBridgeLoadingTarget}
                        readUidFromBridge={readUidFromBridge}
                        importCsv={importCsv}
                        exportCsv={exportCsv}
                    />
                )}

                {activeSection === 'rewards' && (
                    <RewardsSection
                        fieldError={fieldError}
                        canManage={canManage}
                        rewardForm={rewardForm}
                        editRewardForm={editRewardForm}
                        editingRewardId={editingRewardId}
                        setEditingRewardId={setEditingRewardId}
                        startEditReward={startEditReward}
                        redeemForm={redeemForm}
                        customers={customers}
                        rewards={rewards}
                        recentRedemptions={recentRedemptions}
                        salonServices={salonServices}
                        appointmentsForRedeem={appointmentsForRedeem}
                    />
                )}

                {activeSection === 'points' && (
                    <PointsSection
                        fieldError={fieldError}
                        canManage={canManage}
                        bonusForm={bonusForm}
                        pointsForm={pointsForm}
                        customers={customers}
                        recentLedgers={recentLedgers}
                    />
                )}
            </div>
        </AuthenticatedLayout>
    );
}
