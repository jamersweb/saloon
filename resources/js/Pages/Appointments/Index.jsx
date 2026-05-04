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
const formatHourLabel = (hour) => {
    const suffix = hour >= 12 ? 'PM' : 'AM';
    const normalized = hour % 12 || 12;
    return `${normalized}:00 ${suffix}`;
};
const formatMoney = (value, currencyCode = 'AED') =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency: currencyCode, minimumFractionDigits: 2 }).format(Number(value || 0));
const appointmentCategoryCardPalettes = [
    { backgroundColor: '#fff1f2', borderColor: '#fecdd3', color: '#881337' },
    { backgroundColor: '#fff7ed', borderColor: '#fed7aa', color: '#9a3412' },
    { backgroundColor: '#fffbeb', borderColor: '#fde68a', color: '#92400e' },
    { backgroundColor: '#f7fee7', borderColor: '#bef264', color: '#3f6212' },
    { backgroundColor: '#ecfdf5', borderColor: '#a7f3d0', color: '#065f46' },
    { backgroundColor: '#ecfeff', borderColor: '#a5f3fc', color: '#155e75' },
    { backgroundColor: '#f0f9ff', borderColor: '#bae6fd', color: '#0c4a6e' },
    { backgroundColor: '#eff6ff', borderColor: '#bfdbfe', color: '#1d4ed8' },
    { backgroundColor: '#f5f3ff', borderColor: '#ddd6fe', color: '#5b21b6' },
    { backgroundColor: '#fdf4ff', borderColor: '#f5d0fe', color: '#a21caf' },
];
const stringToPaletteIndex = (value) => {
    const text = String(value || '').trim().toLowerCase();
    if (!text) return 0;

    let hash = 0;
    for (let index = 0; index < text.length; index += 1) {
        hash = ((hash << 5) - hash) + text.charCodeAt(index);
        hash |= 0;
    }

    return Math.abs(hash) % appointmentCategoryCardPalettes.length;
};
const getAppointmentCardStyle = (category, isPaid) => {
    if (isPaid) {
        return { backgroundColor: '#ffffff', borderColor: '#ffffff', color: '#0f172a' };
    }

    return appointmentCategoryCardPalettes[stringToPaletteIndex(category)];
};
const layoutOverlappingAppointments = (cards) => {
    const sortedCards = [...cards].sort((a, b) => {
        if (a.startMinutes !== b.startMinutes) return a.startMinutes - b.startMinutes;
        if (a.endMinutes !== b.endMinutes) return a.endMinutes - b.endMinutes;
        return String(a.id).localeCompare(String(b.id));
    });
    const active = [];
    const groups = [];

    sortedCards.forEach((card) => {
        for (let index = active.length - 1; index >= 0; index -= 1) {
            if (active[index].endMinutes <= card.startMinutes) {
                active.splice(index, 1);
            }
        }

        const usedLanes = new Set(active.map((item) => item.lane));
        let lane = 0;
        while (usedLanes.has(lane)) lane += 1;

        const overlappingGroupIds = new Set(active.map((item) => item.groupId));
        const groupId = overlappingGroupIds.size > 0 ? Math.min(...overlappingGroupIds) : groups.length;

        if (!groups[groupId]) groups[groupId] = [];

        const nextCard = { ...card, lane, groupId };
        groups[groupId].push(nextCard);
        active.push(nextCard);
    });

    return groups.flatMap((groupCards) => {
        const laneCount = Math.max(1, ...groupCards.map((card) => card.lane + 1));
        const width = 100 / laneCount;

        return groupCards.map((card) => ({
            ...card,
            laneCount,
            width,
            left: card.lane * width,
            zIndex: 20 + card.lane,
        }));
    });
};
const serviceMatchesSearch = (service, query) => {
    const needle = String(query || '').trim().toLowerCase();
    if (!needle) return true;

    const haystack = `${service?.name || ''} ${service?.category || ''}`.toLowerCase();
    return haystack.includes(needle);
};
const normalizeServiceQuantities = (serviceIds, serviceQuantities) => {
    const next = {};
    (serviceIds || []).forEach((serviceId) => {
        const key = String(serviceId);
        next[key] = Math.max(1, Number(serviceQuantities?.[key] || 1));
    });

    return next;
};
const estimateSelectedServicesTotal = (serviceIds, serviceQuantities, services, coveredServiceIds = []) => {
    const covered = new Set((coveredServiceIds || []).map((id) => String(id)));

    return (serviceIds || []).reduce((sum, serviceId) => {
        const service = services.find((item) => String(item.id) === String(serviceId));
        if (!service) return sum;
        if (covered.has(String(serviceId))) return sum;
        const quantity = Math.max(1, Number(serviceQuantities?.[String(serviceId)] || 1));
        return sum + (Number(service.price || 0) * quantity);
    }, 0);
};
const hasAssignmentsForAllServices = (serviceIds, staffAssignments) => {
    const selected = (serviceIds || []).map((id) => String(id));
    if (selected.length === 0) return false;

    return selected.every((serviceId) => String(staffAssignments?.[serviceId] || '').trim() !== '');
};
const isBlockingAppointmentStatus = (status) => !['completed', 'cancelled', 'no_show'].includes(String(status || '').toLowerCase());
const intervalsOverlap = (startA, endA, startB, endB) => startA < endB && endA > startB;
const localYmd = (d) => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
const sameLocalDate = (a, ymd) => {
    if (!a || !ymd) return false;
    const date = new Date(a);
    if (Number.isNaN(date.getTime())) return false;
    return localYmd(date) === ymd;
};

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

const adminStartBoundsForYmd = (dateYmd, bookingRules) => {
    const open = salonClockBoundary(bookingRules, 'opening_time', '09:00');
    const today = new Date();
    const todayYmd = localYmd(today);
    const maxAdvanceDays = Math.max(1, Number(bookingRules?.max_advance_days || 60));
    const horizon = new Date(today);
    horizon.setDate(horizon.getDate() + maxAdvanceDays);
    horizon.setHours(23, 59, 0, 0);

    let min = `${dateYmd}T${pad2(open.h)}:${pad2(open.m)}`;
    if (dateYmd === todayYmd) {
        const walkInNow = new Date();
        walkInNow.setSeconds(0, 0);
        const nowLocal = toDateTimeLocal(walkInNow);
        if (dateTimeLocalCompare(nowLocal, min) > 0) min = nowLocal;
    }

    return {
        min,
        max: toDateTimeLocal(horizon),
    };
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
    const { min, max } = adminStartBoundsForYmd(d, bookingRules);
    let v = value;
    if (dateTimeLocalCompare(v, min) < 0) v = min;
    if (dateTimeLocalCompare(v, max) > 0) v = max;

    return v;
};

