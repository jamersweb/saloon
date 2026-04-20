import ConfirmActionModal from '@/Components/ConfirmActionModal';
import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { flushSync } from 'react-dom';
import { useEffect, useMemo, useRef, useState } from 'react';

const statusLabels = { pending: 'Pending', confirmed: 'Confirm', in_progress: 'Start', completed: 'Complete', cancelled: 'Cancel', no_show: 'No-show' };
const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;
const isSeedReferenceNote = (value) => /^SEED-APPT-\d{12}-\d+$/i.test(String(value || '').trim());
const pad2 = (value) => String(value).padStart(2, '0');

/** Parse datetime-local string to epoch ms (local); invalid → NaN. */
const dateTimeLocalMs = (value) => {
    if (!value) return Number.NaN;
    const ms = new Date(value).getTime();

    return Number.isNaN(ms) ? Number.NaN : ms;
};

/** Compare two datetime-local values (-1 / 0 / 1). Falls back to string compare if unparsable. */
const dateTimeLocalCompare = (a, b) => {
    const ta = dateTimeLocalMs(a);
    const tb = dateTimeLocalMs(b);
    if (Number.isNaN(ta) || Number.isNaN(tb)) return String(a).localeCompare(String(b));

    if (ta < tb) return -1;
    if (ta > tb) return 1;

    return 0;
};

const toDateTimeLocal = (value) => {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';

    return `${date.getFullYear()}-${pad2(date.getMonth() + 1)}-${pad2(date.getDate())}T${pad2(date.getHours())}:${pad2(date.getMinutes())}`;
};
const formatDateTime = (value) => value ? new Date(value).toLocaleString() : 'N/A';
const localYmd = (d) => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;

const salonClockBoundary = (bookingRules, key, fallback) => {
    const raw = String(bookingRules?.[key] || fallback);
    const m = raw.match(/^(\d{1,2}):(\d{2})/);
    if (!m) return { h: 9, m: 0 };

    return { h: Math.min(23, Math.max(0, parseInt(m[1], 10))), m: Math.min(59, Math.max(0, parseInt(m[2], 10))) };
};

/** Earliest selectable instant for a calendar day: salon open, or (on today) the later of that and now+min advance snapped up to slot interval. */
const salonSelectableBoundsForYmd = (dateYmd, bookingRules, slotIntervalMinutes) => {
    const open = salonClockBoundary(bookingRules, 'opening_time', '09:00');
    const close = salonClockBoundary(bookingRules, 'closing_time', '22:00');
    let minM = open.h * 60 + open.m;
    const closeM = close.h * 60 + close.m;
    const max = `${dateYmd}T${pad2(close.h)}:${pad2(close.m)}`;

    const todayYmd = localYmd(new Date());
    const step = Math.max(1, Number(slotIntervalMinutes || 30));
    const minAdv = Math.max(0, Number(bookingRules?.min_advance_minutes || 0));

    if (dateYmd === todayYmd) {
        const threshold = new Date(Date.now() + minAdv * 60000);
        threshold.setSeconds(0, 0);
        const thYmd = localYmd(threshold);
        if (thYmd > dateYmd) {
            return { min: max, max };
        }
        const [Y, M, D] = dateYmd.split('-').map((n) => parseInt(n, 10));
        const dayStart = new Date(Y, M - 1, D);
        const minsFloat = (threshold.getTime() - dayStart.getTime()) / 60000;
        const policyFloor = Math.ceil(minsFloat / step) * step;
        minM = Math.max(minM, policyFloor);
    }

    const minH = Math.floor(minM / 60);
    const minMin = minM % 60;
    let min = `${dateYmd}T${pad2(minH)}:${pad2(minMin)}`;
    if (dateTimeLocalCompare(min, max) > 0) min = max;

    return { min, max };
};

/** Full salon window for one calendar day (used for ends and suggested end). */
const clampDateTimeLocalToSalon = (value, bookingRules, slotIntervalMinutes = 30) => {
    if (!value || !bookingRules) return value;
    const [d] = value.split('T');
    if (!d) return value;
    const { min, max } = salonSelectableBoundsForYmd(d, bookingRules, slotIntervalMinutes);
    if (dateTimeLocalCompare(value, min) < 0) return min;
    if (dateTimeLocalCompare(value, max) > 0) return max;

    return value;
};

/** Staff start time: enforce open/today policy floor only; end-of-day ceiling (salon close is enforced on end time server-side). */
const clampStaffStartDatetimeLocal = (value, bookingRules, slotIntervalMinutes = 30) => {
    if (!value || !bookingRules) return value;
    const [d] = value.split('T');
    if (!d) return value;
    const { min } = salonSelectableBoundsForYmd(d, bookingRules, slotIntervalMinutes);
    const ceiling = `${d}T23:59`;
    let v = value;
    if (dateTimeLocalCompare(v, min) < 0) v = min;
    if (dateTimeLocalCompare(v, ceiling) > 0) v = ceiling;

    return v;
};

