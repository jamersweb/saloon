import Modal from '@/Components/Modal';
import { Transition } from '@headlessui/react';
import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';

export default function MembershipCardsSection({
    fieldError,
    canManage,
    cardTypes,
    customers,
    membershipCards,
    membershipRegistrations,
    currentUserName,
    openRegistrationByDefault = false,
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
    editingMembershipCardId,
    editMembershipCardForm,
    refillMembershipCardForm,
    setEditingMembershipCardId,
    startEditMembershipCard,
    memberRegistrationForm,
    nfcLookupForm,
    nfcBindForm,
    recentLedgers,
    recentRedemptions,
    appointmentsForRedeem,
    readUidFromBridge,
    importCsv,
    exportCsv,
}) {
    const ROWS_PER_PAGE = 10;
    const importFileRef = useRef(null);
    const [membershipCardTypeFilter, setMembershipCardTypeFilter] = useState('');
    const [selectedMembershipCardId, setSelectedMembershipCardId] = useState(null);
    const [showRegistrationModal, setShowRegistrationModal] = useState(false);
    const [registrationStep, setRegistrationStep] = useState(0);
    const [cardTypesPage, setCardTypesPage] = useState(1);
    const [nfcRegistryPage, setNfcRegistryPage] = useState(1);
    const [membershipCustomersPage, setMembershipCustomersPage] = useState(1);
    const [registrationPage, setRegistrationPage] = useState(1);
    const [registrySearch, setRegistrySearch] = useState('');
    const [registryStatusFilter, setRegistryStatusFilter] = useState('');
    const [registryTypeFilter, setRegistryTypeFilter] = useState('');

    const REGISTRATION_STEPS = [
        { id: 'customer', label: 'Customer' },
        { id: 'visit', label: 'Visit & Preferences' },
        { id: 'membership', label: 'Membership' },
        { id: 'consent', label: 'Consent & Review' },
    ];

    const pointsByCustomerId = useMemo(() => {
        const map = {};
        (customers || []).forEach((customer) => {
            map[String(customer.id)] = customer.points || 0;
        });
        return map;
    }, [customers]);

    const membershipCustomers = useMemo(() => {
        const rows = (membershipCards || []).filter((card) => card.customer_id != null);
        if (!membershipCardTypeFilter) {
            return rows;
        }
        return rows.filter((card) => String(card.membership_card_type_id) === String(membershipCardTypeFilter));
    }, [membershipCards, membershipCardTypeFilter]);

    const cardTypesTotalPages = Math.max(1, Math.ceil((cardTypes || []).length / ROWS_PER_PAGE));
    const filteredRegistryCards = useMemo(() => {
        const term = registrySearch.trim().toLowerCase();

        return (membershipCards || []).filter((card) => {
            if (registryStatusFilter && String(card.status) !== String(registryStatusFilter)) {
                return false;
            }
            if (registryTypeFilter && String(card.membership_card_type_id) !== String(registryTypeFilter)) {
                return false;
            }
            if (!term) {
                return true;
            }

            const haystack = [
                card.customer_name,
                card.customer_phone,
                card.customer_email,
                card.card_number,
                card.card_type_name,
                card.nfc_uid,
                card.status,
                card.notes,
                card.customer_id == null ? 'inventory unassigned' : '',
            ]
                .filter(Boolean)
                .join(' ')
                .toLowerCase();

            return haystack.includes(term);
        });
    }, [membershipCards, registrySearch, registryStatusFilter, registryTypeFilter]);

    const nfcRegistryTotalPages = Math.max(1, Math.ceil(filteredRegistryCards.length / ROWS_PER_PAGE));
    const membershipCustomersTotalPages = Math.max(1, Math.ceil(membershipCustomers.length / ROWS_PER_PAGE));
    const registrationTotalPages = Math.max(1, Math.ceil((membershipRegistrations || []).length / ROWS_PER_PAGE));

    const cardTypesPageRows = useMemo(
        () => (cardTypes || []).slice((cardTypesPage - 1) * ROWS_PER_PAGE, cardTypesPage * ROWS_PER_PAGE),
        [cardTypes, cardTypesPage],
    );
    const nfcRegistryPageRows = useMemo(
        () => filteredRegistryCards.slice((nfcRegistryPage - 1) * ROWS_PER_PAGE, nfcRegistryPage * ROWS_PER_PAGE),
        [filteredRegistryCards, nfcRegistryPage],
    );
    const membershipCustomersPageRows = useMemo(
        () => membershipCustomers.slice((membershipCustomersPage - 1) * ROWS_PER_PAGE, membershipCustomersPage * ROWS_PER_PAGE),
        [membershipCustomers, membershipCustomersPage],
    );
    const registrationPageRows = useMemo(
        () => (membershipRegistrations || []).slice((registrationPage - 1) * ROWS_PER_PAGE, registrationPage * ROWS_PER_PAGE),
        [membershipRegistrations, registrationPage],
    );

    useEffect(() => {
        setMembershipCustomersPage(1);
    }, [membershipCardTypeFilter]);

    useEffect(() => {
        if (cardTypesPage > cardTypesTotalPages) {
            setCardTypesPage(cardTypesTotalPages);
        }
    }, [cardTypesPage, cardTypesTotalPages]);

    useEffect(() => {
        if (nfcRegistryPage > nfcRegistryTotalPages) {
            setNfcRegistryPage(nfcRegistryTotalPages);
        }
    }, [nfcRegistryPage, nfcRegistryTotalPages]);

    useEffect(() => {
        setNfcRegistryPage(1);
    }, [registrySearch, registryStatusFilter, registryTypeFilter]);

    useEffect(() => {
        if (membershipCustomersPage > membershipCustomersTotalPages) {
            setMembershipCustomersPage(membershipCustomersTotalPages);
        }
    }, [membershipCustomersPage, membershipCustomersTotalPages]);

    useEffect(() => {
        if (registrationPage > registrationTotalPages) {
            setRegistrationPage(registrationTotalPages);
        }
    }, [registrationPage, registrationTotalPages]);

    useEffect(() => {
        if (openRegistrationByDefault) {
            openRegistrationModal();
        }
    }, [openRegistrationByDefault]);

    const selectedMembershipCard = useMemo(
        () => (membershipCards || []).find((card) => String(card.id) === String(selectedMembershipCardId)) || null,
        [membershipCards, selectedMembershipCardId],
    );

    const selectedCustomerId = selectedMembershipCard?.customer_id ? String(selectedMembershipCard.customer_id) : null;
    const selectedUsageHistory = useMemo(() => {
        if (!selectedCustomerId) return [];
        return (appointmentsForRedeem || [])
            .filter((appointment) => String(appointment.customer_id) === selectedCustomerId)
            .slice(0, 10);
    }, [appointmentsForRedeem, selectedCustomerId]);
    const selectedPointsHistory = useMemo(() => {
        if (!selectedCustomerId) return [];
        return (recentLedgers || [])
            .filter((entry) => String(entry.customer_id) === selectedCustomerId)
            .slice(0, 10);
    }, [recentLedgers, selectedCustomerId]);
    const selectedRewardsHistory = useMemo(() => {
        if (!selectedCustomerId) return [];
        return (recentRedemptions || [])
            .filter((entry) => String(entry.customer_id) === selectedCustomerId)
            .slice(0, 10);
    }, [recentRedemptions, selectedCustomerId]);

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

    const renderPager = (page, totalPages, setPage) => (
        <div className="flex items-center justify-between border-t border-slate-200 px-5 py-3 text-xs text-slate-600">
            <span>Page {page} of {totalPages}</span>
            <div className="flex gap-2">
                <button type="button" className="rounded-lg border border-slate-200 px-2 py-1 disabled:opacity-50" disabled={page <= 1} onClick={() => setPage((prev) => Math.max(1, prev - 1))}>Previous</button>
                <button type="button" className="rounded-lg border border-slate-200 px-2 py-1 disabled:opacity-50" disabled={page >= totalPages} onClick={() => setPage((prev) => Math.min(totalPages, prev + 1))}>Next</button>
            </div>
        </div>
    );

    const resetMemberRegistrationForm = () => {
        memberRegistrationForm.reset();
        memberRegistrationForm.setData({
            customer_id: '',
            registration_date: new Date().toISOString().slice(0, 10),
            staff_name: currentUserName || '',
            full_name: '',
            phone: '',
            email: '',
            nationality: '',
            date_of_birth: '',
            is_first_visit: true,
            preferred_language: 'English',
            preferred_language_other: '',
            heard_about_us: 'Instagram',
            heard_about_us_other: '',
            service_interests: [],
            service_interests_other: '',
            requires_home_service: false,
            home_service_location: '',
            preferred_visit_frequency: 'Monthly',
            spending_profile: 'AED 500 – 2,000',
            membership_card_type_id: '',
            card_number: '',
            nfc_uid: '',
            card_status: 'active',
            card_notes: '',
            consent_data_processing: false,
            consent_marketing: true,
            signature_name: '',
            signature_date: new Date().toISOString().slice(0, 10),
            notes: '',
        });
        memberRegistrationForm.clearErrors();
        setRegistrationStep(0);
    };

    const openRegistrationModal = () => {
        resetMemberRegistrationForm();
        setShowRegistrationModal(true);
    };

    const closeRegistrationModal = () => {
        if (memberRegistrationForm.processing) return;
        setShowRegistrationModal(false);
        setRegistrationStep(0);
    };

    const syncCustomerFromExisting = (customerId) => {
        const customer = (customers || []).find((entry) => String(entry.id) === String(customerId));
        if (!customer) {
            memberRegistrationForm.setData('customer_id', '');
            return;
        }

        memberRegistrationForm.setData({
            ...memberRegistrationForm.data,
            customer_id: String(customer.id),
            full_name: customer.name || memberRegistrationForm.data.full_name,
            phone: customer.phone || memberRegistrationForm.data.phone,
            email: customer.email || memberRegistrationForm.data.email,
            signature_name: memberRegistrationForm.data.signature_name || customer.name || '',
        });
    };

    const toggleServiceInterest = (value) => {
        const current = new Set(memberRegistrationForm.data.service_interests || []);
        if (current.has(value)) {
            current.delete(value);
        } else {
            current.add(value);
        }
        memberRegistrationForm.setData('service_interests', Array.from(current));
    };

    const validateRegistrationStep = () => {
        if (registrationStep === 0) {
            if (!memberRegistrationForm.data.full_name || !memberRegistrationForm.data.phone || !memberRegistrationForm.data.registration_date) {
                window.alert('Add registration date, full name, and phone before continuing.');
                return false;
            }
        }
        if (registrationStep === 1) {
            if ((memberRegistrationForm.data.service_interests || []).length === 0) {
                window.alert('Select at least one service interest.');
                return false;
            }
        }
        if (registrationStep === 2) {
            if (!memberRegistrationForm.data.membership_card_type_id) {
                window.alert('Select the membership card type before continuing.');
                return false;
            }
        }
        if (registrationStep === 3) {
            if (!memberRegistrationForm.data.consent_data_processing || !memberRegistrationForm.data.signature_name || !memberRegistrationForm.data.signature_date) {
                window.alert('Consent, signature name, and signature date are required.');
                return false;
            }
        }

        return true;
    };

    const handleRegistrationSubmit = (e) => {
        e.preventDefault();

        if (!validateRegistrationStep()) {
            return;
        }

        memberRegistrationForm.post(route('loyalty.cards.register-member'), {
            preserveScroll: true,
            onSuccess: () => {
                setShowRegistrationModal(false);
                resetMemberRegistrationForm();
            },
        });
    };

    const selectedRegistrationCardType = (cardTypes || []).find(
        (cardType) => String(cardType.id) === String(memberRegistrationForm.data.membership_card_type_id),
    );
    const editingMembershipCardType = (cardTypes || []).find(
        (cardType) => String(cardType.id) === String(editMembershipCardForm.data.membership_card_type_id),
    );
    const editingMembershipCardActsAsGift = Boolean(
        editingMembershipCardType
        && Number(editingMembershipCardType.direct_purchase_price || 0) > 0
        && (
            String(editingMembershipCardType.kind || '').toLowerCase() === 'gift'
            || String(editingMembershipCardType.name || '').toLowerCase().includes('gift')
            || String(editingMembershipCardType.slug || '').toLowerCase().includes('gift')
        )
    );

    return (
        <div className="space-y-6">
            <Modal show={showRegistrationModal} onClose={closeRegistrationModal} maxWidth="6xl">
                <form onSubmit={handleRegistrationSubmit} className="bg-slate-50 text-slate-800">
                    <div className="border-b border-slate-200 bg-white px-6 py-5">
                        <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <h2 className="text-2xl font-semibold text-slate-900">Add New Member</h2>
                                <p className="mt-1 text-sm text-slate-500">Digital membership registration, customer creation, and card assignment in one flow.</p>
                            </div>
                            <div className="grid gap-2 sm:grid-cols-4">
                                {REGISTRATION_STEPS.map((step, index) => (
                                    <button
                                        key={step.id}
                                        type="button"
                                        onClick={() => {
                                            if (index <= registrationStep || validateRegistrationStep()) {
                                                setRegistrationStep(index);
                                            }
                                        }}
                                        className={`rounded-2xl border px-3 py-2 text-left transition ${
                                            index === registrationStep
                                                ? 'border-indigo-300 bg-indigo-50 text-indigo-700'
                                                : index < registrationStep
                                                    ? 'border-emerald-300 bg-emerald-50 text-emerald-700'
                                                    : 'border-slate-200 bg-white text-slate-500'
                                        }`}
                                    >
                                        <div className="text-[11px] uppercase tracking-[0.3em] opacity-70">Step {index + 1}</div>
                                        <div className="mt-1 text-sm font-medium">{step.label}</div>
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>

                    <div className="grid min-h-[70vh] gap-0 lg:grid-cols-[1.65fr_0.75fr]">
                        <div className="overflow-y-auto p-6">
                            <Transition
                                key={registrationStep}
                                appear
                                show
                                enter="transform transition duration-300 ease-out"
                                enterFrom="translate-x-3 opacity-0"
                                enterTo="translate-x-0 opacity-100"
                                leave="transform transition duration-200 ease-in"
                                leaveFrom="translate-x-0 opacity-100"
                                leaveTo="-translate-x-2 opacity-0"
                            >
                                <div className="space-y-6">
                                    {registrationStep === 0 && (
                                        <div className="grid gap-5 md:grid-cols-2">
                                            <div className="ta-card md:col-span-2 p-5">
                                                <label className="ta-field-label">Existing Customer Optional</label>
                                                <select
                                                    className="ta-input"
                                                    value={memberRegistrationForm.data.customer_id}
                                                    onChange={(e) => syncCustomerFromExisting(e.target.value)}
                                                >
                                                    <option value="">Create new customer</option>
                                                    {customers.map((customer) => (
                                                        <option key={customer.id} value={customer.id}>{customer.name} {customer.phone ? `(${customer.phone})` : ''}</option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div>
                                                <label className="ta-field-label">Registration Date</label>
                                                <input className="ta-input" type="date" value={memberRegistrationForm.data.registration_date} onChange={(e) => memberRegistrationForm.setData('registration_date', e.target.value)} />
                                                {fieldError(memberRegistrationForm, 'registration_date')}
                                            </div>
                                            <div>
                                                <label className="ta-field-label">Staff Name</label>
                                                <input className="ta-input" value={memberRegistrationForm.data.staff_name} onChange={(e) => memberRegistrationForm.setData('staff_name', e.target.value)} />
                                                {fieldError(memberRegistrationForm, 'staff_name')}
                                            </div>
                                            <div>
                                                <label className="ta-field-label">Full Name</label>
                                                <input className="ta-input" value={memberRegistrationForm.data.full_name} onChange={(e) => memberRegistrationForm.setData('full_name', e.target.value)} />
                                                {fieldError(memberRegistrationForm, 'full_name')}
                                            </div>
                                            <div>
                                                <label className="ta-field-label">Phone Number</label>
                                                <input className="ta-input" value={memberRegistrationForm.data.phone} onChange={(e) => memberRegistrationForm.setData('phone', e.target.value)} />
                                                {fieldError(memberRegistrationForm, 'phone')}
                                            </div>
                                            <div>
                                                <label className="ta-field-label">Email Address</label>
                                                <input className="ta-input" type="email" value={memberRegistrationForm.data.email} onChange={(e) => memberRegistrationForm.setData('email', e.target.value)} />
                                                {fieldError(memberRegistrationForm, 'email')}
                                            </div>
                                            <div>
                                                <label className="ta-field-label">Nationality</label>
                                                <input className="ta-input" value={memberRegistrationForm.data.nationality} onChange={(e) => memberRegistrationForm.setData('nationality', e.target.value)} />
                                            </div>
                                            <div>
                                                <label className="ta-field-label">Date of Birth</label>
                                                <input className="ta-input" type="date" value={memberRegistrationForm.data.date_of_birth} onChange={(e) => memberRegistrationForm.setData('date_of_birth', e.target.value)} />
                                            </div>
                                        </div>
                                    )}

                                    {registrationStep === 1 && (
                                        <div className="space-y-6">
                                            <div className="grid gap-5 md:grid-cols-2">
                                                <div className="ta-card p-5">
                                                    <div className="ta-field-label">First Visit</div>
                                                    <div className="flex gap-3">
                                                        <button type="button" className={`rounded-xl px-4 py-2 text-sm ${memberRegistrationForm.data.is_first_visit ? 'bg-emerald-100 text-emerald-700' : 'border border-slate-200 bg-white text-slate-600'}`} onClick={() => memberRegistrationForm.setData('is_first_visit', true)}>Yes</button>
                                                        <button type="button" className={`rounded-xl px-4 py-2 text-sm ${memberRegistrationForm.data.is_first_visit === false ? 'bg-emerald-100 text-emerald-700' : 'border border-slate-200 bg-white text-slate-600'}`} onClick={() => memberRegistrationForm.setData('is_first_visit', false)}>No</button>
                                                    </div>
                                                </div>
                                                <div className="ta-card p-5">
                                                    <div className="ta-field-label">Preferred Visit Frequency</div>
                                                    <div className="grid grid-cols-3 gap-2">
                                                        {['Weekly', 'Monthly', 'Occasionally'].map((entry) => (
                                                            <button key={entry} type="button" className={`rounded-xl px-3 py-2 text-sm ${memberRegistrationForm.data.preferred_visit_frequency === entry ? 'bg-indigo-100 text-indigo-700' : 'border border-slate-200 bg-white text-slate-600'}`} onClick={() => memberRegistrationForm.setData('preferred_visit_frequency', entry)}>{entry}</button>
                                                        ))}
                                                    </div>
                                                </div>
                                            </div>

                                            <div className="grid gap-5 md:grid-cols-2">
                                                <div>
                                                    <label className="ta-field-label">Preferred Language</label>
                                                    <select className="ta-input" value={memberRegistrationForm.data.preferred_language} onChange={(e) => memberRegistrationForm.setData('preferred_language', e.target.value)}>
                                                        <option value="English">English</option>
                                                        <option value="Arabic">Arabic</option>
                                                        <option value="Russian">Russian</option>
                                                        <option value="Other">Other</option>
                                                    </select>
                                                </div>
                                                {memberRegistrationForm.data.preferred_language === 'Other' && (
                                                    <div>
                                                        <label className="mb-2 block text-xs uppercase tracking-[0.3em] text-stone-400">Other Language</label>
                                                        <input className="w-full rounded-2xl border border-white/10 bg-white/95 px-4 py-3 text-slate-900" value={memberRegistrationForm.data.preferred_language_other} onChange={(e) => memberRegistrationForm.setData('preferred_language_other', e.target.value)} />
                                                    </div>
                                                )}
                                                <div>
                                                    <label className="ta-field-label">How Did You Hear About Us?</label>
                                                    <select className="ta-input" value={memberRegistrationForm.data.heard_about_us} onChange={(e) => memberRegistrationForm.setData('heard_about_us', e.target.value)}>
                                                        <option value="Instagram">Instagram</option>
                                                        <option value="Google">Google</option>
                                                        <option value="Friend">Friend</option>
                                                        <option value="Walk-in">Walk-in</option>
                                                        <option value="Other">Other</option>
                                                    </select>
                                                </div>
                                                {memberRegistrationForm.data.heard_about_us === 'Other' && (
                                                    <div>
                                                        <label className="mb-2 block text-xs uppercase tracking-[0.3em] text-stone-400">Other Source</label>
                                                        <input className="w-full rounded-2xl border border-white/10 bg-white/95 px-4 py-3 text-slate-900" value={memberRegistrationForm.data.heard_about_us_other} onChange={(e) => memberRegistrationForm.setData('heard_about_us_other', e.target.value)} />
                                                    </div>
                                                )}
                                            </div>

                                            <div className="ta-card p-5">
                                                <div className="ta-field-label">Services of Interest</div>
                                                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                                                    {['Hair', 'Nails', 'Skin', 'Massage', 'Other'].map((service) => (
                                                        <button key={service} type="button" className={`rounded-xl border px-4 py-3 text-sm transition ${memberRegistrationForm.data.service_interests.includes(service) ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-600'}`} onClick={() => toggleServiceInterest(service)}>
                                                            {service}
                                                        </button>
                                                    ))}
                                                </div>
                                                {memberRegistrationForm.data.service_interests.includes('Other') && (
                                                    <div className="mt-4">
                                                        <input className="w-full rounded-2xl border border-white/10 bg-white/95 px-4 py-3 text-slate-900" placeholder="Other service interest" value={memberRegistrationForm.data.service_interests_other} onChange={(e) => memberRegistrationForm.setData('service_interests_other', e.target.value)} />
                                                    </div>
                                                )}
                                            </div>

                                            <div className="grid gap-5 md:grid-cols-2">
                                                <div className="ta-card p-5">
                                                    <div className="ta-field-label">Home Service</div>
                                                    <div className="flex gap-3">
                                                        <button type="button" className={`rounded-xl px-4 py-2 text-sm ${memberRegistrationForm.data.requires_home_service ? 'bg-emerald-100 text-emerald-700' : 'border border-slate-200 bg-white text-slate-600'}`} onClick={() => memberRegistrationForm.setData('requires_home_service', true)}>Yes</button>
                                                        <button type="button" className={`rounded-xl px-4 py-2 text-sm ${memberRegistrationForm.data.requires_home_service === false ? 'bg-emerald-100 text-emerald-700' : 'border border-slate-200 bg-white text-slate-600'}`} onClick={() => memberRegistrationForm.setData('requires_home_service', false)}>No</button>
                                                    </div>
                                                </div>
                                                <div>
                                                    <label className="ta-field-label">Spending Profile</label>
                                                    <select className="ta-input" value={memberRegistrationForm.data.spending_profile} onChange={(e) => memberRegistrationForm.setData('spending_profile', e.target.value)}>
                                                        <option value="Under AED 500">Under AED 500</option>
                                                        <option value="AED 500 – 2,000">AED 500 – 2,000</option>
                                                        <option value="AED 2,000 – 5,000">AED 2,000 – 5,000</option>
                                                        <option value="Above AED 5,000">Above AED 5,000</option>
                                                    </select>
                                                </div>
                                            </div>

                                            {memberRegistrationForm.data.requires_home_service && (
                                                <div>
                                                    <label className="ta-field-label">Home Service Location</label>
                                                    <textarea className="ta-input min-h-[110px]" value={memberRegistrationForm.data.home_service_location} onChange={(e) => memberRegistrationForm.setData('home_service_location', e.target.value)} />
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    {registrationStep === 2 && (
                                        <div className="space-y-6">
                                            <div className="grid gap-4 lg:grid-cols-3">
                                                {cardTypes.filter((type) => type.kind !== 'gift' && type.is_active).map((cardType) => (
                                                    <button
                                                        key={cardType.id}
                                                        type="button"
                                                        onClick={() => memberRegistrationForm.setData('membership_card_type_id', String(cardType.id))}
                                                        className={`rounded-2xl border p-5 text-left transition ${String(memberRegistrationForm.data.membership_card_type_id) === String(cardType.id) ? 'border-indigo-300 bg-indigo-50 shadow-sm' : 'border-slate-200 bg-white hover:bg-slate-50'}`}
                                                    >
                                                        <div className="text-xs uppercase tracking-[0.2em] text-slate-400">{cardType.kind}</div>
                                                        <div className="mt-2 text-lg font-semibold text-slate-800">{cardType.name}</div>
                                                        <div className="mt-3 text-sm text-slate-500">Validity: {cardType.validity_days ? `${cardType.validity_days} days` : 'No expiry'}</div>
                                                        <div className="mt-1 text-sm text-slate-500">Direct price: {cardType.direct_purchase_price ?? '0.00'}</div>
                                                    </button>
                                                ))}
                                            </div>
                                            {fieldError(memberRegistrationForm, 'membership_card_type_id')}
                                            <div className="grid gap-5 md:grid-cols-2">
                                                <div>
                                                    <label className="ta-field-label">Card Number Optional</label>
                                                    <input className="ta-input" value={memberRegistrationForm.data.card_number} onChange={(e) => memberRegistrationForm.setData('card_number', e.target.value)} placeholder="Auto-generate if blank" />
                                                    {fieldError(memberRegistrationForm, 'card_number')}
                                                </div>
                                                <div>
                                                    <label className="ta-field-label">NFC UID Optional</label>
                                                    <div className="flex gap-3">
                                                        <input className="ta-input" value={memberRegistrationForm.data.nfc_uid} onChange={(e) => memberRegistrationForm.setData('nfc_uid', e.target.value)} />
                                                        <button type="button" className="rounded-xl border border-sky-200 bg-sky-50 px-4 py-2 text-sm text-sky-700" onClick={() => readUidFromBridge('register')} disabled={!canManage || nfcBridgeLoadingTarget !== null}>{nfcBridgeLoadingTarget === 'register' ? 'Reading...' : 'Read UID'}</button>
                                                    </div>
                                                    {fieldError(memberRegistrationForm, 'nfc_uid')}
                                                </div>
                                                <div>
                                                    <label className="ta-field-label">Card Status</label>
                                                    <select className="ta-input" value={memberRegistrationForm.data.card_status} onChange={(e) => memberRegistrationForm.setData('card_status', e.target.value)}>
                                                        <option value="active">Active</option>
                                                        <option value="pending">Pending</option>
                                                        <option value="inactive">Inactive</option>
                                                        <option value="expired">Expired</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label className="ta-field-label">Card Notes</label>
                                                    <input className="ta-input" value={memberRegistrationForm.data.card_notes} onChange={(e) => memberRegistrationForm.setData('card_notes', e.target.value)} />
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {registrationStep === 3 && (
                                        <div className="space-y-6">
                                            <div className="ta-card border-emerald-200 bg-emerald-50 p-5">
                                                <label className="flex items-start gap-3">
                                                    <input type="checkbox" className="mt-1" checked={Boolean(memberRegistrationForm.data.consent_data_processing)} onChange={(e) => memberRegistrationForm.setData('consent_data_processing', e.target.checked)} />
                                                    <span className="text-sm text-emerald-900">I consent to the collection and processing of my personal data by Vina Luxury Beauty Salon for service delivery, appointment management, and customer care purposes.</span>
                                                </label>
                                                {fieldError(memberRegistrationForm, 'consent_data_processing')}
                                            </div>
                                            <div className="ta-card p-5">
                                                <label className="flex items-start gap-3">
                                                    <input type="checkbox" className="mt-1" checked={Boolean(memberRegistrationForm.data.consent_marketing)} onChange={(e) => memberRegistrationForm.setData('consent_marketing', e.target.checked)} />
                                                    <span className="text-sm text-slate-700">Allow promotional offers, updates, and marketing communication by SMS, WhatsApp, email, or phone call.</span>
                                                </label>
                                            </div>
                                            <div className="grid gap-5 md:grid-cols-2">
                                                <div>
                                                    <label className="ta-field-label">Signature Name</label>
                                                    <input className="ta-input" value={memberRegistrationForm.data.signature_name} onChange={(e) => memberRegistrationForm.setData('signature_name', e.target.value)} />
                                                    {fieldError(memberRegistrationForm, 'signature_name')}
                                                </div>
                                                <div>
                                                    <label className="ta-field-label">Signature Date</label>
                                                    <input className="ta-input" type="date" value={memberRegistrationForm.data.signature_date} onChange={(e) => memberRegistrationForm.setData('signature_date', e.target.value)} />
                                                    {fieldError(memberRegistrationForm, 'signature_date')}
                                                </div>
                                            </div>
                                            <div>
                                                <label className="ta-field-label">Internal Notes Optional</label>
                                                <textarea className="ta-input min-h-[120px]" value={memberRegistrationForm.data.notes} onChange={(e) => memberRegistrationForm.setData('notes', e.target.value)} />
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </Transition>
                        </div>

                        <aside className="border-t border-slate-200 bg-white p-6 lg:border-l lg:border-t-0">
                            <div className="ta-card sticky top-0 p-5">
                                <div className="text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Summary</div>
                                <div className="mt-4 space-y-4 text-sm">
                                    <div><div className="text-slate-400">Member</div><div className="font-medium text-slate-800">{memberRegistrationForm.data.full_name || 'Not entered yet'}</div></div>
                                    <div><div className="text-slate-400">Phone</div><div className="font-medium text-slate-800">{memberRegistrationForm.data.phone || 'Not entered yet'}</div></div>
                                    <div><div className="text-slate-400">Card Type</div><div className="font-medium text-slate-800">{selectedRegistrationCardType?.name || 'Not selected yet'}</div></div>
                                    <div><div className="text-slate-400">Membership Price</div><div className="font-medium text-slate-800">{selectedRegistrationCardType?.direct_purchase_price ?? '0.00'}</div></div>
                                    <div><div className="text-slate-400">Services</div><div className="font-medium text-slate-800">{(memberRegistrationForm.data.service_interests || []).join(', ') || 'Not selected yet'}</div></div>
                                    <div><div className="text-slate-400">Marketing Consent</div><div className="font-medium text-slate-800">{memberRegistrationForm.data.consent_marketing ? 'Yes' : 'No'}</div></div>
                                </div>
                            </div>
                        </aside>
                    </div>

                    <div className="flex items-center justify-between border-t border-slate-200 bg-white px-6 py-5">
                        <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm text-slate-600" onClick={registrationStep === 0 ? closeRegistrationModal : () => setRegistrationStep((prev) => Math.max(0, prev - 1))}>
                            {registrationStep === 0 ? 'Cancel' : 'Back'}
                        </button>
                        <div className="flex gap-3">
                            {registrationStep < REGISTRATION_STEPS.length - 1 ? (
                                <button type="button" className="ta-btn-primary" onClick={() => { if (validateRegistrationStep()) setRegistrationStep((prev) => Math.min(REGISTRATION_STEPS.length - 1, prev + 1)); }}>
                                    Continue
                                </button>
                            ) : (
                                <button type="submit" className="ta-btn-primary disabled:opacity-60" disabled={memberRegistrationForm.processing}>
                                    {memberRegistrationForm.processing ? 'Saving...' : 'Save Member'}
                                </button>
                            )}
                        </div>
                    </div>
                </form>
            </Modal>

            <section className="ta-card p-4">
                <div className="flex flex-wrap items-center gap-2">
                    <button type="button" className="rounded-xl bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:opacity-50" disabled={!canManage} onClick={openRegistrationModal}>Add New Member</button>
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
                Default physical tiers from the Vina loyalty card PDF are Queen, Titanium, and Gold; seeders align prices and min-points with that document.
            </p>
            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Create membership card type</h3>
                <form onSubmit={(e) => { e.preventDefault(); createCardTypeForm.post(route('loyalty.card-types.store'), { onSuccess: () => createCardTypeForm.reset('name', 'direct_purchase_price', 'validity_days') }); }} className="grid gap-3 md:grid-cols-6">
                    <div><label className="ta-field-label">Name</label><input className="ta-input" value={createCardTypeForm.data.name} onChange={(e) => createCardTypeForm.setData('name', e.target.value)} required />{fieldError(createCardTypeForm, 'name')}</div>
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
                        <tbody>{cardTypesPageRows.map((cardType) => <tr key={cardType.id} className="border-t border-slate-100"><td className="px-5 py-3 font-medium text-slate-700">{cardType.name}</td><td className="px-5 py-3 text-slate-600">{cardType.kind}</td><td className="px-5 py-3 text-slate-600">{cardType.min_points}</td><td className="px-5 py-3 text-slate-600">{cardType.validity_days ? `${cardType.validity_days} days` : 'No expiry'}</td><td className="px-5 py-3"><span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${cardType.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700'}`}>{cardType.is_active ? 'Active' : 'Inactive'}</span></td><td className="px-5 py-3"><button type="button" className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs text-indigo-700" onClick={() => startEditCardType(cardType)}>Edit</button></td></tr>)}</tbody>
                    </table>
                </div>
                {renderPager(cardTypesPage, cardTypesTotalPages, setCardTypesPage)}
            </section>

            <Modal show={Boolean(editingCardTypeId)} onClose={() => !editCardTypeForm.processing && setEditingCardTypeId(null)} maxWidth="3xl">
                <div className="p-6">
                    <h3 className="mb-4 text-base font-semibold text-slate-800">Edit card type #{editingCardTypeId}</h3>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            editCardTypeForm.put(route('loyalty.card-types.update', editingCardTypeId), {
                                preserveScroll: true,
                                onSuccess: () => setEditingCardTypeId(null),
                            });
                        }}
                        className="grid gap-3 md:grid-cols-2"
                    >
                        <div><label className="ta-field-label">Name</label><input className="ta-input" value={editCardTypeForm.data.name} onChange={(e) => editCardTypeForm.setData('name', e.target.value)} required />{fieldError(editCardTypeForm, 'name')}</div>
                        <div><label className="ta-field-label">Kind</label><select className="ta-input" value={editCardTypeForm.data.kind} onChange={(e) => editCardTypeForm.setData('kind', e.target.value)}><option value="physical">Physical</option><option value="virtual">Virtual</option><option value="gift">Gift</option></select>{fieldError(editCardTypeForm, 'kind')}</div>
                        <div><label className="ta-field-label">Min points</label><input className="ta-input" type="number" min="0" value={editCardTypeForm.data.min_points} onChange={(e) => editCardTypeForm.setData('min_points', e.target.value)} required />{fieldError(editCardTypeForm, 'min_points')}</div>
                        <div><label className="ta-field-label">Direct price</label><input className="ta-input" type="number" min="0" step="0.01" value={editCardTypeForm.data.direct_purchase_price ?? ''} onChange={(e) => editCardTypeForm.setData('direct_purchase_price', e.target.value)} />{fieldError(editCardTypeForm, 'direct_purchase_price')}</div>
                        <div><label className="ta-field-label">Validity days</label><input className="ta-input" type="number" min="1" value={editCardTypeForm.data.validity_days ?? ''} onChange={(e) => editCardTypeForm.setData('validity_days', e.target.value)} />{fieldError(editCardTypeForm, 'validity_days')}</div>
                        <div className="flex items-center gap-6 pt-7">
                            <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={editCardTypeForm.data.is_active} onChange={(e) => editCardTypeForm.setData('is_active', e.target.checked)} />Active</label>
                            <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={editCardTypeForm.data.is_transferable} onChange={(e) => editCardTypeForm.setData('is_transferable', e.target.checked)} />Transferable</label>
                        </div>
                        <div className="md:col-span-2 flex gap-2 pt-2">
                            <button className="ta-btn-primary" disabled={editCardTypeForm.processing || !canManage}>Save</button>
                            <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setEditingCardTypeId(null)}>Cancel</button>
                        </div>
                    </form>
                </div>
            </Modal>

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
                <div className="border-b border-slate-200 px-5 py-4">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                        <h3 className="text-sm font-semibold text-slate-700">NFC card registry</h3>
                        <div className="grid gap-2 md:grid-cols-3">
                            <input className="ta-input" value={registrySearch} onChange={(e) => setRegistrySearch(e.target.value)} placeholder="Search customer, card, UID" />
                            <select className="ta-input" value={registryTypeFilter} onChange={(e) => setRegistryTypeFilter(e.target.value)}>
                                <option value="">All card types</option>
                                {cardTypes.map((cardType) => <option key={cardType.id} value={cardType.id}>{cardType.name}</option>)}
                            </select>
                            <select className="ta-input" value={registryStatusFilter} onChange={(e) => setRegistryStatusFilter(e.target.value)}>
                                <option value="">All statuses</option>
                                <option value="pending">pending</option>
                                <option value="active">active</option>
                                <option value="inactive">inactive</option>
                                <option value="expired">expired</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Card</th><th className="px-5 py-3">Type</th><th className="px-5 py-3">NFC UID</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Actions</th></tr></thead>
                        <tbody>
                            {nfcRegistryPageRows.length === 0 && <tr><td className="px-5 py-3 text-slate-500" colSpan="6">No NFC cards match the current filters.</td></tr>}
                            {nfcRegistryPageRows.map((card) => <tr key={card.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-700">{card.customer_id == null ? <span className="text-amber-700">Inventory (unassigned)</span> : card.customer_name}<div className="text-xs text-slate-500">{card.customer_id == null ? '—' : card.customer_phone || 'No phone'}</div></td><td className="px-5 py-3 text-slate-600">{card.card_number || '—'}</td><td className="px-5 py-3 text-slate-600">{card.card_type_name}</td><td className="px-5 py-3 text-slate-600">{card.nfc_uid || 'Unbound'}</td><td className="px-5 py-3 text-slate-600">{card.status}</td><td className="px-5 py-3"><div className="flex flex-wrap gap-2"><button type="button" className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700" onClick={() => startEditMembershipCard(card)}>Edit</button><button type="button" className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700" onClick={() => { if (window.confirm(`Delete card ${card.card_number || card.id}?`)) { router.delete(route('loyalty.cards.destroy', card.id), { preserveScroll: true }); } }}>Delete</button><button type="button" className="rounded-lg border border-sky-200 bg-sky-50 px-2.5 py-1 text-xs font-medium text-sky-700 disabled:cursor-not-allowed disabled:opacity-50" onClick={() => copyNfcPortalUrl(card.nfc_uid)} disabled={!card.nfc_uid}>Copy NFC URL</button><button type="button" className="rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 disabled:cursor-not-allowed disabled:opacity-50" onClick={() => openNfcPortalUrl(card.nfc_uid)} disabled={!card.nfc_uid}>Open NFC URL</button></div></td></tr>)}
                        </tbody>
                    </table>
                </div>
                {renderPager(nfcRegistryPage, nfcRegistryTotalPages, setNfcRegistryPage)}
            </section>

            <section className="ta-card overflow-hidden">
                <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <h3 className="text-sm font-semibold text-slate-700">Membership Registrations</h3>
                    <p className="text-xs text-slate-500">Latest digital registration forms saved from the multi-step wizard.</p>
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Date</th><th className="px-5 py-3">Member</th><th className="px-5 py-3">Membership</th><th className="px-5 py-3">Card Number</th><th className="px-5 py-3">Language</th><th className="px-5 py-3">Visit Frequency</th><th className="px-5 py-3">First Visit</th><th className="px-5 py-3">Marketing</th><th className="px-5 py-3">Registered By</th></tr></thead>
                        <tbody>
                            {registrationPageRows.length === 0 && <tr><td className="px-5 py-3 text-slate-500" colSpan="9">No membership registrations saved yet.</td></tr>}
                            {registrationPageRows.map((registration) => <tr key={registration.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-600">{registration.registration_date || '-'}</td><td className="px-5 py-3 text-slate-700">{registration.customer_name}<div className="text-xs text-slate-500">{registration.phone || '-'} {registration.email ? `· ${registration.email}` : ''}</div></td><td className="px-5 py-3 text-slate-600">{registration.membership_type_name || '-'}</td><td className="px-5 py-3 text-slate-600">{registration.membership_card_number || '-'}</td><td className="px-5 py-3 text-slate-600">{registration.preferred_language || '-'}</td><td className="px-5 py-3 text-slate-600">{registration.preferred_visit_frequency || '-'}</td><td className="px-5 py-3 text-slate-600">{registration.is_first_visit ? 'Yes' : 'No'}</td><td className="px-5 py-3 text-slate-600">{registration.consent_marketing ? 'Yes' : 'No'}</td><td className="px-5 py-3 text-slate-600">{registration.registered_by_name || '-'}</td></tr>)}
                        </tbody>
                    </table>
                </div>
                {renderPager(registrationPage, registrationTotalPages, setRegistrationPage)}
            </section>

            <Modal show={Boolean(editingMembershipCardId)} onClose={() => !editMembershipCardForm.processing && setEditingMembershipCardId(null)} maxWidth="3xl">
                <div className="p-6">
                    <h3 className="mb-4 text-base font-semibold text-slate-800">Edit membership card #{editingMembershipCardId}</h3>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            editMembershipCardForm.put(route('loyalty.cards.update', editingMembershipCardId), {
                                preserveScroll: true,
                                onSuccess: () => setEditingMembershipCardId(null),
                            });
                        }}
                        className="grid gap-3 md:grid-cols-2"
                    >
                        <div><label className="ta-field-label">Card type</label><select className="ta-input" value={editMembershipCardForm.data.membership_card_type_id} onChange={(e) => editMembershipCardForm.setData('membership_card_type_id', e.target.value)} required><option value="">Select card type</option>{cardTypes.map((cardType) => <option key={cardType.id} value={cardType.id}>{cardType.name}</option>)}</select>{fieldError(editMembershipCardForm, 'membership_card_type_id')}</div>
                        <div><label className="ta-field-label">Status</label><select className="ta-input" value={editMembershipCardForm.data.status} onChange={(e) => editMembershipCardForm.setData('status', e.target.value)} required><option value="pending">pending</option><option value="active">active</option><option value="inactive">inactive</option><option value="expired">expired</option></select>{fieldError(editMembershipCardForm, 'status')}</div>
                        <div><label className="ta-field-label">Card number</label><input className="ta-input" value={editMembershipCardForm.data.card_number} onChange={(e) => editMembershipCardForm.setData('card_number', e.target.value)} required />{fieldError(editMembershipCardForm, 'card_number')}</div>
                        <div><label className="ta-field-label">NFC UID</label><input className="ta-input" value={editMembershipCardForm.data.nfc_uid} onChange={(e) => editMembershipCardForm.setData('nfc_uid', e.target.value)} />{fieldError(editMembershipCardForm, 'nfc_uid')}</div>
                        <div className="md:col-span-2"><label className="ta-field-label">Notes</label><textarea className="ta-input min-h-[96px]" value={editMembershipCardForm.data.notes} onChange={(e) => editMembershipCardForm.setData('notes', e.target.value)} />{fieldError(editMembershipCardForm, 'notes')}</div>
                        {editingMembershipCardActsAsGift ? (
                            <div className="md:col-span-2 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                                <p className="text-sm font-semibold text-emerald-900">Refill gift card balance</p>
                                <div className="mt-3 grid gap-3 md:grid-cols-2">
                                    <div>
                                        <label className="ta-field-label">Refill amount</label>
                                        <input
                                            className="ta-input"
                                            type="number"
                                            min="0.01"
                                            step="0.01"
                                            value={refillMembershipCardForm.data.amount}
                                            onChange={(e) => refillMembershipCardForm.setData('amount', e.target.value)}
                                            placeholder="300"
                                        />
                                        {fieldError(refillMembershipCardForm, 'amount')}
                                    </div>
                                    <div>
                                        <label className="ta-field-label">Refill notes</label>
                                        <input
                                            className="ta-input"
                                            value={refillMembershipCardForm.data.notes}
                                            onChange={(e) => refillMembershipCardForm.setData('notes', e.target.value)}
                                            placeholder="Top up for customer"
                                        />
                                        {fieldError(refillMembershipCardForm, 'notes')}
                                    </div>
                                </div>
                                {fieldError(refillMembershipCardForm, 'membership_card')}
                                <div className="mt-3">
                                    <button
                                        type="button"
                                        className="rounded-xl border border-emerald-300 bg-white px-4 py-2 text-sm font-semibold text-emerald-800"
                                        disabled={refillMembershipCardForm.processing || !canManage}
                                        onClick={() => {
                                            refillMembershipCardForm.post(route('loyalty.cards.refill', editingMembershipCardId), {
                                                preserveScroll: true,
                                                onSuccess: () => refillMembershipCardForm.reset(),
                                            });
                                        }}
                                    >
                                        Refill
                                    </button>
                                </div>
                            </div>
                        ) : null}
                        <div className="md:col-span-2 flex gap-2">
                            <button className="ta-btn-primary" disabled={editMembershipCardForm.processing || !canManage}>Save</button>
                            <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setEditingMembershipCardId(null)}>Cancel</button>
                            <button
                                type="button"
                                className="rounded-xl border border-red-200 bg-red-50 px-4 py-2 text-sm text-red-700"
                                onClick={() => {
                                    if (!window.confirm('Delete this membership card?')) return;
                                    router.delete(route('loyalty.cards.destroy', editingMembershipCardId), {
                                        preserveScroll: true,
                                        onSuccess: () => setEditingMembershipCardId(null),
                                    });
                                }}
                            >
                                Delete
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>

            <section className="ta-card overflow-hidden">
                <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                    <h3 className="text-sm font-semibold text-slate-700">Membership Customers</h3>
                    <div className="w-64 max-w-full">
                        <select className="ta-input" value={membershipCardTypeFilter} onChange={(e) => setMembershipCardTypeFilter(e.target.value)}>
                            <option value="">All card types</option>
                            {cardTypes.map((cardType) => <option key={cardType.id} value={cardType.id}>{cardType.name}</option>)}
                        </select>
                    </div>
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Membership ID</th><th className="px-5 py-3">Member Full Name</th><th className="px-5 py-3">Card Number</th><th className="px-5 py-3">Membership Start</th><th className="px-5 py-3">Card Type</th><th className="px-5 py-3">Points Balance</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Phone</th><th className="px-5 py-3">Email</th><th className="px-5 py-3">Notes</th></tr></thead>
                        <tbody>
                            {membershipCustomers.length === 0 && <tr><td className="px-5 py-3 text-slate-500" colSpan="10">No membership customers found for this filter.</td></tr>}
                            {membershipCustomersPageRows.map((card) => (
                                <tr
                                    key={card.id}
                                    className={`cursor-pointer border-t border-slate-100 ${String(selectedMembershipCardId) === String(card.id) ? 'bg-indigo-50' : 'hover:bg-slate-50'}`}
                                    onClick={() => setSelectedMembershipCardId(card.id)}
                                >
                                    <td className="px-5 py-3 text-slate-700">{card.id}</td>
                                    <td className="px-5 py-3 text-slate-700">{card.customer_name}</td>
                                    <td className="px-5 py-3 text-slate-600">{card.card_number || '-'}</td>
                                    <td className="px-5 py-3 text-slate-600">{card.activated_at ? new Date(card.activated_at).toLocaleDateString() : (card.issued_at ? new Date(card.issued_at).toLocaleDateString() : '-')}</td>
                                    <td className="px-5 py-3 text-slate-600">{card.card_type_name || '-'}</td>
                                    <td className="px-5 py-3 text-slate-600">{pointsByCustomerId[String(card.customer_id)] ?? 0}</td>
                                    <td className="px-5 py-3 text-slate-600">{card.status}</td>
                                    <td className="px-5 py-3 text-slate-600">{card.customer_phone || '-'}</td>
                                    <td className="px-5 py-3 text-slate-600">{card.customer_email || '-'}</td>
                                    <td className="px-5 py-3 text-slate-600">{card.notes || '-'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {renderPager(membershipCustomersPage, membershipCustomersTotalPages, setMembershipCustomersPage)}
            </section>

            {selectedMembershipCard && (
                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Card & Customer Details</h3>
                    <div className="mb-4 grid gap-3 md:grid-cols-4">
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm"><div className="text-xs text-slate-500">Member</div><div className="font-medium text-slate-800">{selectedMembershipCard.customer_name}</div></div>
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm"><div className="text-xs text-slate-500">Card</div><div className="font-medium text-slate-800">{selectedMembershipCard.card_number || '-'}</div></div>
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm"><div className="text-xs text-slate-500">Card Type</div><div className="font-medium text-slate-800">{selectedMembershipCard.card_type_name || '-'}</div></div>
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm"><div className="text-xs text-slate-500">Points Balance</div><div className="font-medium text-slate-800">{pointsByCustomerId[String(selectedMembershipCard.customer_id)] ?? 0}</div></div>
                    </div>

                    <div className="grid gap-4 md:grid-cols-3">
                        <div className="rounded-lg border border-slate-200">
                            <div className="border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-600">Usage History</div>
                            <div className="max-h-64 overflow-auto p-3 text-sm">
                                {selectedUsageHistory.length === 0 && <div className="text-slate-500">No usage history found.</div>}
                                {selectedUsageHistory.map((row) => <div key={row.id} className="mb-2 rounded border border-slate-100 p-2 text-slate-700">{row.label}</div>)}
                            </div>
                        </div>
                        <div className="rounded-lg border border-slate-200">
                            <div className="border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-600">Rewards History</div>
                            <div className="max-h-64 overflow-auto p-3 text-sm">
                                {selectedRewardsHistory.length === 0 && <div className="text-slate-500">No rewards history found.</div>}
                                {selectedRewardsHistory.map((row) => <div key={row.id} className="mb-2 rounded border border-slate-100 p-2 text-slate-700">{row.reward_name} ({row.points_spent} pts)</div>)}
                            </div>
                        </div>
                        <div className="rounded-lg border border-slate-200">
                            <div className="border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-slate-600">Points Activity</div>
                            <div className="max-h-64 overflow-auto p-3 text-sm">
                                {selectedPointsHistory.length === 0 && <div className="text-slate-500">No points activity found.</div>}
                                {selectedPointsHistory.map((row) => <div key={row.id} className="mb-2 rounded border border-slate-100 p-2 text-slate-700">{row.reason}: {row.points_change > 0 ? `+${row.points_change}` : row.points_change} (balance {row.balance_after})</div>)}
                            </div>
                        </div>
                    </div>
                </section>
            )}
        </div>
    );
}