export default function AppointmentsIndex({ appointments, services, customers = [], staffProfiles, inventoryItems, statusFilter, bookingRules, defaultStart, gift_cards_for_checkout = [] }) {
    const { app_currency_code: currencyCode = 'AED' } = usePage().props;
    const { flash, auth } = usePage().props;
    const serviceCategoryMap = useMemo(
        () => Object.fromEntries((services || []).map((service) => [String(service.id), service.category || 'Uncategorized'])),
        [services],
    );
    const roleName = String(auth?.user?.role?.name || '').toLowerCase();
    const canManageFinance = Boolean(auth?.permissions?.can_manage_finance);
    const canCollectPayments = Boolean(auth?.permissions?.can_collect_payments);
    const canCheckout = canManageFinance || canCollectPayments;
    const canFinishAndPayNow = roleName === 'manager' || roleName === 'reception';
    const [editingId, setEditingId] = useState(null);
    const [startServiceId, setStartServiceId] = useState(null);
    const [completeServiceId, setCompleteServiceId] = useState(null);
    const [createEndManuallySet, setCreateEndManuallySet] = useState(false);
    const [createCustomerMode, setCreateCustomerMode] = useState('new');
    const [createSelectedCustomerId, setCreateSelectedCustomerId] = useState('');
    const [createSelectedPackageId, setCreateSelectedPackageId] = useState('');
    const [editCustomerMode, setEditCustomerMode] = useState('new');
    const [editSelectedCustomerId, setEditSelectedCustomerId] = useState('');
    const [editSelectedPackageId, setEditSelectedPackageId] = useState('');
    const [editEndManuallySet, setEditEndManuallySet] = useState(true);
    const [deleteAppointmentId, setDeleteAppointmentId] = useState(null);
    const [deleteAppointmentBusy, setDeleteAppointmentBusy] = useState(false);
    const importFileRef = useRef(null);
    const [checkoutFlow, setCheckoutFlow] = useState('draft');
    const [createServiceSearch, setCreateServiceSearch] = useState('');
    const [editServiceSearch, setEditServiceSearch] = useState('');
    const [showBoardView, setShowBoardView] = useState(false);
    const [boardDate, setBoardDate] = useState(() => localYmd(new Date()));
    const [boardStaffFilter, setBoardStaffFilter] = useState('all');
    const [createStaffAvailability, setCreateStaffAvailability] = useState({});
    const [editStaffAvailability, setEditStaffAvailability] = useState({});
    const slotIntervalMinutes = Math.max(1, Number(bookingRules?.slot_interval_minutes || 30));

    const createStartRef = useRef(null);
    const editStartRef = useRef(null);
    const [createStartMount, setCreateStartMount] = useState(0);
    const [createStartYmd, setCreateStartYmd] = useState(() => ((defaultStart || '').split('T')[0] || localYmd(new Date())));
    const [editStartYmd, setEditStartYmd] = useState(() => localYmd(new Date()));
    const [editStartMountKey, setEditStartMountKey] = useState(0);

    const createForm = useForm({ customer_name: '', customer_phone: '', customer_email: '', service_id: '', service_ids: [], service_quantities: {}, customer_package_id: '', package_service_ids: [], staff_profile_id: '', staff_assignments: {}, scheduled_start: defaultStart || '', scheduled_end: '', status: 'confirmed', notes: '' });
    const editForm = useForm({ customer_name: '', customer_phone: '', customer_email: '', service_id: '', service_ids: [], service_quantities: {}, customer_package_id: '', package_service_ids: [], staff_profile_id: '', staff_assignments: {}, scheduled_start: '', scheduled_end: '', status: 'confirmed', notes: '' });
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
        products: [],
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

    const calculateSuggestedEnd = (startValue, serviceIds) => {
        if (!startValue || !Array.isArray(serviceIds) || serviceIds.length === 0) return '';

        const startDate = new Date(startValue);
        if (Number.isNaN(startDate.getTime())) return '';

        const totalMinutes = serviceIds.reduce((sum, id) => {
            const service = services.find((s) => String(s.id) === String(id));
            if (!service) return sum;
            return sum + Number(service.duration_minutes || 0) + Number(service.buffer_minutes || 0);
        }, 0);
        if (totalMinutes <= 0) return '';

        startDate.setMinutes(startDate.getMinutes() + totalMinutes);

        let endStr = toDateTimeLocal(startDate);
        endStr = clampDateTimeLocalToSalon(endStr, bookingRules, slotIntervalMinutes);
        if (startValue && dateTimeLocalCompare(endStr, startValue) < 0) {
            endStr = clampDateTimeLocalToSalon(startValue, bookingRules, slotIntervalMinutes);
        }

        return endStr;
    };

    const handleCreateServiceChange = (nextIds) => {
        createForm.clearErrors('service_id', 'service_ids', 'staff_profile_id', 'staff_assignments');
        const startVal = createStartRef.current?.value || createForm.data.scheduled_start || '';
        createForm.setData((prev) => ({
            ...prev,
            ...(() => {
                const nextAssignments = Object.fromEntries(
                Object.entries(prev.staff_assignments || {}).filter(([serviceId]) => nextIds.includes(String(serviceId))),
                );
                return {
                    staff_assignments: nextAssignments,
                    staff_profile_id: hasAssignmentsForAllServices(nextIds, nextAssignments) ? '' : prev.staff_profile_id,
                };
            })(),
            service_quantities: normalizeServiceQuantities(nextIds, prev.service_quantities),
            package_service_ids: (prev.package_service_ids || []).filter((serviceId) => nextIds.includes(String(serviceId))),
            service_ids: nextIds,
            service_id: nextIds[0] || '',
            scheduled_end: !createEndManuallySet || !prev.scheduled_end
                ? calculateSuggestedEnd(startVal, nextIds)
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

    const handleEditServiceChange = (nextIds) => {
        editForm.clearErrors('service_id', 'service_ids', 'staff_profile_id', 'staff_assignments');
        const startVal = editStartRef.current?.value || editForm.data.scheduled_start || '';
        editForm.setData((prev) => ({
            ...prev,
            ...(() => {
                const nextAssignments = Object.fromEntries(
                Object.entries(prev.staff_assignments || {}).filter(([serviceId]) => nextIds.includes(String(serviceId))),
                );
                return {
                    staff_assignments: nextAssignments,
                    staff_profile_id: hasAssignmentsForAllServices(nextIds, nextAssignments) ? '' : prev.staff_profile_id,
                };
            })(),
            service_quantities: normalizeServiceQuantities(nextIds, prev.service_quantities),
            package_service_ids: (prev.package_service_ids || []).filter((serviceId) => nextIds.includes(String(serviceId))),
            service_ids: nextIds,
            service_id: nextIds[0] || '',
            scheduled_end: !editEndManuallySet || !prev.scheduled_end
                ? calculateSuggestedEnd(startVal, nextIds)
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
                ? calculateSuggestedEnd(clamped, prev.service_ids)
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
                ? calculateSuggestedEnd(clamped, prev.service_ids)
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
        createForm.setData('customer_package_id', '');
        createForm.setData('package_service_ids', []);
        setCreateSelectedPackageId('');
    };

    const applyCustomerToEditForm = (customer) => {
        editForm.setData('customer_name', customer?.name ?? '');
        editForm.setData('customer_phone', customer?.phone ?? '');
        editForm.setData('customer_email', customer?.email ?? '');
        editForm.setData('customer_package_id', '');
        editForm.setData('package_service_ids', []);
        setEditSelectedPackageId('');
    };

    const startEdit = (appt) => {
        const startStr = toDateTimeLocal(appt.scheduled_start);
        setEditStartYmd(startStr.split('T')[0] || localYmd(new Date()));
        setEditingId(appt.id);
        setEditCustomerMode(appt.customer_id ? 'existing' : 'new');
        setEditSelectedCustomerId(appt.customer_id ? String(appt.customer_id) : '');
        setEditSelectedPackageId(appt.customer_package_id ? String(appt.customer_package_id) : '');
        setEditServiceSearch('');
        setEditEndManuallySet(Boolean(appt.scheduled_end));
        setEditStartMountKey((k) => k + 1);
        editForm.setData({
            customer_name: appt.customer_name || '',
            customer_phone: appt.customer_phone || '',
            customer_email: appt.customer_email || '',
            service_id: appt.service_id || '',
            service_ids: appt.service_id ? [String(appt.service_id)] : [],
            service_quantities: appt.service_id ? { [String(appt.service_id)]: String(appt.service_quantity || 1) } : {},
            customer_package_id: appt.customer_package_id ? String(appt.customer_package_id) : '',
            package_service_ids: appt.customer_package_id && appt.service_id ? [String(appt.service_id)] : [],
            staff_profile_id: appt.staff_profile_id || '',
            staff_assignments: appt.service_id && appt.staff_profile_id
                ? { [String(appt.service_id)]: String(appt.staff_profile_id) }
                : {},
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
                : [],
        });
        completeForm.clearErrors();
    };

    const changeFilter = (value) => router.get(route('appointments.index'), { status: value || undefined }, { preserveState: true, replace: true });
    const transition = (id, nextStatus) => router.patch(route('appointments.transition', id), { status: nextStatus });
    const handleAppointmentsImport = (event) => {
        const file = event.target.files?.[0];
        if (!file) return;
        router.post(route('data-transfer.import', { entity: 'appointments' }), { csv_file: file }, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                if (importFileRef.current) importFileRef.current.value = '';
            },
        });
    };

    const createSelectedServices = createForm.data.service_ids || [];
    const editSelectedServices = editForm.data.service_ids || [];
    const createHasMultipleServices = createSelectedServices.length > 1;
    const editHasMultipleServices = editSelectedServices.length > 1;
    const createSelectedCustomer = customers.find((c) => String(c.id) === String(createSelectedCustomerId)) || null;
    const editSelectedCustomer = customers.find((c) => String(c.id) === String(editSelectedCustomerId)) || null;
    const createAvailablePackages = createSelectedCustomer?.active_packages || [];
    const editAvailablePackages = editSelectedCustomer?.active_packages || [];
    const createSelectedPackage = createAvailablePackages.find((pkg) => String(pkg.id) === String(createSelectedPackageId)) || null;
    const editSelectedPackage = editAvailablePackages.find((pkg) => String(pkg.id) === String(editSelectedPackageId)) || null;
    const createPackageCoverageMap = Object.fromEntries((createSelectedPackage?.services || []).map((service) => [String(service.id), service]));
    const editPackageCoverageMap = Object.fromEntries((editSelectedPackage?.services || []).map((service) => [String(service.id), service]));
    const createCoveredServiceIds = createForm.data.package_service_ids || [];
    const editCoveredServiceIds = editForm.data.package_service_ids || [];
    const createAvailableServices = services.filter((s) => !createSelectedServices.includes(String(s.id)));
    const editAvailableServices = services.filter((s) => !editSelectedServices.includes(String(s.id)));
    const createFilteredServices = createAvailableServices.filter((s) => serviceMatchesSearch(s, createServiceSearch));
    const editFilteredServices = editAvailableServices.filter((s) => serviceMatchesSearch(s, editServiceSearch));
    const createEstimatedServicesTotal = estimateSelectedServicesTotal(createSelectedServices, createForm.data.service_quantities, services, createCoveredServiceIds);
    const editEstimatedServicesTotal = estimateSelectedServicesTotal(editSelectedServices, editForm.data.service_quantities, services, editCoveredServiceIds);
    const createCustomerHasGiftCards = (createSelectedCustomer?.active_gift_cards || []).length > 0
        && Number(createSelectedCustomer?.gift_card_balance || 0) > 0;
    const editCustomerHasGiftCards = (editSelectedCustomer?.active_gift_cards || []).length > 0
        && Number(editSelectedCustomer?.gift_card_balance || 0) > 0;
    const createCustomerGiftBalance = Number(createSelectedCustomer?.gift_card_balance || 0);
    const editCustomerGiftBalance = Number(editSelectedCustomer?.gift_card_balance || 0);
    const createGiftCardShortfall = Math.max(0, createEstimatedServicesTotal - createCustomerGiftBalance);
    const editGiftCardShortfall = Math.max(0, editEstimatedServicesTotal - editCustomerGiftBalance);
    const createStartForAvailability = createStartRef.current?.value || createForm.data.scheduled_start || '';
    const createEndForAvailability = createForm.data.scheduled_end || calculateSuggestedEnd(createStartForAvailability, createSelectedServices);
    const editStartForAvailability = editStartRef.current?.value || editForm.data.scheduled_start || '';
    const editEndForAvailability = editForm.data.scheduled_end || calculateSuggestedEnd(editStartForAvailability, editSelectedServices);

    const buildStaffAvailabilityMap = (startValue, endValue, ignoreAppointmentId = null) => {
        const start = new Date(startValue);
        const end = new Date(endValue || startValue);
        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return {};
        const normalizedEnd = end > start ? end : new Date(start.getTime() + (30 * 60 * 1000));

        return Object.fromEntries(staffProfiles.map((staff) => {
            const conflictingAppointment = appointments.find((appt) => {
                if (ignoreAppointmentId && String(appt.id) === String(ignoreAppointmentId)) return false;
                if (String(appt.staff_profile_id || '') !== String(staff.id)) return false;
                if (!isBlockingAppointmentStatus(appt.status)) return false;

                const apptStart = new Date(appt.scheduled_start);
                const apptEnd = new Date(appt.scheduled_end || appt.scheduled_start);
                if (Number.isNaN(apptStart.getTime()) || Number.isNaN(apptEnd.getTime())) return false;
                const normalizedApptEnd = apptEnd > apptStart ? apptEnd : new Date(apptStart.getTime() + (30 * 60 * 1000));

                return intervalsOverlap(start, normalizedEnd, apptStart, normalizedApptEnd);
            });

            return [String(staff.id), {
                busy: Boolean(conflictingAppointment),
                label: conflictingAppointment
                    ? `Busy${conflictingAppointment.customer_name ? ` - ${conflictingAppointment.customer_name}` : ''}`
                    : 'Available',
            }];
        }));
    };

    const createFallbackStaffAvailability = buildStaffAvailabilityMap(createStartForAvailability, createEndForAvailability);
    const editFallbackStaffAvailability = buildStaffAvailabilityMap(editStartForAvailability, editEndForAvailability, editingId);

    useEffect(() => {
        const startValue = createStartRef.current?.value || createForm.data.scheduled_start || '';
        const endValue = createForm.data.scheduled_end || calculateSuggestedEnd(startValue, createSelectedServices);
        if (!startValue) {
            setCreateStaffAvailability({});
            return;
        }

        let cancelled = false;
        const params = new URLSearchParams({ scheduled_start: startValue });
        if (endValue) params.set('scheduled_end', endValue);

        fetch(`${route('appointments.staff-availability')}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => response.ok ? response.json() : Promise.reject(new Error('Availability request failed')))
            .then((payload) => {
                if (cancelled) return;
                const next = Object.fromEntries((payload?.staff || []).map((staff) => [String(staff.id), {
                    busy: !staff.available,
                    label: staff.available ? 'Available' : (staff.reason || 'Busy'),
                }]));
                setCreateStaffAvailability(next);
            })
            .catch(() => {
                if (!cancelled) setCreateStaffAvailability({});
            });

        return () => {
            cancelled = true;
        };
    }, [createForm.data.scheduled_start, createForm.data.scheduled_end, createSelectedServices, createStartMount]);

    useEffect(() => {
        const startValue = editStartRef.current?.value || editForm.data.scheduled_start || '';
        const endValue = editForm.data.scheduled_end || calculateSuggestedEnd(startValue, editSelectedServices);
        if (!editingId || !startValue) {
            setEditStaffAvailability({});
            return;
        }

        let cancelled = false;
        const params = new URLSearchParams({ scheduled_start: startValue, ignore_appointment_id: String(editingId) });
        if (endValue) params.set('scheduled_end', endValue);

        fetch(`${route('appointments.staff-availability')}?${params.toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => response.ok ? response.json() : Promise.reject(new Error('Availability request failed')))
            .then((payload) => {
                if (cancelled) return;
                const next = Object.fromEntries((payload?.staff || []).map((staff) => [String(staff.id), {
                    busy: !staff.available,
                    label: staff.available ? 'Available' : (staff.reason || 'Busy'),
                }]));
                setEditStaffAvailability(next);
            })
            .catch(() => {
                if (!cancelled) setEditStaffAvailability({});
            });

        return () => {
            cancelled = true;
        };
    }, [editingId, editForm.data.scheduled_start, editForm.data.scheduled_end, editSelectedServices, editStartMountKey]);

    const updateProductRow = (index, field, value) => {
        completeForm.setData('products', completeForm.data.products.map((row, rowIndex) => rowIndex === index ? { ...row, [field]: value } : row));
    };

    const addProductRow = () => completeForm.setData('products', [...completeForm.data.products, { inventory_item_id: '', quantity: 1, notes: '' }]);
    const removeProductRow = (index) => completeForm.setData('products', completeForm.data.products.filter((_, rowIndex) => rowIndex !== index));

    const createSalonBounds = adminStartBoundsForYmd(createStartYmd, bookingRules);
    const editSalonBounds = adminStartBoundsForYmd(editStartYmd, bookingRules);
    const editEndYmd = (editForm.data.scheduled_end || editForm.data.scheduled_start || '').split('T')[0] || editStartYmd;
    const editEndSalonBounds = salonSelectableBoundsForYmd(editEndYmd, bookingRules, slotIntervalMinutes);
    const editingAppt = appointments.find((a) => String(a.id) === String(editingId));
    const editStartDefault = editingAppt ? toDateTimeLocal(editingAppt.scheduled_start) : (editForm.data.scheduled_start || '');
    const completingAppt = appointments.find((a) => String(a.id) === String(completeServiceId));
    const completingService = services.find((s) => String(s.id) === String(completingAppt?.service_id));
    const completingCustomer = customers.find((customer) => String(customer.id) === String(completingAppt?.customer_id));
    const completingServiceQuantity = Math.max(1, Number(completingAppt?.service_quantity || 1));
    const completingServiceAmount = completingAppt?.customer_package_id ? 0 : (Number(completingService?.price || 0) * completingServiceQuantity);
    const selectedProductLines = (completeForm.data.products || [])
        .map((row) => {
            const item = inventoryItems.find((inv) => String(inv.id) === String(row.inventory_item_id));
            const quantity = Math.max(1, Number(row.quantity || 1));
            const unitPrice = Number(item?.selling_price || 0);
            const lineTotal = quantity * unitPrice;

            return {
                inventory_item_id: row.inventory_item_id,
                label: item ? `${item.name}${item.sku ? ` (${item.sku})` : ''}` : 'Unknown item',
                quantity,
                unitPrice,
                lineTotal,
            };
        })
        .filter((line) => String(line.inventory_item_id || '') !== '');
    const selectedProductsAmount = selectedProductLines.reduce((sum, line) => sum + line.lineTotal, 0);
    const previewTotalAmount = completingServiceAmount + selectedProductsAmount;
    const completingCustomerGiftBalance = Number(completingCustomer?.gift_card_balance || 0);
    const completingGiftCardShortfall = Math.max(0, previewTotalAmount - completingCustomerGiftBalance);
    const boardOpen = salonClockBoundary(bookingRules, 'opening_time', '09:00');
    const boardClose = salonClockBoundary(bookingRules, 'closing_time', '22:00');
    const boardStartMinutes = boardOpen.h * 60 + boardOpen.m;
    const boardEndMinutes = Math.max(boardStartMinutes + 60, boardClose.h * 60 + boardClose.m);
    const boardTotalMinutes = Math.max(60, boardEndMinutes - boardStartMinutes);
    const boardHourMarks = Array.from({ length: Math.ceil(boardTotalMinutes / 60) + 1 }, (_, idx) => boardStartMinutes + (idx * 60));
    const boardStaffList = boardStaffFilter === 'all'
        ? staffProfiles
        : staffProfiles.filter((staff) => String(staff.id) === String(boardStaffFilter));
    const boardAppointments = appointments.filter((appt) => sameLocalDate(appt.scheduled_start, boardDate));
    const boardCardsByStaff = boardStaffList.map((staff) => {
        const cards = layoutOverlappingAppointments(boardAppointments
            .filter((appt) => String(appt.staff_profile_id || '') === String(staff.id))
            .map((appt) => {
                const start = new Date(appt.scheduled_start);
                const end = new Date(appt.scheduled_end || appt.scheduled_start);
                const startMinutes = start.getHours() * 60 + start.getMinutes();
                const endMinutes = Math.max(startMinutes + 30, end.getHours() * 60 + end.getMinutes());
                const top = Math.max(0, ((startMinutes - boardStartMinutes) / boardTotalMinutes) * 100);
                const height = Math.max(7, (((endMinutes) - startMinutes) / boardTotalMinutes) * 100);
                const isPaid = appt.status === 'completed' && !appt.awaiting_checkout;
                const category = serviceCategoryMap[String(appt.service_id)] || 'Uncategorized';

                return {
                    ...appt,
                    cardStyle: getAppointmentCardStyle(category, isPaid),
                    category,
                    endMinutes,
                    isPaid,
                    startMinutes,
                    top,
                    height,
                    timeLabel: `${start.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })} - ${end.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}`,
                };
            }));

        return { staff, cards };
    });
    const appointmentQueueRows = Array.from(appointments.reduce((map, appt) => {
        const groupKey = String(appt.visit_id || appt.id);
        const existing = map.get(groupKey);
        if (!existing) {
            map.set(groupKey, { key: groupKey, appointments: [appt] });
            return map;
        }

        existing.appointments.push(appt);
        return map;
    }, new Map()).values()).map((group) => {
        const rows = [...group.appointments].sort((a, b) => new Date(a.scheduled_start) - new Date(b.scheduled_start));
        const actionAppointment = rows.find((row) => row.status === 'in_progress')
            || rows.find((row) => row.status === 'confirmed')
            || rows[0];
        const staffNames = [...new Set(rows.map((row) => row.staff_name || 'Unassigned'))];
        const photos = rows.flatMap((row) => row.photos || []);
        const productUsages = rows.flatMap((row) => row.product_usages || []);
        const serviceSummary = rows.map((row) => `${row.service_name}${Number(row.service_quantity || 1) > 1 ? ` x${row.service_quantity}` : ''}`);

        return {
            ...actionAppointment,
            key: group.key,
            scheduled_start: rows[0]?.scheduled_start,
            service_name: serviceSummary.join(', '),
            staff_name: staffNames.join(', '),
            photos,
            product_usages: productUsages,
            grouped_services: rows.map((row) => ({
                id: row.id,
                name: row.service_name,
                quantity: row.service_quantity || 1,
                status: row.status,
                staff_name: row.staff_name || 'Unassigned',
            })),
            awaiting_checkout: rows.some((row) => row.awaiting_checkout),
            checkout_invoice_id: rows.find((row) => row.checkout_invoice_id)?.checkout_invoice_id || null,
        };
    });

    return (
        <AuthenticatedLayout
            header="Appointments"
            headerActions={(
                <button
                    type="button"
                    onClick={() => setShowBoardView(true)}
                    className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
                >
                    View Appointment
                </button>
            )}
        >
            <Head title="Appointments" />
            <div className="space-y-6">
                <section className="ta-card p-3">
                    <div className="flex items-center gap-2">
                        <input ref={importFileRef} type="file" accept=".csv,text/csv" className="hidden" onChange={handleAppointmentsImport} />
                        <button type="button" className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700" onClick={() => importFileRef.current?.click()}>Import CSV</button>
                        <button type="button" className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700" onClick={() => { window.location.href = route('data-transfer.template', { entity: 'appointments' }); }}>Template CSV</button>
                        <button type="button" className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700" onClick={() => { window.location.href = route('data-transfer.export', { entity: 'appointments' }); }}>Export CSV</button>
                    </div>
                </section>
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
                                    createForm.setData('service_ids', []);
                                    createForm.setData('service_id', '');
                                    setCreateServiceSearch('');
                                    setCreateStartYmd((defaultStart || '').split('T')[0] || localYmd(new Date()));
                                    setCreateStartMount((m) => m + 1);
                                    setCreateEndManuallySet(false);
                                    setCreateCustomerMode('new');
                                    setCreateSelectedCustomerId('');
                                    setCreateSelectedPackageId('');
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
                            <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                                <input type="radio" name="create_customer_mode" className="text-indigo-600" checked={createCustomerMode === 'package'} onChange={() => { setCreateCustomerMode('package'); setCreateSelectedCustomerId(''); applyCustomerToCreateForm(null); }} />
                                Package customer
                            </label>
                        </div>
                        {createCustomerMode === 'existing' || createCustomerMode === 'package' ? (
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
                                {createCustomerHasGiftCards ? (
                                    <p className="mt-2 text-sm font-semibold text-emerald-800">Gift card remaining balance: {formatMoney(createCustomerGiftBalance, currencyCode)}</p>
                                ) : null}
                            </div>
                        ) : null}
                        {createCustomerMode === 'package' && createSelectedCustomerId ? (
                            <div className="md:col-span-4 rounded-lg border border-emerald-200 bg-emerald-50/70 p-3">
                                <label className="ta-field-label">Select package</label>
                                <select
                                    className="ta-input"
                                    value={createSelectedPackageId}
                                    onChange={(e) => {
                                        const id = e.target.value;
                                        setCreateSelectedPackageId(id);
                                        createForm.setData('customer_package_id', id);
                                        createForm.setData('package_service_ids', []);
                                    }}
                                >
                                    <option value="">Choose customer package…</option>
                                    {createAvailablePackages.map((pkg) => (
                                        <option key={pkg.id} value={pkg.id}>
                                            {pkg.package_name}{pkg.expires_at ? ` — expires ${new Date(pkg.expires_at).toLocaleDateString()}` : ''}
                                        </option>
                                    ))}
                                </select>
                                {createSelectedPackage ? (
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {createSelectedPackage.services.map((service) => (
                                            <span key={service.id} className={`rounded-full px-2 py-1 text-xs ${service.remaining_sessions > 0 ? 'border border-emerald-200 bg-white text-emerald-800' : 'border border-slate-200 bg-slate-100 text-slate-500'}`}>
                                                {service.name} {service.remaining_sessions}/{service.included_sessions} left
                                            </span>
                                        ))}
                                    </div>
                                ) : null}
                                {fieldError(createForm, 'customer_package_id')}
                                {fieldError(createForm, 'package_service_ids')}
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
                        <div>
                            <label className="ta-field-label">Services</label>
                            <input className="ta-input" value={createServiceSearch} onChange={(e) => setCreateServiceSearch(e.target.value)} placeholder="Search by service or category" />
                            <div className="mt-2 max-h-36 overflow-auto rounded-lg border border-slate-200 bg-white">
                                {createFilteredServices.map((s) => (
                                    <button
                                        key={s.id}
                                        type="button"
                                        className="block w-full border-b border-slate-100 px-3 py-2 text-left text-xs text-slate-700 hover:bg-slate-50"
                                        onClick={() => handleCreateServiceChange([...createSelectedServices, String(s.id)])}
                                    >
                                        <div className="font-medium text-slate-700">{s.name}</div>
                                        <div className="mt-0.5 text-[11px] text-slate-500">{s.category || 'Uncategorized'} • {s.duration_minutes}m • <span className="font-bold text-slate-700">{formatMoney(s.price, currencyCode)}</span></div>
                                    </button>
                                ))}
                                {createFilteredServices.length === 0 ? <div className="px-3 py-2 text-xs text-slate-500">No more services found.</div> : null}
                            </div>
                            <div className="mt-2 flex flex-wrap gap-2">
                                {createSelectedServices.map((id) => {
                                    const s = services.find((x) => String(x.id) === String(id));
                                    if (!s) return null;
                                    const packageCoverage = createPackageCoverageMap[String(id)];
                                    const isCovered = (createForm.data.package_service_ids || []).includes(String(id));
                                    return (
                                        <div key={id} className="flex items-center gap-2">
                                            <button type="button" className="rounded-full border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs text-indigo-700" onClick={() => handleCreateServiceChange(createSelectedServices.filter((x) => x !== id))}>
                                                {s.name}{Number(createForm.data.service_quantities?.[String(id)] || 1) > 1 ? ` x${createForm.data.service_quantities?.[String(id)]}` : ''} ✕
                                            </button>
                                            {createCustomerMode === 'package' && createSelectedPackage && packageCoverage ? (
                                                <label className="inline-flex items-center gap-1 text-xs text-emerald-700">
                                                    <input
                                                        type="checkbox"
                                                        checked={isCovered}
                                                        disabled={packageCoverage.remaining_sessions < 1 && !isCovered}
                                                        onChange={(e) => createForm.setData('package_service_ids', e.target.checked
                                                            ? [...new Set([...(createForm.data.package_service_ids || []), String(id)])]
                                                            : (createForm.data.package_service_ids || []).filter((serviceId) => String(serviceId) !== String(id)))}
                                                    />
                                                    Package session ({packageCoverage.remaining_sessions} left)
                                                </label>
                                            ) : null}
                                        </div>
                                    );
                                })}
                            </div>
                            {fieldError(createForm, 'service_id')}
                            {fieldError(createForm, 'service_ids')}
                        </div>
                        {createSelectedCustomer && createCustomerHasGiftCards ? (
                            <div className="md:col-span-2 rounded-lg border border-slate-200 bg-slate-50/70 p-3">
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Gift Card Check</p>
                                <div className="mt-2 flex flex-wrap gap-4 text-sm text-slate-700">
                                    <span>Gift card balance: <strong>{formatMoney(createCustomerGiftBalance, currencyCode)}</strong></span>
                                    <span>Estimated services total: <strong>{formatMoney(createEstimatedServicesTotal, currencyCode)}</strong></span>
                                </div>
                                {createGiftCardShortfall > 0 ? (
                                    <p className="mt-2 text-sm font-semibold text-red-600">Warning: selected services exceed gift card balance by {formatMoney(createGiftCardShortfall, currencyCode)}.</p>
                                ) : null}
                                {!createGiftCardShortfall && createCustomerGiftBalance > 0 && createSelectedServices.length > 0 ? (
                                    <p className="mt-2 text-xs text-emerald-700">Selected services fit within the current gift card balance.</p>
                                ) : null}
                            </div>
                        ) : null}
                        {createHasMultipleServices ? (
                            <div>
                                <label className="ta-field-label">Default Staff Profile</label>
                                <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">Hidden for multi-service bookings. Assign staff per service below.</div>
                            </div>
                        ) : (
                            <div>
                                <label className="ta-field-label">Default Staff Profile</label>
                                <select className="ta-input" value={createForm.data.staff_profile_id} onChange={(e) => createForm.setData('staff_profile_id', e.target.value)}>
                                    <option value="">Auto / Unassigned</option>
                                    {staffProfiles.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                </select>
                                <p className="mt-1 text-xs text-slate-500">Optional default for all selected services.</p>
                                {fieldError(createForm, 'staff_profile_id')}
                            </div>
                        )}
                        {createSelectedServices.length > 0 ? (
                            <div className="md:col-span-2 rounded-lg border border-slate-200 bg-slate-50/70 p-3">
                                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Staff Per Service</p>
                                <div className="grid gap-2 md:grid-cols-2">
                                    {createSelectedServices.map((serviceId) => {
                                        const service = services.find((s) => String(s.id) === String(serviceId));
                                        const assignmentKey = String(serviceId);
                                        return (
                                            <div key={`create-staff-${serviceId}`}>
                                                <label className="ta-field-label">{service?.name || `Service #${serviceId}`}</label>
                                                <div className="mb-2">
                                                    <label className="ta-field-label">Qty</label>
                                                    <input
                                                        className="ta-input"
                                                        type="number"
                                                        min="1"
                                                        value={createForm.data.service_quantities?.[assignmentKey] || 1}
                                                        onChange={(e) => createForm.setData('service_quantities', {
                                                            ...(createForm.data.service_quantities || {}),
                                                            [assignmentKey]: e.target.value,
                                                        })}
                                                    />
                                                </div>
                                                <select
                                                    className="ta-input"
                                                    value={createForm.data.staff_assignments?.[assignmentKey] || ''}
                                                    onChange={(e) => {
                                                        createForm.clearErrors('staff_profile_id', 'staff_assignments');
                                                        const nextAssignments = {
                                                            ...(createForm.data.staff_assignments || {}),
                                                            [assignmentKey]: e.target.value,
                                                        };
                                                        createForm.setData({
                                                            ...createForm.data,
                                                            staff_assignments: nextAssignments,
                                                            staff_profile_id: hasAssignmentsForAllServices(createSelectedServices, nextAssignments)
                                                                ? ''
                                                                : createForm.data.staff_profile_id,
                                                        });
                                                    }}
                                                >
                                                    <option value="">Use default / auto</option>
                                                    {staffProfiles.map((s) => {
                                                        const availability = createStaffAvailability[String(s.id)] || createFallbackStaffAvailability[String(s.id)];
                                                        return <option key={s.id} value={s.id} disabled={Boolean(availability?.busy)}>{s.name} {availability ? `(${availability.label})` : ''}</option>;
                                                    })}
                                                </select>
                                            </div>
                                        );
                                    })}
                                </div>
                                <p className="mt-2 text-xs text-slate-500">Staff marked busy already have an overlapping appointment in the current schedule view.</p>
                                {fieldError(createForm, 'staff_assignments')}
                            </div>
                        ) : null}
                        <div>
                            <label className="ta-field-label">Scheduled Start</label>
                            <p className="mb-1 text-xs text-slate-500">Same-day visit: keep start and end within {bookingRules?.opening_time || '09:00'}–{bookingRules?.closing_time || '22:00'}; the visit must end by closing. Walk-ins can start from the current time, and future bookings can be scheduled up to the booking horizon.</p>
                            <input
                                key={`create-start-${createStartMount}`}
                                ref={createStartRef}
                                className="ta-input"
                                type="datetime-local"
                                defaultValue={createStartDefault}
                                onInput={(e) => syncCreateStartFromInput(e.currentTarget.value)}
                                min={createSalonBounds.min}
                                max={createSalonBounds.max}
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
                                {appointmentQueueRows.map((a) => (
                                    <tr key={a.key} className="border-t border-slate-100 align-top">
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
                                        <td className="px-5 py-3 text-slate-600">
                                            <div className="space-y-1">
                                                {(a.grouped_services || []).map((serviceRow) => (
                                                    <div key={serviceRow.id}>
                                                        <span className="font-medium text-slate-700">{serviceRow.name}</span>
                                                        {Number(serviceRow.quantity || 1) > 1 ? <span className="ml-1 text-xs text-slate-500">x{serviceRow.quantity}</span> : null}
                                                        <span className="ml-2 text-xs text-slate-400">({serviceRow.staff_name})</span>
                                                    </div>
                                                ))}
                                            </div>
                                        </td>
                                        <td className="px-5 py-3 text-slate-600">{a.staff_name || 'Unassigned'}</td>
                                        <td className="px-5 py-3 text-xs text-slate-600">
                                            <div className="mb-1"><span className="rounded-full bg-slate-100 px-2.5 py-1 font-semibold text-slate-700">{a.status}</span></div>
                                            {(a.grouped_services || []).length > 1 && <div className="mb-1 text-slate-500">{a.grouped_services.map((serviceRow) => `${serviceRow.name}: ${serviceRow.status}`).join(' · ')}</div>}
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
                                finish_and_pay: canCheckout && canFinishAndPayNow && checkoutFlow === 'pay',
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
                                    {canFinishAndPayNow ? (
                                        <label className="flex cursor-pointer items-start gap-2">
                                            <input type="radio" className="mt-1" name="checkout_flow" checked={checkoutFlow === 'pay'} onChange={() => setCheckoutFlow('pay')} />
                                            <span>
                                                <span className="font-medium">Finish &amp; pay now</span>
                                                <span className="mt-0.5 block text-xs text-slate-500">Completes the visit, creates the draft, issues the tax receipt number, and records one full payment in a single step.</span>
                                            </span>
                                        </label>
                                    ) : null}
                                    <label className="flex cursor-pointer items-start gap-2">
                                        <input type="radio" className="mt-1" name="checkout_flow" checked={checkoutFlow === 'skip'} onChange={() => setCheckoutFlow('skip')} />
                                        <span>
                                            <span className="font-medium">No invoice from this screen</span>
                                            <span className="mt-0.5 block text-xs text-slate-500">Use when billing is handled separately (for example a package or account client).</span>
                                        </span>
                                    </label>
                                </div>
                                {checkoutFlow !== 'skip' ? (
                                    <div className="grid gap-3 border-t border-slate-200 pt-3 md:grid-cols-2">
                                        <div className="md:col-span-2 rounded-lg border border-slate-200 bg-white p-3 text-sm text-slate-700">
                                            <div className="flex items-center justify-between">
                                                <span>
                                                    Service ({completingService?.name || 'Selected service'}{completingServiceQuantity > 1 ? ` x ${completingServiceQuantity}` : ''})
                                                    {completingAppt?.customer_package_id ? ` - ${completingAppt?.package_name || 'Package session'}` : ''}
                                                </span>
                                                <span className="font-medium">{formatMoney(completingServiceAmount, currencyCode)}</span>
                                            </div>
                                            {selectedProductLines.length > 0 ? (
                                                selectedProductLines.map((line, idx) => (
                                                    <div key={`${line.inventory_item_id}-${idx}`} className="mt-1 flex items-center justify-between text-xs text-slate-600">
                                                        <span>{line.label} x {line.quantity}</span>
                                                        <span>{formatMoney(line.lineTotal, currencyCode)}</span>
                                                    </div>
                                                ))
                                            ) : (
                                                <div className="mt-1 text-xs text-slate-500">No extra products selected.</div>
                                            )}
                                            <div className="mt-2 flex items-center justify-between border-t border-slate-200 pt-2 text-sm font-semibold text-slate-900">
                                                <span>Estimated total</span>
                                                <span>{formatMoney(previewTotalAmount, currencyCode)}</span>
                                            </div>
                                            {completingCustomer ? (
                                                <div className="mt-2 flex items-center justify-between text-xs text-slate-600">
                                                    <span>Customer gift card balance</span>
                                                    <span>{formatMoney(completingCustomerGiftBalance, currencyCode)}</span>
                                                </div>
                                            ) : null}
                                            {completingGiftCardShortfall > 0 ? (
                                                <div className="mt-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">
                                                    Warning: gift card balance is short by {formatMoney(completingGiftCardShortfall, currencyCode)}.
                                                </div>
                                            ) : null}
                                        </div>
                                        {checkoutFlow === 'pay' ? (
                                            <>
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
                                                <p className="mt-1 text-xs text-slate-500">Available balance: {formatMoney(completingCustomerGiftBalance, currencyCode)}. Invoice estimate: {formatMoney(previewTotalAmount, currencyCode)}.</p>
                                                {completingGiftCardShortfall > 0 ? <p className="mt-1 text-xs font-semibold text-red-600">This gift card does not fully cover the visit total.</p> : null}
                                                {fieldError(completeForm, 'checkout_gift_card_id')}
                                            </div>
                                        ) : null}
                                            </>
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
                                            {inventoryItems.map((item) => <option key={item.id} value={item.id}>{item.name} ({item.sku}) - {formatMoney(item.selling_price, currencyCode)}</option>)}
                                        </select>
                                        {product.inventory_item_id ? (() => {
                                            const selected = inventoryItems.find((inv) => String(inv.id) === String(product.inventory_item_id));
                                            const quantity = Math.max(1, Number(product.quantity || 1));
                                            const unitPrice = Number(selected?.selling_price || 0);

                                            return (
                                                <p className="mt-1 text-xs text-slate-500">
                                                    Unit: {formatMoney(unitPrice, currencyCode)} | Line: {formatMoney(unitPrice * quantity, currencyCode)}
                                                </p>
                                            );
                                        })() : null}
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
                                    setEditSelectedPackageId('');
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
                            <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                                <input type="radio" name="edit_customer_mode" className="text-indigo-600" checked={editCustomerMode === 'package'} onChange={() => { setEditCustomerMode('package'); setEditSelectedCustomerId(''); applyCustomerToEditForm(null); }} />
                                Package customer
                            </label>
                        </div>
                        {editCustomerMode === 'existing' || editCustomerMode === 'package' ? (
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
                                {editCustomerHasGiftCards ? (
                                    <p className="mt-2 text-sm font-semibold text-emerald-800">Gift card remaining balance: {formatMoney(editCustomerGiftBalance, currencyCode)}</p>
                                ) : null}
                            </div>
                        ) : null}
                        {editCustomerMode === 'package' && editSelectedCustomerId ? (
                            <div className="md:col-span-2 rounded-lg border border-emerald-200 bg-emerald-50/70 p-3">
                                <label className="ta-field-label">Select package</label>
                                <select
                                    className="ta-input"
                                    value={editSelectedPackageId}
                                    onChange={(e) => {
                                        const id = e.target.value;
                                        setEditSelectedPackageId(id);
                                        editForm.setData('customer_package_id', id);
                                        editForm.setData('package_service_ids', []);
                                    }}
                                >
                                    <option value="">Choose customer package…</option>
                                    {editAvailablePackages.map((pkg) => (
                                        <option key={pkg.id} value={pkg.id}>
                                            {pkg.package_name}
                                        </option>
                                    ))}
                                </select>
                                {editSelectedPackage ? (
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {editSelectedPackage.services.map((service) => (
                                            <span key={service.id} className={`rounded-full px-2 py-1 text-xs ${service.remaining_sessions > 0 ? 'border border-emerald-200 bg-white text-emerald-800' : 'border border-slate-200 bg-slate-100 text-slate-500'}`}>
                                                {service.name} {service.remaining_sessions}/{service.included_sessions} left
                                            </span>
                                        ))}
                                    </div>
                                ) : null}
                            </div>
                        ) : null}
                        <div><label className="ta-field-label">{editCustomerMode === 'existing' ? 'Name' : 'Full name'}</label><input className="ta-input" value={editForm.data.customer_name} onChange={(e) => editForm.setData('customer_name', e.target.value)} required />{fieldError(editForm, 'customer_name')}</div>
                        <div><label className="ta-field-label">{editCustomerMode === 'existing' ? 'Phone number' : 'Phone'}</label><input className="ta-input" value={editForm.data.customer_phone} onChange={(e) => editForm.setData('customer_phone', e.target.value)} required />{fieldError(editForm, 'customer_phone')}</div>
                        <div><label className="ta-field-label">Email</label><input className="ta-input" type="email" value={editForm.data.customer_email} onChange={(e) => editForm.setData('customer_email', e.target.value)} />{fieldError(editForm, 'customer_email')}</div>
                        <div>
                            <label className="ta-field-label">Services</label>
                            <input className="ta-input" value={editServiceSearch} onChange={(e) => setEditServiceSearch(e.target.value)} placeholder="Search by service or category" />
                            <div className="mt-2 max-h-36 overflow-auto rounded-lg border border-slate-200 bg-white">
                                {editFilteredServices.map((s) => (
                                    <button
                                        key={s.id}
                                        type="button"
                                        className="block w-full border-b border-slate-100 px-3 py-2 text-left text-xs text-slate-700 hover:bg-slate-50"
                                        onClick={() => handleEditServiceChange([...editSelectedServices, String(s.id)])}
                                    >
                                        <div className="font-medium text-slate-700">{s.name}</div>
                                        <div className="mt-0.5 text-[11px] text-slate-500">{s.category || 'Uncategorized'} • {s.duration_minutes}m • <span className="font-bold text-slate-700">{formatMoney(s.price, currencyCode)}</span></div>
                                    </button>
                                ))}
                                {editFilteredServices.length === 0 ? <div className="px-3 py-2 text-xs text-slate-500">No more services found.</div> : null}
                            </div>
                            <div className="mt-2 flex flex-wrap gap-2">
                                {editSelectedServices.map((id) => {
                                    const s = services.find((x) => String(x.id) === String(id));
                                    if (!s) return null;
                                    return (
                                        <button key={id} type="button" className="rounded-full border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs text-indigo-700" onClick={() => handleEditServiceChange(editSelectedServices.filter((x) => x !== id))}>
                                            {s.name}{Number(editForm.data.service_quantities?.[String(id)] || 1) > 1 ? ` x${editForm.data.service_quantities?.[String(id)]}` : ''} ✕
                                        </button>
                                    );
                                })}
                            </div>
                            {fieldError(editForm, 'service_id')}
                            {fieldError(editForm, 'service_ids')}
                        </div>
                        {editSelectedCustomer && editCustomerHasGiftCards ? (
                            <div className="md:col-span-2 rounded-lg border border-slate-200 bg-slate-50/70 p-3">
                                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Gift Card Check</p>
                                <div className="mt-2 flex flex-wrap gap-4 text-sm text-slate-700">
                                    <span>Gift card balance: <strong>{formatMoney(editCustomerGiftBalance, currencyCode)}</strong></span>
                                    <span>Estimated services total: <strong>{formatMoney(editEstimatedServicesTotal, currencyCode)}</strong></span>
                                </div>
                                {editGiftCardShortfall > 0 ? (
                                    <p className="mt-2 text-sm font-semibold text-red-600">Warning: selected services exceed gift card balance by {formatMoney(editGiftCardShortfall, currencyCode)}.</p>
                                ) : null}
                                {!editGiftCardShortfall && editCustomerGiftBalance > 0 && editSelectedServices.length > 0 ? (
                                    <p className="mt-2 text-xs text-emerald-700">Selected services fit within the current gift card balance.</p>
                                ) : null}
                            </div>
                        ) : null}
                        {editHasMultipleServices ? (
                            <div>
                                <label className="ta-field-label">Default Staff Profile</label>
                                <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500">Hidden for multi-service bookings. Assign staff per service below.</div>
                            </div>
                        ) : (
                            <div>
                                <label className="ta-field-label">Default Staff Profile</label>
                                <select className="ta-input" value={editForm.data.staff_profile_id} onChange={(e) => editForm.setData('staff_profile_id', e.target.value)}>
                                    <option value="">Auto / Unassigned</option>
                                    {staffProfiles.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                </select>
                                <p className="mt-1 text-xs text-slate-500">Optional default for all selected services.</p>
                                {fieldError(editForm, 'staff_profile_id')}
                            </div>
                        )}
                        {editSelectedServices.length > 0 ? (
                            <div className="md:col-span-2 rounded-lg border border-slate-200 bg-slate-50/70 p-3">
                                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Staff Per Service</p>
                                <div className="grid gap-2 md:grid-cols-2">
                                    {editSelectedServices.map((serviceId) => {
                                        const service = services.find((s) => String(s.id) === String(serviceId));
                                        const assignmentKey = String(serviceId);
                                        return (
                                            <div key={`edit-staff-${serviceId}`}>
                                                <label className="ta-field-label">{service?.name || `Service #${serviceId}`}</label>
                                                <div className="mb-2">
                                                    <label className="ta-field-label">Qty</label>
                                                    <input
                                                        className="ta-input"
                                                        type="number"
                                                        min="1"
                                                        value={editForm.data.service_quantities?.[assignmentKey] || 1}
                                                        onChange={(e) => editForm.setData('service_quantities', {
                                                            ...(editForm.data.service_quantities || {}),
                                                            [assignmentKey]: e.target.value,
                                                        })}
                                                    />
                                                </div>
                                                <select
                                                    className="ta-input"
                                                    value={editForm.data.staff_assignments?.[assignmentKey] || ''}
                                                    onChange={(e) => {
                                                        editForm.clearErrors('staff_profile_id', 'staff_assignments');
                                                        const nextAssignments = {
                                                            ...(editForm.data.staff_assignments || {}),
                                                            [assignmentKey]: e.target.value,
                                                        };
                                                        editForm.setData({
                                                            ...editForm.data,
                                                            staff_assignments: nextAssignments,
                                                            staff_profile_id: hasAssignmentsForAllServices(editSelectedServices, nextAssignments)
                                                                ? ''
                                                                : editForm.data.staff_profile_id,
                                                        });
                                                    }}
                                                >
                                                    <option value="">Use default / auto</option>
                                                    {staffProfiles.map((s) => {
                                                        const availability = editStaffAvailability[String(s.id)] || editFallbackStaffAvailability[String(s.id)];
                                                        return <option key={s.id} value={s.id} disabled={Boolean(availability?.busy)}>{s.name} {availability ? `(${availability.label})` : ''}</option>;
                                                    })}
                                                </select>
                                            </div>
                                        );
                                    })}
                                </div>
                                <p className="mt-2 text-xs text-slate-500">Staff marked busy already have an overlapping appointment in the current schedule view.</p>
                                {fieldError(editForm, 'staff_assignments')}
                            </div>
                        ) : null}
                        <div><label className="ta-field-label">Status</label><select className="ta-input" value={editForm.data.status} onChange={(e) => editForm.setData('status', e.target.value)}><option value="pending">pending</option><option value="confirmed">confirmed</option><option value="in_progress">in_progress</option><option value="completed">completed</option><option value="cancelled">cancelled</option><option value="no_show">no_show</option></select>{fieldError(editForm, 'status')}</div>
                        <div>
                            <label className="ta-field-label">Scheduled Start</label>
                            <p className="mb-1 text-xs text-slate-500">Same-day visit: keep start and end within {bookingRules?.opening_time || '09:00'}–{bookingRules?.closing_time || '22:00'}; the visit must end by closing. Walk-ins can start from the current time, and future bookings can be scheduled up to the booking horizon.</p>
                            <input
                                key={`edit-start-${editStartMountKey}`}
                                ref={editStartRef}
                                className="ta-input"
                                type="datetime-local"
                                defaultValue={editStartDefault}
                                onInput={(e) => syncEditStartFromInput(e.currentTarget.value)}
                                min={editSalonBounds.min}
                                max={editSalonBounds.max}
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

            <Modal show={showBoardView} maxWidth="full" onClose={() => setShowBoardView(false)}>
                <div className="flex h-[90vh] flex-col bg-[#111315] text-white">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 px-6 py-4">
                        <div>
                            <h3 className="text-lg font-semibold">Appointment Board</h3>
                            <p className="text-sm text-slate-300">Time-based planner by staff. Covered package visits still appear here, but invoice at zero when completed.</p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                onClick={() => {
                                    const d = new Date(`${boardDate}T12:00:00`);
                                    d.setDate(d.getDate() - 1);
                                    setBoardDate(localYmd(d));
                                }}
                                className="rounded-xl border border-white/10 px-3 py-2 text-sm text-slate-200 hover:bg-white/5"
                            >
                                Prev
                            </button>
                            <input
                                className="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-sm text-white"
                                type="date"
                                value={boardDate}
                                onChange={(e) => setBoardDate(e.target.value)}
                            />
                            <button
                                type="button"
                                onClick={() => {
                                    const d = new Date(`${boardDate}T12:00:00`);
                                    d.setDate(d.getDate() + 1);
                                    setBoardDate(localYmd(d));
                                }}
                                className="rounded-xl border border-white/10 px-3 py-2 text-sm text-slate-200 hover:bg-white/5"
                            >
                                Next
                            </button>
                            <select
                                className="rounded-xl border border-white/10 bg-white px-3 py-2 text-sm text-slate-900"
                                value={boardStaffFilter}
                                onChange={(e) => setBoardStaffFilter(e.target.value)}
                            >
                                <option value="all" className="bg-white text-slate-900">All team</option>
                                {staffProfiles.map((staff) => (
                                    <option key={staff.id} value={staff.id} className="bg-white text-slate-900">{staff.name}</option>
                                ))}
                            </select>
                            <button type="button" onClick={() => setShowBoardView(false)} className="rounded-xl border border-white/10 px-3 py-2 text-sm text-slate-200 hover:bg-white/5">Close</button>
                        </div>
                    </div>

                    <div className="flex min-h-0 flex-1 overflow-auto">
                        <div className="sticky left-0 z-20 w-24 shrink-0 border-r border-white/10 bg-[#0b0d0f]">
                            <div className="h-24 border-b border-white/10 px-3 py-4 text-xs font-semibold uppercase tracking-[0.2em] text-slate-500">Time</div>
                            <div className="relative">
                                {boardHourMarks.slice(0, -1).map((minutes) => (
                                    <div key={minutes} className="flex h-20 items-start border-b border-white/5 px-3 pt-2 text-xs text-slate-400">
                                        {formatHourLabel(Math.floor(minutes / 60))}
                                    </div>
                                ))}
                            </div>
                        </div>

                        <div className="flex min-w-max flex-1">
                            {boardCardsByStaff.map(({ staff, cards }) => (
                                <div key={staff.id} className="w-72 shrink-0 border-r border-white/10">
                                    <div className="sticky top-0 z-10 flex h-24 flex-col items-center justify-center gap-2 border-b border-white/10 bg-[#171a1d] px-4">
                                        <div className="flex h-12 w-12 items-center justify-center rounded-full border border-cyan-300/40 bg-slate-800 text-sm font-semibold text-cyan-200">
                                            {(staff.name || '?').split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase()}
                                        </div>
                                        <div className="text-center text-sm font-medium text-slate-100">{staff.name}</div>
                                    </div>
                                    <div className="relative" style={{ height: `${(boardHourMarks.length - 1) * 80}px` }}>
                                        {boardHourMarks.slice(0, -1).map((minutes) => (
                                            <div key={`${staff.id}-${minutes}`} className="h-20 border-b border-white/5" />
                                        ))}
                                        {cards.map((appt) => (
                                            <button
                                                key={appt.id}
                                                type="button"
                                                onClick={() => {
                                                    setShowBoardView(false);
                                                    if (appt.status === 'confirmed') openStartService(appt);
                                                    else if (appt.status === 'in_progress' || appt.status === 'completed') openCompleteService(appt);
                                                    else startEdit(appt);
                                                }}
                                                className="absolute overflow-hidden rounded-xl border p-2 text-left shadow-lg transition hover:scale-[1.01]"
                                                style={{
                                                    ...appt.cardStyle,
                                                    top: `${appt.top}%`,
                                                    height: `${appt.height}%`,
                                                    left: `calc(${appt.left}% + 0.5rem)`,
                                                    width: `calc(${appt.width}% - 0.75rem)`,
                                                    zIndex: appt.zIndex,
                                                }}
                                            >
                                                <div className="text-[11px] font-semibold text-slate-600">{appt.timeLabel}</div>
                                                <div className="mt-1 text-sm font-semibold">{appt.customer_name}</div>
                                                <div className="text-xs text-slate-700">{appt.service_name}</div>
                                                {!appt.isPaid ? <div className="mt-1 text-[11px] font-medium text-slate-600">{appt.category}</div> : null}
                                                {appt.customer_package_id ? <div className="mt-1 text-[11px] font-medium text-emerald-700">Package session</div> : null}
                                                {appt.isPaid ? <div className="mt-1 text-[11px] font-medium text-emerald-700">Paid</div> : null}
                                                {appt.awaiting_checkout ? <div className="mt-1 text-[11px] font-medium text-amber-700">Needs payment</div> : null}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            ))}
                            {boardCardsByStaff.length === 0 ? (
                                <div className="flex flex-1 items-center justify-center text-sm text-slate-400">No staff selected.</div>
                            ) : null}
                        </div>
                    </div>
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