export default function AppointmentsIndex({ appointments, services, customers = [], staffProfiles, inventoryItems, statusFilter, bookingRules, defaultStart, gift_cards_for_checkout = [] }) {
    const { flash, auth } = usePage().props;
    const canManageFinance = Boolean(auth?.permissions?.can_manage_finance);
    const canCollectPayments = Boolean(auth?.permissions?.can_collect_payments);
    const canCheckout = canManageFinance || canCollectPayments;
    const [editingId, setEditingId] = useState(null);
    const [startServiceId, setStartServiceId] = useState(null);
    const [completeServiceId, setCompleteServiceId] = useState(null);
    const [createEndManuallySet, setCreateEndManuallySet] = useState(false);
    const [createCustomerMode, setCreateCustomerMode] = useState('new');
    const [createSelectedCustomerId, setCreateSelectedCustomerId] = useState('');
    const [editCustomerMode, setEditCustomerMode] = useState('new');
    const [editSelectedCustomerId, setEditSelectedCustomerId] = useState('');
    const [editEndManuallySet, setEditEndManuallySet] = useState(true);
    const [deleteAppointmentId, setDeleteAppointmentId] = useState(null);
    const [deleteAppointmentBusy, setDeleteAppointmentBusy] = useState(false);
    const [checkoutFlow, setCheckoutFlow] = useState('draft');
    const slotIntervalMinutes = Math.max(1, Number(bookingRules?.slot_interval_minutes || 30));

    const createStartRef = useRef(null);
    const editStartRef = useRef(null);
    const [createStartMount, setCreateStartMount] = useState(0);
    const [createStartYmd, setCreateStartYmd] = useState(() => ((defaultStart || '').split('T')[0] || localYmd(new Date())));
    const [editStartYmd, setEditStartYmd] = useState(() => localYmd(new Date()));
    const [editStartMountKey, setEditStartMountKey] = useState(0);

    const createForm = useForm({ customer_name: '', customer_phone: '', customer_email: '', service_id: '', staff_profile_id: '', scheduled_start: defaultStart || '', scheduled_end: '', status: 'confirmed', notes: '' });
    const editForm = useForm({ customer_name: '', customer_phone: '', customer_email: '', service_id: '', staff_profile_id: '', scheduled_start: '', scheduled_end: '', status: 'confirmed', notes: '' });
    const startForm = useForm({ intake_notes: '', service_notes: '', before_photo: null });
    const completeForm = useForm({
        service_report: '',
        completion_notes: '',
        materials_used: '',
        exclude_loyalty_earn: false,
        create_tax_invoice_draft: true,
        finish_and_pay: false,
        checkout_payment_method: 'cash',
        checkout_gift_card_id: '',
        checkout_paid_at: new Date().toISOString().slice(0, 16),
        after_photo: null,
        products: [{ inventory_item_id: '', quantity: 1, notes: '' }],
    });

    useEffect(() => {
        const y = (defaultStart || '').split('T')[0] || localYmd(new Date());
        setCreateStartYmd(y);
    }, [defaultStart]);

    const createStartDefault = useMemo(
        () => clampStaffStartDatetimeLocal(defaultStart || '', bookingRules, slotIntervalMinutes),
        [bookingRules, defaultStart, slotIntervalMinutes, createStartMount],
    );

    useEffect(() => {
        const el = createStartRef.current;
        if (!el || document.activeElement === el) {
            return;
        }
        const next = createForm.data.scheduled_start || '';
        if (next && el.value !== next) {
            el.value = next;
        }
    }, [createForm.data.scheduled_start]);

    useEffect(() => {
        const el = editStartRef.current;
        if (!el || document.activeElement === el || !editingId) {
            return;
        }
        const next = editForm.data.scheduled_start || '';
        if (next && el.value !== next) {
            el.value = next;
        }
    }, [editForm.data.scheduled_start, editingId]);

    const calculateSuggestedEnd = (startValue, serviceId) => {
        if (!startValue || !serviceId) return '';

        const service = services.find((s) => String(s.id) === String(serviceId));
        if (!service) return '';

        const startDate = new Date(startValue);
        if (Number.isNaN(startDate.getTime())) return '';

        const durationMinutes = Number(service.duration_minutes || 0);
        const bufferMinutes = Number(service.buffer_minutes || 0);
        const totalMinutes = durationMinutes + bufferMinutes;

        startDate.setMinutes(startDate.getMinutes() + totalMinutes);

        let endStr = toDateTimeLocal(startDate);
        endStr = clampDateTimeLocalToSalon(endStr, bookingRules, slotIntervalMinutes);
        if (startValue && dateTimeLocalCompare(endStr, startValue) < 0) {
            endStr = clampDateTimeLocalToSalon(startValue, bookingRules, slotIntervalMinutes);
        }

        return endStr;
    };

    const handleCreateServiceChange = (value) => {
        const startVal = createStartRef.current?.value || createForm.data.scheduled_start || '';
        createForm.setData((prev) => ({
            ...prev,
            service_id: value,
            scheduled_end: !createEndManuallySet || !prev.scheduled_end
                ? calculateSuggestedEnd(startVal, value)
                : prev.scheduled_end,
        }));
    };

    const handleCreateEndChange = (value) => {
        setCreateEndManuallySet(Boolean(value));
        if (!value) {
            createForm.setData('scheduled_end', '');
            return;
        }
        const [d] = value.split('T');
        if (!d) {
            createForm.setData('scheduled_end', value);
            return;
        }
        const { min, max } = salonSelectableBoundsForYmd(d, bookingRules, slotIntervalMinutes);
        const start = createStartRef.current?.value || createForm.data.scheduled_start || '';
        const floor = start && start.startsWith(`${d}T`) ? start : min;
        let v = value;
        if (dateTimeLocalCompare(v, floor) < 0) v = floor;
        if (dateTimeLocalCompare(v, max) > 0) v = max;
        createForm.setData('scheduled_end', clampDateTimeLocalToSalon(v, bookingRules, slotIntervalMinutes));
    };

    const handleEditEndChange = (value) => {
        setEditEndManuallySet(Boolean(value));
        if (!value) {
            editForm.setData('scheduled_end', '');
            return;
        }
        const [d] = value.split('T');
        if (!d) {
            editForm.setData('scheduled_end', value);
            return;
        }
        const { min, max } = salonSelectableBoundsForYmd(d, bookingRules, slotIntervalMinutes);
        const start = editStartRef.current?.value || editForm.data.scheduled_start || '';
        const floor = start && start.startsWith(`${d}T`) ? start : min;
        let v = value;
        if (dateTimeLocalCompare(v, floor) < 0) v = floor;
        if (dateTimeLocalCompare(v, max) > 0) v = max;
        editForm.setData('scheduled_end', clampDateTimeLocalToSalon(v, bookingRules, slotIntervalMinutes));
    };

    const handleEditServiceChange = (value) => {
        const startVal = editStartRef.current?.value || editForm.data.scheduled_start || '';
        editForm.setData((prev) => ({
            ...prev,
            service_id: value,
            scheduled_end: !editEndManuallySet || !prev.scheduled_end
                ? calculateSuggestedEnd(startVal, value)
                : prev.scheduled_end,
        }));
    };

    const syncCreateStartFromInput = (rawValue) => {
        const [ymd] = (rawValue || '').split('T');
        if (ymd) setCreateStartYmd(ymd);
        const clamped = clampStaffStartDatetimeLocal(rawValue || '', bookingRules, slotIntervalMinutes);
        createForm.setData((prev) => ({
            ...prev,
            scheduled_start: clamped,
            scheduled_end: !createEndManuallySet || !prev.scheduled_end
                ? calculateSuggestedEnd(clamped, prev.service_id)
                : prev.scheduled_end,
        }));
        if (createStartRef.current && createStartRef.current.value !== clamped) {
            createStartRef.current.value = clamped;
        }
    };

    const syncEditStartFromInput = (rawValue) => {
        const [ymd] = (rawValue || '').split('T');
        if (ymd) setEditStartYmd(ymd);
        const clamped = clampStaffStartDatetimeLocal(rawValue || '', bookingRules, slotIntervalMinutes);
        editForm.setData((prev) => ({
            ...prev,
            scheduled_start: clamped,
            scheduled_end: !editEndManuallySet || !prev.scheduled_end
                ? calculateSuggestedEnd(clamped, prev.service_id)
                : prev.scheduled_end,
        }));
        if (editStartRef.current && editStartRef.current.value !== clamped) {
            editStartRef.current.value = clamped;
        }
    };

    const applyCustomerToCreateForm = (customer) => {
        createForm.setData('customer_name', customer?.name ?? '');
        createForm.setData('customer_phone', customer?.phone ?? '');
        createForm.setData('customer_email', customer?.email ?? '');
    };

    const applyCustomerToEditForm = (customer) => {
        editForm.setData('customer_name', customer?.name ?? '');
        editForm.setData('customer_phone', customer?.phone ?? '');
        editForm.setData('customer_email', customer?.email ?? '');
    };

    const startEdit = (appt) => {
        const startStr = toDateTimeLocal(appt.scheduled_start);
        setEditStartYmd(startStr.split('T')[0] || localYmd(new Date()));
        setEditingId(appt.id);
        setEditCustomerMode('new');
        setEditSelectedCustomerId('');
        setEditEndManuallySet(Boolean(appt.scheduled_end));
        setEditStartMountKey((k) => k + 1);
        editForm.setData({
            customer_name: appt.customer_name || '',
            customer_phone: appt.customer_phone || '',
            customer_email: appt.customer_email || '',
            service_id: appt.service_id || '',
            staff_profile_id: appt.staff_profile_id || '',
            scheduled_start: startStr,
            scheduled_end: toDateTimeLocal(appt.scheduled_end),
            status: appt.status || 'confirmed',
            notes: appt.notes || '',
        });
        editForm.clearErrors();
    };

    const openStartService = (appt) => {
        setStartServiceId(appt.id);
        setCompleteServiceId(null);
        startForm.setData({
            intake_notes: appt.service_execution?.intake_notes || '',
            service_notes: appt.service_execution?.service_notes || (isSeedReferenceNote(appt.notes) ? '' : (appt.notes || '')),
            before_photo: null,
        });
        startForm.clearErrors();
    };

    const openCompleteService = (appt) => {
        setCompleteServiceId(appt.id);
        setStartServiceId(null);
        setCheckoutFlow(canCheckout ? 'draft' : 'skip');
        completeForm.setData({
            service_report: appt.notes || '',
            completion_notes: appt.service_execution?.completion_notes || '',
            materials_used: appt.service_execution?.materials_used || '',
            exclude_loyalty_earn: false,
            create_tax_invoice_draft: canCheckout,
            finish_and_pay: false,
            checkout_payment_method: 'cash',
            checkout_gift_card_id: '',
            checkout_paid_at: new Date().toISOString().slice(0, 16),
            after_photo: null,
            products: appt.product_usages?.length
                ? appt.product_usages.map((usage) => ({
                    inventory_item_id: String(inventoryItems.find((item) => item.name === usage.item_name)?.id || ''),
                    quantity: usage.quantity,
                    notes: usage.notes || '',
                }))
                : [{ inventory_item_id: '', quantity: 1, notes: '' }],
        });
        completeForm.clearErrors();
    };

    const changeFilter = (value) => router.get(route('appointments.index'), { status: value || undefined }, { preserveState: true, replace: true });
    const transition = (id, nextStatus) => router.patch(route('appointments.transition', id), { status: nextStatus });

    const updateProductRow = (index, field, value) => {
        completeForm.setData('products', completeForm.data.products.map((row, rowIndex) => rowIndex === index ? { ...row, [field]: value } : row));
    };

    const addProductRow = () => completeForm.setData('products', [...completeForm.data.products, { inventory_item_id: '', quantity: 1, notes: '' }]);
    const removeProductRow = (index) => completeForm.setData('products', completeForm.data.products.filter((_, rowIndex) => rowIndex !== index));

    const createSalonBounds = salonSelectableBoundsForYmd(createStartYmd, bookingRules, slotIntervalMinutes);
    const editSalonBounds = salonSelectableBoundsForYmd(editStartYmd, bookingRules, slotIntervalMinutes);
    const editEndYmd = (editForm.data.scheduled_end || editForm.data.scheduled_start || '').split('T')[0] || editStartYmd;
    const editEndSalonBounds = salonSelectableBoundsForYmd(editEndYmd, bookingRules, slotIntervalMinutes);
    const editingAppt = appointments.find((a) => String(a.id) === String(editingId));
    const editStartDefault = editingAppt ? toDateTimeLocal(editingAppt.scheduled_start) : (editForm.data.scheduled_start || '');

    return (
        <AuthenticatedLayout header="Appointments">
            <Head title="Appointments" />
            <div className="space-y-6">
                {flash?.created_tax_invoice_id ? (
                    <div className="ta-card flex flex-wrap items-center justify-between gap-3 border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-900">
                        <span>Tax invoice is ready for this visit — open it to adjust lines, issue the receipt, or record payment.</span>
                        <Link href={route('finance.invoices.show', flash.created_tax_invoice_id)} className="font-semibold text-indigo-700 underline">
                            Open invoice
                        </Link>
                    </div>
                ) : null}
                {canCheckout && appointments.some((a) => a.awaiting_checkout) ? (
                    <section id="checkout-alerts" className="ta-card border-amber-200 bg-amber-50/90 p-4">
                        <h3 className="mb-2 text-sm font-semibold text-amber-950">Needs checkout</h3>
                        <p className="mb-2 text-xs text-amber-900/90">Completed visits below still need a receipt issued and/or payment recorded.</p>
                        <ul className="list-inside list-disc text-sm text-amber-950">
                            {appointments
                                .filter((a) => a.awaiting_checkout)
                                .slice(0, 8)
                                .map((a) => (
                                    <li key={a.id}>
                                        #{a.id} {a.customer_name}
                                        {a.checkout_invoice_id ? (
                                            <>
                                                {' · '}
                                                <Link href={route('finance.invoices.show', a.checkout_invoice_id)} className="font-semibold text-amber-900 underline">
                                                    Open invoice
                                                </Link>
                                            </>
                                        ) : null}
                                    </li>
                                ))}
                        </ul>
                    </section>
                ) : null}
                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Create Appointment</h3>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            flushSync(() => {
                                const v = clampStaffStartDatetimeLocal(
                                    createStartRef.current?.value || createForm.data.scheduled_start || '',
                                    bookingRules,
                                    slotIntervalMinutes,
                                );
                                const [ymd] = v.split('T');
                                if (ymd) setCreateStartYmd(ymd);
                                createForm.setData('scheduled_start', v);
                            });
                            createForm.post(route('appointments.store'), {
                                onSuccess: () => {
                                    createForm.reset();
                                    const next = clampStaffStartDatetimeLocal(defaultStart || '', bookingRules, slotIntervalMinutes);
                                    createForm.setData('scheduled_start', next);
                                    setCreateStartYmd((defaultStart || '').split('T')[0] || localYmd(new Date()));
                                    setCreateStartMount((m) => m + 1);
                                    setCreateEndManuallySet(false);
                                    setCreateCustomerMode('new');
                                    setCreateSelectedCustomerId('');
                                },
                            });
                        }}
                        className="grid gap-3 md:grid-cols-4"
                    >
                        <div className="md:col-span-4 flex flex-wrap items-center gap-4 rounded-lg border border-slate-200 bg-slate-50/80 px-3 py-3">
                            <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Customer type</span>
                            <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                                <input type="radio" name="create_customer_mode" className="text-indigo-600" checked={createCustomerMode === 'new'} onChange={() => { setCreateCustomerMode('new'); setCreateSelectedCustomerId(''); }} />
                                New customer
                            </label>
                            <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                                <input type="radio" name="create_customer_mode" className="text-indigo-600" checked={createCustomerMode === 'existing'} onChange={() => { setCreateCustomerMode('existing'); setCreateSelectedCustomerId(''); applyCustomerToCreateForm(null); }} />
                                Existing customer
                            </label>
                        </div>
                        {createCustomerMode === 'existing' ? (
                            <div className="md:col-span-4">
                                <label className="ta-field-label">Select customer</label>
                                <select
                                    className="ta-input"
                                    value={createSelectedCustomerId}
                                    onChange={(e) => {
                                        const id = e.target.value;
                                        setCreateSelectedCustomerId(id);
                                        const customer = customers.find((c) => String(c.id) === id);
                                        applyCustomerToCreateForm(customer || null);
                                    }}
                                >
                                    <option value="">Search list — choose a customer…</option>
                                    {customers.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}{c.phone ? ` — ${c.phone}` : ''}
                                        </option>
                                    ))}
                                </select>
                                <p className="mt-1 text-xs text-slate-500">Full name, phone, and email fill in automatically. You can still edit them before saving.</p>
                            </div>
                        ) : null}
                        <div>
                            <label className="ta-field-label">{createCustomerMode === 'existing' ? 'Name' : 'Full name'}</label>
                            <input className="ta-input" value={createForm.data.customer_name} onChange={(e) => createForm.setData('customer_name', e.target.value)} required disabled={createCustomerMode === 'existing' && !createSelectedCustomerId} />
                            {fieldError(createForm, 'customer_name')}
                        </div>
                        <div>
                            <label className="ta-field-label">{createCustomerMode === 'existing' ? 'Phone number' : 'Phone'}</label>
                            <input className="ta-input" value={createForm.data.customer_phone} onChange={(e) => createForm.setData('customer_phone', e.target.value)} required disabled={createCustomerMode === 'existing' && !createSelectedCustomerId} />
                            {fieldError(createForm, 'customer_phone')}
                        </div>
                        <div>
                            <label className="ta-field-label">Email</label>
                            <input className="ta-input" type="email" value={createForm.data.customer_email} onChange={(e) => createForm.setData('customer_email', e.target.value)} disabled={createCustomerMode === 'existing' && !createSelectedCustomerId} />
                            {fieldError(createForm, 'customer_email')}
                        </div>
                        <div><label className="ta-field-label">Service</label><select className="ta-input" value={createForm.data.service_id} onChange={(e) => handleCreateServiceChange(e.target.value)} required><option value="">Service</option>{services.map((s) => <option key={s.id} value={s.id}>{s.name} ({s.duration_minutes}m)</option>)}</select>{fieldError(createForm, 'service_id')}</div>
                        <div><label className="ta-field-label">Staff Profile</label><select className="ta-input" value={createForm.data.staff_profile_id} onChange={(e) => createForm.setData('staff_profile_id', e.target.value)}><option value="">Unassigned Staff</option>{staffProfiles.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>{fieldError(createForm, 'staff_profile_id')}</div>
                        <div>
                            <label className="ta-field-label">Scheduled Start</label>
                            <p className="mb-1 text-xs text-slate-500">Same-day visit: keep start and end within {bookingRules?.opening_time || '09:00'}–{bookingRules?.closing_time || '22:00'}; the visit must end by closing. For today, the earliest start is the next available time after now (including minimum advance).</p>
                            <input
                                key={`create-start-${createStartMount}`}
                                ref={createStartRef}
                                className="ta-input"
                                type="datetime-local"
                                defaultValue={createStartDefault}
                                onInput={(e) => syncCreateStartFromInput(e.currentTarget.value)}
                                min={createSalonBounds.min}
                                max={`${createStartYmd}T23:59`}
                                required
                            />
                            {fieldError(createForm, 'scheduled_start')}
                        </div>
                        <div>
                            <label className="ta-field-label">Scheduled End</label>
                            <input className="ta-input" type="datetime-local" value={createForm.data.scheduled_end} onInput={(e) => handleCreateEndChange(e.currentTarget.value)} min={createSalonBounds.min} max={createSalonBounds.max} />
                            {fieldError(createForm, 'scheduled_end')}
                        </div>
                        <div><label className="ta-field-label">Status</label><select className="ta-input" value={createForm.data.status} onChange={(e) => createForm.setData('status', e.target.value)}><option value="confirmed">confirmed</option><option value="pending">pending</option></select>{fieldError(createForm, 'status')}</div>
                        <div className="md:col-span-4"><input className="ta-input" value={createForm.data.notes} onChange={(e) => createForm.setData('notes', e.target.value)} placeholder="Notes" />{fieldError(createForm, 'notes')}</div>
                        <button className="ta-btn-primary" disabled={createForm.processing}>Create</button>
                    </form>
                </section>

                <section className="ta-card p-4">
                    <label className="ta-field-label mb-2 block">Filter Status</label>
                    <div className="flex flex-wrap gap-2">
                        {[
                            { value: '', label: 'All' },
                            { value: 'pending', label: 'Pending' },
                            { value: 'confirmed', label: 'Confirmed' },
                            { value: 'upcoming', label: 'Upcoming' },
                            { value: 'completed', label: 'Completed' },
                        ].map((filter) => (
                            <button
                                key={filter.value || 'all'}
                                type="button"
                                onClick={() => changeFilter(filter.value)}
                                className={`rounded-lg border px-3 py-1.5 text-xs font-semibold ${String(statusFilter || '') === String(filter.value) ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-600'}`}
                            >
                                {filter.label}
                            </button>
                        ))}
                    </div>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Appointment Queue</h3></div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-5 py-3">Time</th>
                                    <th className="px-5 py-3">Customer</th>
                                    <th className="px-5 py-3">Service</th>
                                    <th className="px-5 py-3">Staff</th>
                                    <th className="px-5 py-3">Execution</th>
                                    <th className="px-5 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {appointments.map((a) => (
                                    <tr key={a.id} className="border-t border-slate-100 align-top">
                                        <td className="px-5 py-3 text-slate-600">{formatDateTime(a.scheduled_start)}</td>
                                        <td className="px-5 py-3">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <span className="font-medium text-slate-700">{a.customer_name}</span>
                                                {a.awaiting_checkout ? (
                                                    <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900">Needs pay</span>
                                                ) : null}
                                            </div>
                                            <div className="text-xs text-slate-500">{a.customer_phone}</div>
                                            {a.customer_email && <div className="text-xs text-slate-500">{a.customer_email}</div>}
                                        </td>
                                        <td className="px-5 py-3 text-slate-600">{a.service_name}</td>
                                        <td className="px-5 py-3 text-slate-600">{a.staff_name || 'Unassigned'}</td>
                                        <td className="px-5 py-3 text-xs text-slate-600">
                                            <div className="mb-1"><span className="rounded-full bg-slate-100 px-2.5 py-1 font-semibold text-slate-700">{a.status}</span></div>
                                            {a.service_execution?.started_at && <div>Started: {formatDateTime(a.service_execution.started_at)}</div>}
                                            {a.service_execution?.completed_at && <div>Finished: {formatDateTime(a.service_execution.completed_at)}</div>}
                                            {a.service_execution?.materials_used && <div className="mt-1">Materials: {a.service_execution.materials_used}</div>}
                                            {a.product_usages?.length > 0 && <div className="mt-1">Products: {a.product_usages.map((usage) => `${usage.item_name} x${usage.quantity}`).join(', ')}</div>}
                                            {a.photos?.length > 0 && (
                                                <div className="mt-1 flex flex-wrap gap-2">
                                                    {a.photos.map((photo) => (
                                                        <a key={photo.id} href={photo.url} target="_blank" rel="noreferrer" className="text-indigo-600 underline">
                                                            {photo.type} photo
                                                        </a>
                                                    ))}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-5 py-3">
                                            <div className="flex flex-wrap gap-2">
                                                <button className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700" onClick={() => startEdit(a)}>Edit</button>
                                                {a.status === 'confirmed' && <button className="rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700" onClick={() => openStartService(a)}>Start Service</button>}
                                                {a.status === 'in_progress' && (
                                                    <button className="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700" onClick={() => openCompleteService(a)}>
                                                        {canCheckout ? 'Finish / Pay' : 'Finish Service'}
                                                    </button>
                                                )}
                                                {a.status === 'completed' && a.awaiting_checkout && a.checkout_invoice_id ? (
                                                    <Link
                                                        href={route('finance.invoices.show', a.checkout_invoice_id)}
                                                        className="inline-flex rounded-lg border border-amber-300 bg-white px-2.5 py-1 text-xs font-medium text-amber-900 hover:bg-amber-50"
                                                    >
                                                        Checkout
                                                    </Link>
                                                ) : null}
                                                {(a.next_statuses || []).filter((next) => !['in_progress', 'completed'].includes(next)).map((next) => <button key={next} className="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50" onClick={() => transition(a.id, next)}>{statusLabels[next] || next}</button>)}
                                                <button
                                                    type="button"
                                                    className="rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-800 hover:bg-rose-100"
                                                    onClick={() => setDeleteAppointmentId(a.id)}
                                                >
                                                    Delete permanently
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

            </div>
            <Modal show={Boolean(startServiceId)} maxWidth="2xl" onClose={() => setStartServiceId(null)}>
                <div className="p-6">
                    <h3 className="mb-4 text-base font-semibold text-slate-800">Start Service for Appointment #{startServiceId}</h3>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            startForm.post(route('appointments.service-start', startServiceId), {
                                forceFormData: true,
                                onSuccess: () => {
                                    setStartServiceId(null);
                                    startForm.reset();
                                },
                            });
                        }}
                        className="grid gap-3"
                    >
                        <div>
                            <label className="ta-field-label">Client Intake Notes</label>
                            <textarea className="ta-input min-h-[110px]" value={startForm.data.intake_notes} onChange={(e) => startForm.setData('intake_notes', e.target.value)} />
                            {fieldError(startForm, 'intake_notes')}
                        </div>
                        <div>
                            <label className="ta-field-label">Staff Service Notes</label>
                            <textarea className="ta-input min-h-[110px]" value={startForm.data.service_notes} onChange={(e) => startForm.setData('service_notes', e.target.value)} />
                            {fieldError(startForm, 'service_notes')}
                        </div>
                        <div>
                            <label className="ta-field-label">Before Photo</label>
                            <input className="ta-input" type="file" accept="image/*" onChange={(e) => startForm.setData('before_photo', e.target.files?.[0] || null)} />
                            {fieldError(startForm, 'before_photo')}
                        </div>
                        <div className="flex justify-end gap-2 pt-2">
                            <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setStartServiceId(null)}>Close</button>
                            <button className="ta-btn-primary" disabled={startForm.processing}>Start Service</button>
                        </div>
                    </form>
                </div>
            </Modal>

            <Modal show={Boolean(completeServiceId)} maxWidth="2xl" onClose={() => setCompleteServiceId(null)}>
                <div className="p-6">
                    <h3 className="mb-4 text-base font-semibold text-slate-800">Complete Service for Appointment #{completeServiceId}</h3>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            completeForm.transform((data) => ({
                                ...data,
                                create_tax_invoice_draft: canCheckout && checkoutFlow !== 'skip',
                                finish_and_pay: canCheckout && checkoutFlow === 'pay',
                            }));
                            completeForm.post(route('appointments.service-complete', completeServiceId), {
                                forceFormData: true,
                                onSuccess: () => {
                                    completeForm.transform((d) => d);
                                    setCompleteServiceId(null);
                                    setCheckoutFlow('draft');
                                    completeForm.reset();
                                },
                                onFinish: () => {
                                    completeForm.transform((d) => d);
                                },
                            });
                        }}
                        className="grid gap-3 md:grid-cols-2"
                    >
                        <div className="md:col-span-2">
                            <label className="ta-field-label">Service Report</label>
                            <textarea className="ta-input min-h-[120px]" value={completeForm.data.service_report} onChange={(e) => completeForm.setData('service_report', e.target.value)} required />
                            {fieldError(completeForm, 'service_report')}
                        </div>
                        <div>
                            <label className="ta-field-label">Completion Notes</label>
                            <textarea className="ta-input min-h-[110px]" value={completeForm.data.completion_notes} onChange={(e) => completeForm.setData('completion_notes', e.target.value)} />
                            {fieldError(completeForm, 'completion_notes')}
                        </div>
                        <div>
                            <label className="ta-field-label">Materials Used</label>
                            <textarea className="ta-input min-h-[110px]" value={completeForm.data.materials_used} onChange={(e) => completeForm.setData('materials_used', e.target.value)} placeholder="Hair color, polish, extensions, treatment kits..." />
                            {fieldError(completeForm, 'materials_used')}
                        </div>
                        <div className="md:col-span-2">
                            <label className="ta-field-label">After Photo</label>
                            <input className="ta-input" type="file" accept="image/*" onChange={(e) => completeForm.setData('after_photo', e.target.files?.[0] || null)} />
                            {fieldError(completeForm, 'after_photo')}
                        </div>
                        <div className="md:col-span-2">
                            <label className="flex items-center text-sm text-slate-700">
                                <input type="checkbox" className="mr-2 rounded border-slate-300" checked={completeForm.data.exclude_loyalty_earn} onChange={(e) => completeForm.setData('exclude_loyalty_earn', e.target.checked)} />
                                Paid with gift card / no loyalty points for this visit
                            </label>
                            <p className="mt-1 text-xs text-slate-500">Matches policy when the client pays using gift card balance. You can also link gift card usage to this visit from Loyalty → Consume Gift Card.</p>
                            {fieldError(completeForm, 'exclude_loyalty_earn')}
                        </div>
                        {canCheckout ? (
                            <div className="md:col-span-2 space-y-3 rounded-xl border border-slate-200 bg-slate-50/80 p-4">
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">After this visit</p>
                                <div className="space-y-2 text-sm text-slate-700">
                                    <label className="flex cursor-pointer items-start gap-2">
                                        <input type="radio" className="mt-1" name="checkout_flow" checked={checkoutFlow === 'draft'} onChange={() => setCheckoutFlow('draft')} />
                                        <span>
                                            <span className="font-medium">Create tax invoice draft</span>
                                            <span className="mt-0.5 block text-xs text-slate-500">Default — opens the receipt screen so you can issue the tax invoice and record payment when the client is ready.</span>
                                        </span>
                                    </label>
                                    <label className="flex cursor-pointer items-start gap-2">
                                        <input type="radio" className="mt-1" name="checkout_flow" checked={checkoutFlow === 'pay'} onChange={() => setCheckoutFlow('pay')} />
                                        <span>
                                            <span className="font-medium">Finish &amp; pay now</span>
                                            <span className="mt-0.5 block text-xs text-slate-500">Completes the visit, creates the draft, issues the tax receipt number, and records one full payment in a single step.</span>
                                        </span>
                                    </label>
                                    <label className="flex cursor-pointer items-start gap-2">
                                        <input type="radio" className="mt-1" name="checkout_flow" checked={checkoutFlow === 'skip'} onChange={() => setCheckoutFlow('skip')} />
                                        <span>
                                            <span className="font-medium">No invoice from this screen</span>
                                            <span className="mt-0.5 block text-xs text-slate-500">Use when billing is handled separately (for example a package or account client).</span>
                                        </span>
                                    </label>
                                </div>
                                {checkoutFlow === 'pay' ? (
                                    <div className="grid gap-3 border-t border-slate-200 pt-3 md:grid-cols-2">
                                        <div>
                                            <label className="ta-field-label">Payment method</label>
                                            <select
                                                className="ta-input"
                                                value={completeForm.data.checkout_payment_method}
                                                onChange={(e) => completeForm.setData('checkout_payment_method', e.target.value)}
                                            >
                                                <option value="cash">Cash</option>
                                                <option value="card">Card</option>
                                                <option value="bank_transfer">Bank transfer</option>
                                                <option value="gift_card">Gift card</option>
                                                <option value="other">Other</option>
                                            </select>
                                            {fieldError(completeForm, 'checkout_payment_method')}
                                        </div>
                                        <div>
                                            <label className="ta-field-label">Paid at</label>
                                            <input
                                                type="datetime-local"
                                                className="ta-input"
                                                value={completeForm.data.checkout_paid_at}
                                                onChange={(e) => completeForm.setData('checkout_paid_at', e.target.value)}
                                            />
                                            {fieldError(completeForm, 'checkout_paid_at')}
                                        </div>
                                        {completeForm.data.checkout_payment_method === 'gift_card' ? (
                                            <div className="md:col-span-2">
                                                <label className="ta-field-label">Gift card</label>
                                                <select
                                                    className="ta-input"
                                                    value={completeForm.data.checkout_gift_card_id}
                                                    onChange={(e) => completeForm.setData('checkout_gift_card_id', e.target.value)}
                                                    required
                                                >
                                                    <option value="">Select gift card</option>
                                                    {(completeServiceId
                                                        ? gift_cards_for_checkout.filter(
                                                            (g) => !g.assigned_customer_id
                                                                || String(g.assigned_customer_id)
                                                                    === String(appointments.find((ap) => String(ap.id) === String(completeServiceId))?.customer_id),
                                                        )
                                                        : []
                                                    ).map((g) => (
                                                        <option key={g.id} value={g.id}>
                                                            {g.code} — balance {g.remaining_value}
                                                            {!g.assigned_customer_id ? ' (unassigned)' : ''}
                                                        </option>
                                                    ))}
                                                </select>
                                                <p className="mt-1 text-xs text-slate-500">Balance must cover the full invoice total. Cards assigned to another customer are hidden.</p>
                                                {fieldError(completeForm, 'checkout_gift_card_id')}
                                            </div>
                                        ) : null}
                                    </div>
                                ) : null}
                                {fieldError(completeForm, 'finish_and_pay')}
                            </div>
                        ) : null}
                        <div className="md:col-span-2 space-y-3 rounded-xl border border-slate-200 p-4">
                            <div className="flex items-center justify-between">
                                <h4 className="text-sm font-semibold text-slate-700">Products Used</h4>
                                <button type="button" className="rounded-lg border border-slate-200 px-3 py-1 text-xs font-medium text-slate-700" onClick={addProductRow}>Add Product</button>
                            </div>
                            {completeForm.data.products.map((product, index) => (
                                <div key={index} className="grid gap-3 md:grid-cols-4">
                                    <div>
                                        <label className="ta-field-label">Inventory Item</label>
                                        <select className="ta-input" value={product.inventory_item_id} onChange={(e) => updateProductRow(index, 'inventory_item_id', e.target.value)}>
                                            <option value="">Select product</option>
                                            {inventoryItems.map((item) => <option key={item.id} value={item.id}>{item.name} ({item.sku})</option>)}
                                        </select>
                                        {fieldError(completeForm, `products.${index}.inventory_item_id`)}
                                    </div>
                                    <div>
                                        <label className="ta-field-label">Quantity</label>
                                        <input className="ta-input" type="number" min="1" value={product.quantity} onChange={(e) => updateProductRow(index, 'quantity', e.target.value)} />
                                        {fieldError(completeForm, `products.${index}.quantity`)}
                                    </div>
                                    <div className="md:col-span-2">
                                        <label className="ta-field-label">Usage Notes</label>
                                        <div className="flex gap-2">
                                            <input className="ta-input" value={product.notes} onChange={(e) => updateProductRow(index, 'notes', e.target.value)} placeholder="Optional notes" />
                                            {completeForm.data.products.length > 1 && (
                                                <button type="button" className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-700" onClick={() => removeProductRow(index)}>Remove</button>
                                            )}
                                        </div>
                                        {fieldError(completeForm, `products.${index}.notes`)}
                                    </div>
                                </div>
                            ))}
                        </div>
                        <div className="md:col-span-2 flex flex-wrap justify-end gap-2 pt-2">
                            <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setCompleteServiceId(null)}>Close</button>
                            <button className="ta-btn-primary" disabled={completeForm.processing}>
                                {checkoutFlow === 'pay' && canCheckout ? 'Finish & pay' : 'Finish service'}
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>

            <Modal show={Boolean(editingId)} maxWidth="2xl" onClose={() => setEditingId(null)}>
                <div className="p-6">
                    <h3 className="mb-4 text-base font-semibold text-slate-800">Edit Appointment #{editingId}</h3>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            flushSync(() => {
                                const v = clampStaffStartDatetimeLocal(
                                    editStartRef.current?.value || editForm.data.scheduled_start || '',
                                    bookingRules,
                                    slotIntervalMinutes,
                                );
                                const [ymd] = v.split('T');
                                if (ymd) setEditStartYmd(ymd);
                                editForm.setData('scheduled_start', v);
                            });
                            editForm.put(route('appointments.update', editingId), {
                                onSuccess: () => {
                                    setEditingId(null);
                                    setEditCustomerMode('new');
                                    setEditSelectedCustomerId('');
                                },
                            });
                        }}
                        className="grid gap-3 md:grid-cols-2"
                    >
                        <div className="md:col-span-2 flex flex-wrap items-center gap-4 rounded-lg border border-slate-200 bg-slate-50/80 px-3 py-3">
                            <span className="text-xs font-semibold uppercase tracking-wide text-slate-500">Customer type</span>
                            <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                                <input type="radio" name="edit_customer_mode" className="text-indigo-600" checked={editCustomerMode === 'new'} onChange={() => { setEditCustomerMode('new'); setEditSelectedCustomerId(''); }} />
                                Keep / edit details
                            </label>
                            <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                                <input type="radio" name="edit_customer_mode" className="text-indigo-600" checked={editCustomerMode === 'existing'} onChange={() => { setEditCustomerMode('existing'); setEditSelectedCustomerId(''); }} />
                                Link to existing customer
                            </label>
                        </div>
                        {editCustomerMode === 'existing' ? (
                            <div className="md:col-span-2">
                                <label className="ta-field-label">Select customer</label>
                                <select
                                    className="ta-input"
                                    value={editSelectedCustomerId}
                                    onChange={(e) => {
                                        const id = e.target.value;
                                        setEditSelectedCustomerId(id);
                                        const customer = customers.find((c) => String(c.id) === id);
                                        applyCustomerToEditForm(customer || null);
                                    }}
                                >
                                    <option value="">Choose a customer…</option>
                                    {customers.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}{c.phone ? ` — ${c.phone}` : ''}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        ) : null}
                        <div><label className="ta-field-label">{editCustomerMode === 'existing' ? 'Name' : 'Full name'}</label><input className="ta-input" value={editForm.data.customer_name} onChange={(e) => editForm.setData('customer_name', e.target.value)} required />{fieldError(editForm, 'customer_name')}</div>
                        <div><label className="ta-field-label">{editCustomerMode === 'existing' ? 'Phone number' : 'Phone'}</label><input className="ta-input" value={editForm.data.customer_phone} onChange={(e) => editForm.setData('customer_phone', e.target.value)} required />{fieldError(editForm, 'customer_phone')}</div>
                        <div><label className="ta-field-label">Email</label><input className="ta-input" type="email" value={editForm.data.customer_email} onChange={(e) => editForm.setData('customer_email', e.target.value)} />{fieldError(editForm, 'customer_email')}</div>
                        <div><label className="ta-field-label">Service</label><select className="ta-input" value={editForm.data.service_id} onChange={(e) => handleEditServiceChange(e.target.value)} required><option value="">Service</option>{services.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>{fieldError(editForm, 'service_id')}</div>
                        <div><label className="ta-field-label">Staff Profile</label><select className="ta-input" value={editForm.data.staff_profile_id} onChange={(e) => editForm.setData('staff_profile_id', e.target.value)}><option value="">Unassigned Staff</option>{staffProfiles.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>{fieldError(editForm, 'staff_profile_id')}</div>
                        <div><label className="ta-field-label">Status</label><select className="ta-input" value={editForm.data.status} onChange={(e) => editForm.setData('status', e.target.value)}><option value="pending">pending</option><option value="confirmed">confirmed</option><option value="in_progress">in_progress</option><option value="completed">completed</option><option value="cancelled">cancelled</option><option value="no_show">no_show</option></select>{fieldError(editForm, 'status')}</div>
                        <div>
                            <label className="ta-field-label">Scheduled Start</label>
                            <p className="mb-1 text-xs text-slate-500">Same-day visit: keep start and end within {bookingRules?.opening_time || '09:00'}–{bookingRules?.closing_time || '22:00'}; the visit must end by closing. For today, the earliest start is the next available time after now (including minimum advance).</p>
                            <input
                                key={`edit-start-${editStartMountKey}`}
                                ref={editStartRef}
                                className="ta-input"
                                type="datetime-local"
                                defaultValue={editStartDefault}
                                onInput={(e) => syncEditStartFromInput(e.currentTarget.value)}
                                min={editSalonBounds.min}
                                max={`${editStartYmd}T23:59`}
                                required
                            />
                            {fieldError(editForm, 'scheduled_start')}
                        </div>
                        <div>
                            <label className="ta-field-label">Scheduled End</label>
                            <input className="ta-input" type="datetime-local" value={editForm.data.scheduled_end} onInput={(e) => handleEditEndChange(e.currentTarget.value)} min={editEndSalonBounds.min} max={editEndSalonBounds.max} />
                            {fieldError(editForm, 'scheduled_end')}
                        </div>
                        <div className="md:col-span-2"><label className="ta-field-label">Notes</label><input className="ta-input" value={editForm.data.notes} onChange={(e) => editForm.setData('notes', e.target.value)} placeholder="Notes" />{fieldError(editForm, 'notes')}</div>
                        <div className="md:col-span-2 flex justify-end gap-2 pt-2">
                            <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setEditingId(null)}>Close</button>
                            <button className="ta-btn-primary" disabled={editForm.processing}>Save</button>
                        </div>
                    </form>
                </div>
            </Modal>

            <ConfirmActionModal
                show={Boolean(deleteAppointmentId)}
                title="Delete this appointment permanently?"
                message="This removes the appointment from the database. This cannot be undone."
                confirmText="Delete permanently"
                onClose={() => !deleteAppointmentBusy && setDeleteAppointmentId(null)}
                processing={deleteAppointmentBusy}
                onConfirm={() => {
                    if (!deleteAppointmentId) return;
                    setDeleteAppointmentBusy(true);
                    router.delete(route('appointments.destroy', deleteAppointmentId), {
                        onFinish: () => {
                            setDeleteAppointmentBusy(false);
                            setDeleteAppointmentId(null);
                        },
                    });
                }}
            />
        </AuthenticatedLayout>
    );
}
