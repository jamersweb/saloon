import ConfirmActionModal from '@/Components/ConfirmActionModal';
import Modal from '@/Components/Modal';
import SearchableSelect from '@/Components/SearchableSelect';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { flushSync } from 'react-dom';
import { useEffect, useMemo, useRef, useState } from 'react';

const statusLabels = { pending: 'Pending', confirmed: 'Confirm', in_progress: 'Start', completed: 'Complete', cancelled: 'Cancel', no_show: 'No-show' };
const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;
const isSeedReferenceNote = (value) => /^SEED-APPT-\d{12}-\d+$/i.test(String(value || '').trim());
const pad2 = (value) => String(value).padStart(2, '0');
const SALON_TIME_ZONE = 'Asia/Dubai';
const COMPLETABLE_SERVICE_STATUSES = ['confirmed', 'in_progress'];
const salonDateFormatter = new Intl.DateTimeFormat('en-CA', {
    timeZone: SALON_TIME_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
});

const salonDateTimeParts = (date = new Date()) => {
    const parts = Object.fromEntries(
        salonDateFormatter
            .formatToParts(date)
            .filter((part) => part.type !== 'literal')
            .map((part) => [part.type, part.value]),
    );

    return {
        ymd: `${parts.year}-${parts.month}-${parts.day}`,
        hour: Number(parts.hour || 0),
        minute: Number(parts.minute || 0),
    };
};

const salonTodayYmd = () => salonDateTimeParts().ymd;
const salonNowMinutes = () => {
    const parts = salonDateTimeParts();

    return (parts.hour * 60) + parts.minute;
};

const minutesToClock = (minutes) => {
    const normalized = ((Number(minutes || 0) % 1440) + 1440) % 1440;

    return `${pad2(Math.floor(normalized / 60))}:${pad2(normalized % 60)}`;
};

const minutesToDateTimeLocal = (ymd, minutes) => `${ymd}T${minutesToClock(minutes)}`;

const shiftYmdByDays = (ymd, days) => {
    const [year, month, day] = String(ymd || '').split('-').map((part) => Number(part));
    if (!year || !month || !day) return salonTodayYmd();
    const date = new Date(Date.UTC(year, month - 1, day + Number(days || 0), 12, 0, 0));

    return `${date.getUTCFullYear()}-${pad2(date.getUTCMonth() + 1)}-${pad2(date.getUTCDate())}`;
};

const parseDateTimeParts = (value) => {
    if (!value) return null;
    const raw = String(value);
    const localMatch = raw.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})/);
    if (localMatch && !/[zZ]|[+-]\d{2}:?\d{2}$/.test(raw.trim())) {
        return {
            ymd: `${localMatch[1]}-${localMatch[2]}-${localMatch[3]}`,
            hour: Number(localMatch[4]),
            minute: Number(localMatch[5]),
        };
    }

    const date = value instanceof Date ? value : new Date(value);
    if (Number.isNaN(date.getTime())) {
        return localMatch ? {
            ymd: `${localMatch[1]}-${localMatch[2]}-${localMatch[3]}`,
            hour: Number(localMatch[4]),
            minute: Number(localMatch[5]),
        } : null;
    }

    return salonDateTimeParts(date);
};

const dateTimeLocalYmd = (value) => parseDateTimeParts(value)?.ymd || '';
const dateTimeLocalMinutes = (value) => {
    const parts = parseDateTimeParts(value);

    return parts ? (parts.hour * 60) + parts.minute : Number.NaN;
};

const formatMinutesAmPm = (minutes) => {
    const normalized = ((Number(minutes || 0) % 1440) + 1440) % 1440;
    const hour = Math.floor(normalized / 60);
    const minute = normalized % 60;
    const suffix = hour >= 12 ? 'PM' : 'AM';

    return `${hour % 12 || 12}:${pad2(minute)} ${suffix}`;
};

/** Parse datetime-local string to epoch ms (local); invalid → NaN. */
const dateTimeLocalMs = (value) => {
    if (!value) return Number.NaN;
    const parts = parseDateTimeParts(value);
    if (!parts) return Number.NaN;
    const ms = new Date(`${parts.ymd}T${minutesToClock((parts.hour * 60) + parts.minute)}`).getTime();

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
    const parts = parseDateTimeParts(value);
    if (!parts) return '';

    return `${parts.ymd}T${pad2(parts.hour)}:${pad2(parts.minute)}`;
};
const formatDateTime = (value) => {
    const parts = parseDateTimeParts(value);
    if (!parts) return 'N/A';

    return `${parts.ymd} ${formatMinutesAmPm((parts.hour * 60) + parts.minute)}`;
};
const formatHourLabel = (hour) => {
    const suffix = hour >= 12 ? 'PM' : 'AM';
    const normalized = hour % 12 || 12;
    return `${normalized}:00 ${suffix}`;
};
const formatMoney = (value, currencyCode = 'AED') =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency: currencyCode, minimumFractionDigits: 2 }).format(Number(value || 0));
const appointmentCategoryCardPalettes = {
    hair: { backgroundColor: '#facc15', borderColor: '#a16207', color: '#000000' },
    makeup: { backgroundColor: '#fb7185', borderColor: '#be123c', color: '#000000' },
    threading: { backgroundColor: '#a78bfa', borderColor: '#6d28d9', color: '#000000' },
    eyelash: { backgroundColor: '#22d3ee', borderColor: '#0891b2', color: '#000000' },
    waxing: { backgroundColor: '#4ade80', borderColor: '#16a34a', color: '#000000' },
    nails: { backgroundColor: '#fb923c', borderColor: '#ea580c', color: '#000000' },
    hair_extension: { backgroundColor: '#60a5fa', borderColor: '#2563eb', color: '#000000' },
    brows: { backgroundColor: '#f472b6', borderColor: '#be185d', color: '#000000' },
    default: { backgroundColor: '#cbd5e1', borderColor: '#475569', color: '#000000' },
};
const resolveAppointmentCategoryPalette = (value) => {
    const text = String(value || '').trim().toLowerCase();
    if (!text) return appointmentCategoryCardPalettes.default;

    if (text.includes('hair extension')) return appointmentCategoryCardPalettes.hair_extension;
    if (text.includes('eyelash') || text.includes('lashes') || text.includes('lash')) return appointmentCategoryCardPalettes.eyelash;
    if (text.includes('eyebrow') || text.includes('brow')) return appointmentCategoryCardPalettes.brows;
    if (text.includes('thread')) return appointmentCategoryCardPalettes.threading;
    if (text.includes('makeup') || text.includes('make up')) return appointmentCategoryCardPalettes.makeup;
    if (text.includes('wax')) return appointmentCategoryCardPalettes.waxing;
    if (text.includes('nail') || text.includes('manicure') || text.includes('pedicure')) return appointmentCategoryCardPalettes.nails;
    if (text.includes('hair')) return appointmentCategoryCardPalettes.hair;

    return appointmentCategoryCardPalettes.default;
};
const getAppointmentCardStyle = (category, isPaid) => {
    if (isPaid) {
        return { backgroundColor: '#ffffff', borderColor: '#ffffff', color: '#0f172a' };
    }

    return resolveAppointmentCategoryPalette(category);
};
const getServiceAccentColor = (service) => resolveAppointmentCategoryPalette(`${service?.category || ''} ${service?.name || ''}`).backgroundColor;
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
const serviceLineKey = (index) => `line_${index}`;
const serviceLineMapValue = (map, index, serviceId, fallback = undefined) => {
    const lineKey = serviceLineKey(index);
    if (map && Object.prototype.hasOwnProperty.call(map, lineKey)) return map[lineKey];
    if (map && Object.prototype.hasOwnProperty.call(map, String(index))) return map[String(index)];
    if (map && Object.prototype.hasOwnProperty.call(map, String(serviceId))) return map[String(serviceId)];

    return fallback;
};
const normalizeServiceQuantities = (serviceIds, serviceQuantities) => {
    const next = {};
    (serviceIds || []).forEach((serviceId, index) => {
        const key = serviceLineKey(index);
        next[key] = Math.max(1, Number(serviceLineMapValue(serviceQuantities, index, serviceId, 1)));
    });

    return next;
};
const filterServiceMap = (serviceIds, map) => {
    const allowedServiceIds = new Set((serviceIds || []).map((id) => String(id)));
    const allowedLineKeys = new Set((serviceIds || []).map((_, index) => serviceLineKey(index)));

    return Object.fromEntries(
        Object.entries(map || {}).filter(([key, value]) => (allowedLineKeys.has(String(key)) || allowedServiceIds.has(String(key))) && value !== undefined && value !== null && value !== ''),
    );
};
const rekeyServiceMapByLines = (serviceIds, map) => {
    const next = {};
    (serviceIds || []).forEach((serviceId, index) => {
        const value = serviceLineMapValue(map, index, serviceId);
        if (value !== undefined && value !== null && value !== '') {
            next[serviceLineKey(index)] = value;
        }
    });

    return next;
};
const normalizeServiceStarts = (serviceIds, serviceStarts, fallbackStart = '') => {
    const next = {};
    (serviceIds || []).forEach((serviceId, index) => {
        const value = serviceLineMapValue(serviceStarts, index, serviceId, fallbackStart);
        if (value) next[serviceLineKey(index)] = value;
    });

    return next;
};
const estimateSelectedServicesTotal = (serviceIds, serviceQuantities, services, coveredServiceIds = []) => {
    const covered = new Set((coveredServiceIds || []).map((id) => String(id)));

    return (serviceIds || []).reduce((sum, serviceId, index) => {
        const service = services.find((item) => String(item.id) === String(serviceId));
        if (!service) return sum;
        if (covered.has(String(serviceId))) return sum;
        const quantity = Math.max(1, Number(serviceLineMapValue(serviceQuantities, index, serviceId, 1)));
        return sum + (Number(service.price || 0) * quantity);
    }, 0);
};
const addMinutesToDateTimeLocal = (value, minutes) => {
    if (!value) return '';
    const parts = parseDateTimeParts(value);
    if (!parts) return '';

    const total = (parts.hour * 60) + parts.minute + Number(minutes || 0);
    const dayOffset = Math.floor(total / 1440);
    const ymd = dayOffset ? shiftYmdByDays(parts.ymd, dayOffset) : parts.ymd;

    return minutesToDateTimeLocal(ymd, total);
};
const formatTimeFromDateTimeLocal = (value) => {
    if (!value) return '';
    const minutes = dateTimeLocalMinutes(value);
    if (Number.isNaN(minutes)) return '';

    return formatMinutesAmPm(minutes);
};
const hasAssignmentsForAllServices = (serviceIds, staffAssignments) => {
    const selected = (serviceIds || []).map((id) => String(id));
    if (selected.length === 0) return false;

    return selected.every((serviceId, index) => String(staffAssignments?.[serviceLineKey(index)] || staffAssignments?.[serviceId] || '').trim() !== '');
};
const localYmd = (d) => salonDateTimeParts(d).ymd;
const sameLocalDate = (a, ymd) => {
    if (!a || !ymd) return false;
    return dateTimeLocalYmd(a) === ymd;
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

    const todayYmd = salonTodayYmd();
    const step = Math.max(1, Number(slotIntervalMinutes || 30));
    const minAdv = Math.max(0, Number(bookingRules?.min_advance_minutes || 0));

    if (dateYmd === todayYmd) {
        const threshold = salonDateTimeParts(new Date(Date.now() + minAdv * 60000));
        const thYmd = threshold.ymd;
        if (thYmd > dateYmd) {
            return { min: max, max };
        }
        const minsFloat = (threshold.hour * 60) + threshold.minute;
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
    const todayYmd = salonTodayYmd();
    const maxAdvanceDays = Math.max(1, Number(bookingRules?.max_advance_days || 60));
    const horizonYmd = shiftYmdByDays(todayYmd, maxAdvanceDays);

    let min = `${dateYmd}T${pad2(open.h)}:${pad2(open.m)}`;
    if (dateYmd === todayYmd) {
        const nowLocal = minutesToDateTimeLocal(todayYmd, salonNowMinutes());
        if (dateTimeLocalCompare(nowLocal, min) > 0) min = nowLocal;
    }

    return {
        min,
        max: `${horizonYmd}T23:59`,
    };
};

const adminEditStartBoundsForYmd = (dateYmd, bookingRules) => {
    const open = salonClockBoundary(bookingRules, 'opening_time', '09:00');
    const todayYmd = salonTodayYmd();
    const maxAdvanceDays = Math.max(1, Number(bookingRules?.max_advance_days || 60));
    const horizonYmd = shiftYmdByDays(todayYmd, maxAdvanceDays);

    return {
        min: `${dateYmd}T${pad2(open.h)}:${pad2(open.m)}`,
        max: `${horizonYmd}T23:59`,
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

const clampAdminEditStartDatetimeLocal = (value, bookingRules, slotIntervalMinutes = 30) => {
    if (!value || !bookingRules) return value;
    const [d] = value.split('T');
    if (!d) return value;
    const { min, max } = adminEditStartBoundsForYmd(d, bookingRules, slotIntervalMinutes);
    let v = value;
    if (dateTimeLocalCompare(v, min) < 0) v = min;
    if (dateTimeLocalCompare(v, max) > 0) v = max;

    return v;
};

export default function AppointmentsIndex({ appointments, appointmentBlocks = [], staffSchedules = [], services, customers = [], staffProfiles, inventoryItems, statusFilter, bookingRules, defaultStart, gift_cards_for_checkout = [] }) {
    const { app_currency_code: currencyCode = 'AED' } = usePage().props;
    const { flash, auth } = usePage().props;
    const serviceCategoryMap = useMemo(
        () => Object.fromEntries((services || []).map((service) => [String(service.id), `${service.category || ''} ${service.name || ''}`.trim() || 'Uncategorized'])),
        [services],
    );
    const roleName = String(auth?.user?.role?.name || '').toLowerCase();
    const isStaff = roleName === 'staff';
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
    const [editSaving, setEditSaving] = useState(false);
    const [deleteAppointmentId, setDeleteAppointmentId] = useState(null);
    const [deleteAppointmentBusy, setDeleteAppointmentBusy] = useState(false);
    const importFileRef = useRef(null);
    const calendarClientNameRef = useRef(null);
    const [checkoutFlow, setCheckoutFlow] = useState('draft');
    const [createCustomerSearch, setCreateCustomerSearch] = useState('');
    const [createServiceSearch, setCreateServiceSearch] = useState('');
    const [editServiceSearch, setEditServiceSearch] = useState('');
    const [showBoardView, setShowBoardView] = useState(false);
    const [boardDate, setBoardDate] = useState(() => salonTodayYmd());
    const [boardStaffFilter, setBoardStaffFilter] = useState('all');
    const [boardStaffMenu, setBoardStaffMenu] = useState(null);
    const [calendarQuickAction, setCalendarQuickAction] = useState(null);
    const [calendarDrawer, setCalendarDrawer] = useState(null);
    const [calendarAppointmentId, setCalendarAppointmentId] = useState(null);
    const [calendarServiceEditorId, setCalendarServiceEditorId] = useState('');
    const [createStaffAvailability, setCreateStaffAvailability] = useState({});
    const [editStaffAvailability, setEditStaffAvailability] = useState({});
    const [draggingAppointmentId, setDraggingAppointmentId] = useState(null);
    const [boardMoveError, setBoardMoveError] = useState('');
    const slotIntervalMinutes = Math.max(1, Number(bookingRules?.slot_interval_minutes || 30));

    const createStartRef = useRef(null);
    const editStartRef = useRef(null);
    const [createStartMount, setCreateStartMount] = useState(0);
    const [createStartYmd, setCreateStartYmd] = useState(() => ((defaultStart || '').split('T')[0] || salonTodayYmd()));
    const [editStartYmd, setEditStartYmd] = useState(() => salonTodayYmd());
    const [editStartMountKey, setEditStartMountKey] = useState(0);

    const createForm = useForm({ customer_id: '', customer_name: '', customer_phone: '', customer_email: '', service_id: '', service_ids: [], service_quantities: {}, service_starts: {}, service_durations: {}, service_extra_minutes: {}, service_unit_prices: {}, service_discount_amounts: {}, customer_package_id: '', package_service_ids: [], staff_profile_id: '', staff_assignments: {}, scheduled_start: defaultStart || '', scheduled_end: '', status: 'confirmed', notes: '' });
    const editForm = useForm({ customer_id: '', customer_name: '', customer_phone: '', customer_email: '', service_id: '', service_ids: [], service_quantities: {}, service_starts: {}, service_durations: {}, service_extra_minutes: {}, service_unit_prices: {}, service_discount_amounts: {}, customer_package_id: '', package_service_ids: [], staff_profile_id: '', staff_assignments: {}, scheduled_start: '', scheduled_end: '', status: 'confirmed', notes: '' });
    const blockForm = useForm({ staff_profile_id: '', title: 'Blocked time', starts_at: '', ends_at: '', notes: '' });
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
        additional_services: [],
        complete_visit_service_ids: [],
    });

    useEffect(() => {
        const y = (defaultStart || '').split('T')[0] || salonTodayYmd();
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

    const selectedStaffShiftEnd = (startValue, formData = {}) => {
        const startYmd = dateTimeLocalYmd(startValue);
        const startMinutes = dateTimeLocalMinutes(startValue);
        if (!startYmd || Number.isNaN(startMinutes)) return '';

        const selectedStaffIds = [...new Set([
            formData.staff_profile_id,
            ...Object.values(formData.staff_assignments || {}),
        ].map((id) => String(id || '')).filter(Boolean))];

        if (selectedStaffIds.length !== 1) return '';

        const shiftEnds = selectedStaffIds.map((staffId) => {
            const schedule = staffSchedules.find((item) => (
                String(item.staff_profile_id) === staffId
                && item.schedule_date === startYmd
                && !item.is_day_off
                && item.start_time
                && item.end_time
            ));

            if (!schedule) return '';

            const shiftStart = dateTimeLocalMinutes(`${startYmd}T${String(schedule.start_time).slice(0, 5)}`);
            let shiftEnd = dateTimeLocalMinutes(`${startYmd}T${String(schedule.end_time).slice(0, 5)}`);
            if (Number.isNaN(shiftStart) || Number.isNaN(shiftEnd)) return '';
            if (shiftEnd <= shiftStart) shiftEnd += 1440;
            if (startMinutes > shiftEnd) return '';

            return minutesToDateTimeLocal(startYmd, shiftEnd);
        }).filter(Boolean);

        if (shiftEnds.length === 0) return '';

        return shiftEnds.reduce((earliest, value) => (dateTimeLocalCompare(value, earliest) < 0 ? value : earliest), shiftEnds[0]);
    };

    const capSuggestedEndToStaffShift = (startValue, endValue, formData = {}) => {
        const shiftEnd = selectedStaffShiftEnd(startValue, formData);
        if (
            shiftEnd
            && endValue
            && dateTimeLocalCompare(endValue, shiftEnd) > 0
            && dateTimeLocalCompare(shiftEnd, startValue) > 0
        ) {
            return shiftEnd;
        }

        return endValue;
    };

    const calculateSuggestedEnd = (startValue, serviceIds) => {
        if (!startValue || !Array.isArray(serviceIds) || serviceIds.length === 0) return '';

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

    const calculateSuggestedEndWithServiceMeta = (startValue, serviceIds, formData = createForm.data) => {
        if (!startValue || !Array.isArray(serviceIds) || serviceIds.length === 0) return '';

        const serviceEnds = serviceIds.map((id, index) => {
            const service = services.find((s) => String(s.id) === String(id));
            if (!service) return '';
            const serviceStart = serviceLineMapValue(formData.service_starts, index, id, startValue) || startValue;
            const baseDuration = Math.max(1, Number(serviceLineMapValue(formData.service_durations, index, id, service.duration_minutes || 0)));
            const extraMinutes = Math.max(0, Number(serviceLineMapValue(formData.service_extra_minutes, index, id, 0)));

            return addMinutesToDateTimeLocal(serviceStart, baseDuration + extraMinutes + Number(service.buffer_minutes || 0));
        }).filter(Boolean);
        if (serviceEnds.length === 0) return '';

        let endStr = serviceEnds.reduce((latest, value) => (dateTimeLocalCompare(value, latest) > 0 ? value : latest), serviceEnds[0]);
        endStr = clampDateTimeLocalToSalon(endStr, bookingRules, slotIntervalMinutes);
        if (startValue && dateTimeLocalCompare(endStr, startValue) < 0) {
            endStr = clampDateTimeLocalToSalon(startValue, bookingRules, slotIntervalMinutes);
        }

        return capSuggestedEndToStaffShift(startValue, endStr, formData);
    };

    const handleCreateServiceChange = (nextIds) => {
        createForm.clearErrors('service_id', 'service_ids', 'staff_profile_id', 'staff_assignments');
        const startVal = createStartRef.current?.value || createForm.data.scheduled_start || '';
        createForm.setData((prev) => {
            const nextServiceStarts = normalizeServiceStarts(nextIds, prev.service_starts, startVal);
            const nextData = {
                ...prev,
                ...(() => {
                    const nextAssignments = rekeyServiceMapByLines(nextIds, filterServiceMap(nextIds, prev.staff_assignments));
                    return {
                        staff_assignments: nextAssignments,
                        staff_profile_id: hasAssignmentsForAllServices(nextIds, nextAssignments) ? '' : prev.staff_profile_id,
                    };
                })(),
                service_quantities: normalizeServiceQuantities(nextIds, prev.service_quantities),
                service_starts: nextServiceStarts,
                service_durations: rekeyServiceMapByLines(nextIds, filterServiceMap(nextIds, prev.service_durations)),
                service_extra_minutes: rekeyServiceMapByLines(nextIds, filterServiceMap(nextIds, prev.service_extra_minutes)),
                service_unit_prices: rekeyServiceMapByLines(nextIds, filterServiceMap(nextIds, prev.service_unit_prices)),
                service_discount_amounts: rekeyServiceMapByLines(nextIds, filterServiceMap(nextIds, prev.service_discount_amounts)),
                package_service_ids: (prev.package_service_ids || []).filter((serviceId) => nextIds.includes(String(serviceId))),
                service_ids: nextIds,
                service_id: nextIds[0] || '',
            };

            return {
                ...nextData,
                scheduled_end: !createEndManuallySet || !prev.scheduled_end
                    ? calculateSuggestedEndWithServiceMeta(startVal, nextIds, nextData)
                    : prev.scheduled_end,
            };
        });
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
        const start = editStartRef.current?.value || editForm.data.scheduled_start || '';
        let v = value;
        if (start && start.startsWith(`${d}T`) && dateTimeLocalCompare(v, start) < 0) v = start;
        editForm.setData('scheduled_end', v);
    };

    const handleEditServiceChange = (nextIds) => {
        editForm.clearErrors('service_id', 'service_ids', 'staff_profile_id', 'staff_assignments');
        const startVal = editStartRef.current?.value || editForm.data.scheduled_start || '';
        editForm.setData((prev) => {
            const nextServiceStarts = normalizeServiceStarts(nextIds, prev.service_starts, startVal);
            const nextData = {
                ...prev,
                ...(() => {
                    const nextAssignments = rekeyServiceMapByLines(nextIds, filterServiceMap(nextIds, prev.staff_assignments));
                    return {
                        staff_assignments: nextAssignments,
                        staff_profile_id: hasAssignmentsForAllServices(nextIds, nextAssignments) ? '' : prev.staff_profile_id,
                    };
                })(),
                service_quantities: normalizeServiceQuantities(nextIds, prev.service_quantities),
                service_starts: nextServiceStarts,
                service_durations: rekeyServiceMapByLines(nextIds, filterServiceMap(nextIds, prev.service_durations)),
                service_extra_minutes: rekeyServiceMapByLines(nextIds, filterServiceMap(nextIds, prev.service_extra_minutes)),
                service_unit_prices: rekeyServiceMapByLines(nextIds, filterServiceMap(nextIds, prev.service_unit_prices)),
                service_discount_amounts: rekeyServiceMapByLines(nextIds, filterServiceMap(nextIds, prev.service_discount_amounts)),
                package_service_ids: (prev.package_service_ids || []).filter((serviceId) => nextIds.includes(String(serviceId))),
                service_ids: nextIds,
                service_id: nextIds[0] || '',
            };

            return {
                ...nextData,
                scheduled_end: !editEndManuallySet || !prev.scheduled_end
                    ? calculateSuggestedEndWithServiceMeta(startVal, nextIds, nextData)
                    : prev.scheduled_end,
            };
        });
    };

    const syncCreateStartFromInput = (rawValue) => {
        const [ymd] = (rawValue || '').split('T');
        if (ymd) setCreateStartYmd(ymd);
        const clamped = clampStaffStartDatetimeLocal(rawValue || '', bookingRules, slotIntervalMinutes);
        createForm.setData((prev) => {
            const previousStart = prev.scheduled_start || '';
            const nextServiceStarts = {};
            (prev.service_ids || []).forEach((serviceId, index) => {
                const lineKey = serviceLineKey(index);
                const currentStart = serviceLineMapValue(prev.service_starts, index, serviceId, previousStart);
                nextServiceStarts[lineKey] = !currentStart || currentStart === previousStart ? clamped : currentStart;
            });
            const nextData = {
                ...prev,
                service_starts: nextServiceStarts,
                scheduled_start: clamped,
            };

            return {
                ...nextData,
                scheduled_end: !createEndManuallySet || !prev.scheduled_end
                    ? calculateSuggestedEndWithServiceMeta(clamped, prev.service_ids, nextData)
                    : prev.scheduled_end,
            };
        });
        if (createStartRef.current && createStartRef.current.value !== clamped) {
            createStartRef.current.value = clamped;
        }
    };

    const syncEditStartFromInput = (rawValue) => {
        const [ymd] = (rawValue || '').split('T');
        if (ymd) setEditStartYmd(ymd);
        const nextStart = rawValue || '';
        editForm.setData((prev) => {
            const previousStart = prev.scheduled_start || '';
            const nextServiceStarts = {};
            (prev.service_ids || []).forEach((serviceId, index) => {
                const lineKey = serviceLineKey(index);
                const currentStart = serviceLineMapValue(prev.service_starts, index, serviceId, previousStart);
                nextServiceStarts[lineKey] = index === 0 || !currentStart || currentStart === previousStart ? nextStart : currentStart;
            });
            const nextData = {
                ...prev,
                service_starts: nextServiceStarts,
                scheduled_start: nextStart,
            };

            return {
                ...nextData,
                scheduled_end: !editEndManuallySet || !prev.scheduled_end
                    ? calculateSuggestedEndWithServiceMeta(nextStart, prev.service_ids, nextData)
                    : prev.scheduled_end,
            };
        });
        if (editStartRef.current && editStartRef.current.value !== nextStart) {
            editStartRef.current.value = nextStart;
        }
    };

    const buildEditTimingPayload = (rawValue) => {
        const nextStart = rawValue || '';
        const previousStart = editForm.data.scheduled_start || '';
        const nextServiceStarts = {};
        (editForm.data.service_ids || []).forEach((serviceId, index) => {
            const lineKey = serviceLineKey(index);
            const currentStart = serviceLineMapValue(editForm.data.service_starts, index, serviceId, previousStart);
            nextServiceStarts[lineKey] = index === 0 || !currentStart || currentStart === previousStart ? nextStart : currentStart;
        });
        const nextData = {
            ...editForm.data,
            service_starts: nextServiceStarts,
            scheduled_start: nextStart,
        };

        return {
            ...nextData,
            scheduled_end: editForm.data.scheduled_end || calculateSuggestedEndWithServiceMeta(nextStart, nextData.service_ids, nextData),
        };
    };

    const applyCustomerToCreateForm = (customer) => {
        createForm.setData({
            ...createForm.data,
            customer_id: customer?.id ? String(customer.id) : '',
            customer_name: customer?.name ?? '',
            customer_phone: customer?.phone ?? '',
            customer_email: customer?.email ?? '',
            customer_package_id: '',
            package_service_ids: [],
        });
        setCreateSelectedPackageId('');
    };

    const startCalendarNewClient = () => {
        setCreateCustomerMode('new');
        setCreateSelectedCustomerId('');
        setCreateCustomerSearch('');
        applyCustomerToCreateForm(null);
        window.requestAnimationFrame(() => {
            calendarClientNameRef.current?.scrollIntoView({ block: 'center', behavior: 'smooth' });
            calendarClientNameRef.current?.focus();
        });
    };

    const applyCustomerToEditForm = (customer) => {
        editForm.setData({
            ...editForm.data,
            customer_id: customer?.id ? String(customer.id) : '',
            customer_name: customer?.name ?? '',
            customer_phone: customer?.phone ?? '',
            customer_email: customer?.email ?? '',
            customer_package_id: '',
            package_service_ids: [],
        });
        setEditSelectedPackageId('');
    };

    const calendarSlotToDateTimeLocal = (minutes) => {
        return minutesToDateTimeLocal(boardDate, minutes);
    };

    const openCalendarQuickAction = (staffId, minutes, staffIndex = 0) => {
        const start = calendarSlotToDateTimeLocal(minutes);
        const end = calendarSlotToDateTimeLocal(minutes + Math.max(15, slotIntervalMinutes || 30));
        setBoardStaffMenu(null);
        setCalendarQuickAction({
            staffId: staffId ? String(staffId) : '',
            staffIndex,
            minutes,
            startsAt: start,
            endsAt: end,
        });
        setCalendarDrawer(null);
        setCalendarAppointmentId(null);
    };

    const seedCreateFromCalendar = (quickAction = calendarQuickAction, groupMode = false) => {
        if (!quickAction) return;

        setCreateCustomerMode('existing');
        setCreateSelectedCustomerId('');
        setCreateSelectedPackageId('');
        setCreateCustomerSearch('');
        setCreateServiceSearch('');
        setCreateEndManuallySet(false);
        setCreateStartYmd((quickAction.startsAt || '').split('T')[0] || boardDate);
        setCreateStartMount((m) => m + 1);
        createForm.clearErrors();
        createForm.setData({
            customer_id: '',
            customer_name: groupMode ? 'Group appointment' : '',
            customer_phone: '',
            customer_email: '',
            service_id: '',
            service_ids: [],
            service_quantities: {},
            service_starts: {},
            service_durations: {},
            service_extra_minutes: {},
            service_unit_prices: {},
            service_discount_amounts: {},
            customer_package_id: '',
            package_service_ids: [],
            staff_profile_id: quickAction.staffId || '',
            staff_assignments: {},
            scheduled_start: quickAction.startsAt,
            scheduled_end: '',
            status: 'confirmed',
            notes: groupMode ? 'Group appointment' : '',
        });
        setCalendarServiceEditorId('');
        setCalendarDrawer(groupMode ? 'group' : 'appointment');
        setCalendarQuickAction(null);
    };

    const updateCreateServiceMeta = (serviceOrId, updates) => {
        const sid = String(serviceOrId?.id ?? serviceOrId);
        const lineIndex = Number.isInteger(serviceOrId?.lineIndex)
            ? serviceOrId.lineIndex
            : createSelectedServices.findIndex((id) => String(id) === sid);
        const lineKey = serviceOrId?.lineKey || serviceLineKey(Math.max(0, lineIndex));
        createForm.setData((prev) => {
            const nextData = {
                ...prev,
                service_starts: { ...(prev.service_starts || {}) },
                service_durations: { ...(prev.service_durations || {}) },
                service_extra_minutes: { ...(prev.service_extra_minutes || {}) },
                service_unit_prices: { ...(prev.service_unit_prices || {}) },
                service_discount_amounts: { ...(prev.service_discount_amounts || {}) },
                service_quantities: { ...(prev.service_quantities || {}) },
                staff_assignments: { ...(prev.staff_assignments || {}) },
            };

            if (updates.start !== undefined) {
                nextData.service_starts[lineKey] = updates.start;
                if (lineIndex === 0) {
                    nextData.scheduled_start = updates.start;
                }
            }
            if (updates.duration !== undefined) nextData.service_durations[lineKey] = Math.max(1, Number(updates.duration || 1));
            if (updates.extra !== undefined) nextData.service_extra_minutes[lineKey] = Math.max(0, Number(updates.extra || 0));
            if (updates.unitPrice !== undefined) nextData.service_unit_prices[lineKey] = Math.max(0, Number(updates.unitPrice || 0));
            if (updates.discountAmount !== undefined) nextData.service_discount_amounts[lineKey] = Math.max(0, Number(updates.discountAmount || 0));
            if (updates.quantity !== undefined) nextData.service_quantities[lineKey] = Math.max(1, Number(updates.quantity || 1));
            if (updates.staffId !== undefined) {
                if (updates.staffId) {
                    nextData.staff_assignments[lineKey] = updates.staffId;
                } else {
                    delete nextData.staff_assignments[lineKey];
                }
                nextData.staff_profile_id = prev.service_ids?.length === 1 ? (updates.staffId || '') : (hasAssignmentsForAllServices(prev.service_ids || [], nextData.staff_assignments) ? '' : prev.staff_profile_id);
            }

            nextData.scheduled_end = calculateSuggestedEndWithServiceMeta(nextData.scheduled_start, nextData.service_ids, nextData);

            return nextData;
        });
    };

    const seedBlockedTimeFromCalendar = (quickAction = calendarQuickAction) => {
        if (!quickAction) return;

        blockForm.clearErrors();
        blockForm.setData({
            staff_profile_id: quickAction.staffId || '',
            title: 'Blocked time',
            starts_at: quickAction.startsAt,
            ends_at: quickAction.endsAt,
            notes: '',
        });
        setCalendarDrawer('blocked');
        setCalendarQuickAction(null);
    };

    const seedTimeOffFromCalendar = (quickAction = calendarQuickAction) => {
        if (!quickAction) return;

        blockForm.clearErrors();
        blockForm.setData({
            staff_profile_id: quickAction.staffId || '',
            title: 'Time off',
            starts_at: quickAction.startsAt,
            ends_at: quickAction.endsAt,
            notes: 'Staff time off',
        });
        setCalendarDrawer('blocked');
        setCalendarQuickAction(null);
    };

    const startEdit = (appt) => {
        const startStr = toDateTimeLocal(appt.scheduled_start);
        const serviceRows = (Array.isArray(appt.grouped_services) && appt.grouped_services.length > 0)
            ? appt.grouped_services
            : (appt.service_id ? [{
                service_id: appt.service_id,
                quantity: appt.service_quantity || 1,
                staff_profile_id: appt.staff_profile_id || '',
                customer_package_id: appt.customer_package_id || '',
            }] : []);
        const selectedServiceIds = serviceRows
            .map((row) => String(row.service_id || ''))
            .filter(Boolean);
        const selectedServiceQuantities = Object.fromEntries(serviceRows
            .filter((row) => row.service_id)
            .map((row, index) => [serviceLineKey(index), String(row?.quantity || 1)]));
        const selectedServiceStarts = Object.fromEntries(serviceRows
            .filter((row) => row.service_id && row.scheduled_start)
            .map((row, index) => [serviceLineKey(index), toDateTimeLocal(row.scheduled_start)]));
        const selectedServiceDurations = Object.fromEntries(serviceRows
            .filter((row) => row.service_id && row.service_duration_minutes)
            .map((row, index) => [serviceLineKey(index), String(row.service_duration_minutes)]));
        const selectedServiceExtraMinutes = Object.fromEntries(serviceRows
            .filter((row) => row.service_id && Number(row.service_extra_minutes || 0) > 0)
            .map((row, index) => [serviceLineKey(index), String(row.service_extra_minutes)]));
        const selectedServiceUnitPrices = Object.fromEntries(serviceRows
            .filter((row) => row.service_id && row.service_unit_price !== null && row.service_unit_price !== undefined)
            .map((row, index) => [serviceLineKey(index), String(row.service_unit_price)]));
        const selectedServiceDiscountAmounts = Object.fromEntries(serviceRows
            .filter((row) => row.service_id && Number(row.service_discount_amount || 0) > 0)
            .map((row, index) => [serviceLineKey(index), String(row.service_discount_amount)]));
        const staffAssignments = Object.fromEntries(serviceRows
            .filter((row) => row.service_id && row.staff_profile_id)
            .map((row, index) => [serviceLineKey(index), String(row.staff_profile_id)]));
        const selectedPackageId = String(appt.customer_package_id || serviceRows.find((row) => row.customer_package_id)?.customer_package_id || '');
        setEditStartYmd(startStr.split('T')[0] || salonTodayYmd());
        setEditingId(appt.id);
        setEditCustomerMode(appt.customer_id ? 'existing' : 'new');
        setEditSelectedCustomerId(appt.customer_id ? String(appt.customer_id) : '');
        setEditSelectedPackageId(selectedPackageId);
        setEditServiceSearch('');
        setEditEndManuallySet(Boolean(appt.scheduled_end));
        setEditStartMountKey((k) => k + 1);
        editForm.setData({
            customer_id: appt.customer_id ? String(appt.customer_id) : '',
            customer_name: appt.customer_name || '',
            customer_phone: appt.customer_phone || '',
            customer_email: appt.customer_email || '',
            service_id: selectedServiceIds[0] || '',
            service_ids: selectedServiceIds,
            service_quantities: selectedServiceQuantities,
            service_starts: selectedServiceStarts,
            service_durations: selectedServiceDurations,
            service_extra_minutes: selectedServiceExtraMinutes,
            service_unit_prices: selectedServiceUnitPrices,
            service_discount_amounts: selectedServiceDiscountAmounts,
            customer_package_id: selectedPackageId,
            package_service_ids: serviceRows
                .filter((row) => row.customer_package_id && row.service_id)
                .map((row) => String(row.service_id)),
            staff_profile_id: selectedServiceIds.length === 1
                ? String(serviceRows.find((row) => String(row.service_id || '') === selectedServiceIds[0])?.staff_profile_id || appt.staff_profile_id || '')
                : (hasAssignmentsForAllServices(selectedServiceIds, staffAssignments) ? '' : String(appt.staff_profile_id || '')),
            staff_assignments: staffAssignments,
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

    const openCompleteService = (appt, preferredCheckoutFlow = null) => {
        const visitServices = Array.isArray(appt.grouped_services) && appt.grouped_services.length > 0
            ? appt.grouped_services
            : [{ id: appt.id, name: appt.service_name, status: appt.status, quantity: appt.service_quantity || 1 }];
        const selectableServiceIds = visitServices
            .filter((service) => COMPLETABLE_SERVICE_STATUSES.includes(service.status))
            .map((service) => String(service.id));

        setCompleteServiceId(appt.id);
        setStartServiceId(null);
        setCheckoutFlow(preferredCheckoutFlow || (canCheckout ? 'draft' : 'skip'));
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
            complete_visit_service_ids: selectableServiceIds.length > 0 ? selectableServiceIds : [String(appt.id)],
            additional_services: [],
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
    const customerOptions = customers.map((c) => ({ value: String(c.id), label: `${c.name}${c.phone ? ` - ${c.phone}` : ''}` }));
    const normalizedStaffProfiles = useMemo(() => {
        const byName = new Map();

        staffProfiles
            .filter((staff) => staff?.id && String(staff?.name || '').trim() !== '')
            .forEach((staff) => {
                const key = String(staff.name || '').trim().toLowerCase();
                const existing = byName.get(key);

                if (!existing) {
                    byName.set(key, staff);
                    return;
                }

                const existingIsStaff = existing?.role_name === 'staff';
                const nextIsStaff = staff?.role_name === 'staff';

                if (nextIsStaff && !existingIsStaff) {
                    byName.set(key, staff);
                    return;
                }

                if (nextIsStaff === existingIsStaff) {
                    const existingCode = String(existing?.employee_code || '');
                    const nextCode = String(staff?.employee_code || '');

                    if (nextCode > existingCode) {
                        byName.set(key, staff);
                    }
                }
            });

        return Array.from(byName.values());
    }, [staffProfiles]);
    const staffOptions = [{ value: '', label: 'Auto / Unassigned' }, ...normalizedStaffProfiles.map((s) => ({ value: String(s.id), label: s.name }))];
    const serviceOptions = services.map((service) => ({
        value: String(service.id),
        label: `${service.name} - ${formatMoney(service.price, currencyCode)}`,
    }));
    const createAvailablePackages = createSelectedCustomer?.active_packages || [];
    const editAvailablePackages = editSelectedCustomer?.active_packages || [];
    const createSelectedPackage = createAvailablePackages.find((pkg) => String(pkg.id) === String(createSelectedPackageId)) || null;
    const editSelectedPackage = editAvailablePackages.find((pkg) => String(pkg.id) === String(editSelectedPackageId)) || null;
    const createPackageOptions = createAvailablePackages.map((pkg) => ({ value: String(pkg.id), label: `${pkg.package_name}${pkg.expires_at ? ` - expires ${new Date(pkg.expires_at).toLocaleDateString()}` : ''}` }));
    const editPackageOptions = editAvailablePackages.map((pkg) => ({ value: String(pkg.id), label: pkg.package_name }));
    const createPackageCoverageMap = Object.fromEntries((createSelectedPackage?.services || []).map((service) => [String(service.id), service]));
    const editPackageCoverageMap = Object.fromEntries((editSelectedPackage?.services || []).map((service) => [String(service.id), service]));
    const createCoveredServiceIds = createForm.data.package_service_ids || [];
    const editCoveredServiceIds = editForm.data.package_service_ids || [];
    const createAvailableServices = services;
    const editAvailableServices = services;
    const createFilteredCustomers = customers.filter((customer) => {
        const haystack = `${customer.name || ''} ${customer.phone || ''} ${customer.email || ''}`.toLowerCase();
        return haystack.includes(createCustomerSearch.trim().toLowerCase());
    });
    const createFilteredServices = createAvailableServices.filter((s) => serviceMatchesSearch(s, createServiceSearch));
    const editFilteredServices = editAvailableServices.filter((s) => serviceMatchesSearch(s, editServiceSearch));
    const createEstimatedServicesTotal = estimateSelectedServicesTotal(createSelectedServices, createForm.data.service_quantities, services, createCoveredServiceIds);
    const editEstimatedServicesTotal = estimateSelectedServicesTotal(editSelectedServices, editForm.data.service_quantities, services, editCoveredServiceIds);
    const createSelectedServiceRows = createSelectedServices
        .map((id, index) => {
            const service = services.find((item) => String(item.id) === String(id));
            return service ? { ...service, lineKey: serviceLineKey(index), lineIndex: index } : null;
        })
        .filter(Boolean);
    const calendarServiceEditor = createSelectedServiceRows.find((service) => String(service.lineKey) === String(calendarServiceEditorId)) || null;
    const serviceDurationOptions = [15, 30, 45, 60, 75, 90, 105, 120, 150, 180, 210, 240];
    const getCreateServiceMeta = (service) => {
        const sid = String(service.id);
        const lineIndex = Number.isInteger(service.lineIndex) ? service.lineIndex : createSelectedServices.findIndex((id) => String(id) === sid);
        const lineKey = service.lineKey || serviceLineKey(Math.max(0, lineIndex));
        const quantity = Math.max(1, Number(serviceLineMapValue(createForm.data.service_quantities, lineIndex, sid, 1)));
        const unitPrice = Number(serviceLineMapValue(createForm.data.service_unit_prices, lineIndex, sid, service.price ?? 0));
        const discountAmount = Math.max(0, Number(serviceLineMapValue(createForm.data.service_discount_amounts, lineIndex, sid, 0)));
        const durationMinutes = Math.max(1, Number(serviceLineMapValue(createForm.data.service_durations, lineIndex, sid, service.duration_minutes || 0)));
        const extraMinutes = Math.max(0, Number(serviceLineMapValue(createForm.data.service_extra_minutes, lineIndex, sid, 0)));
        const staffId = String(serviceLineMapValue(createForm.data.staff_assignments, lineIndex, sid, createSelectedServices.length === 1 ? createForm.data.staff_profile_id : '') || '');
        const staff = normalizedStaffProfiles.find((item) => String(item.id) === staffId);
        const packageCovered = Boolean(createSelectedPackage && (createForm.data.package_service_ids || []).map(String).includes(sid));

        return {
            sid,
            lineIndex,
            lineKey,
            quantity,
            unitPrice,
            discountAmount,
            durationMinutes,
            extraMinutes,
            staffId,
            staffName: staff?.name || 'Auto / Unassigned',
            packageCovered,
            packageCoverage: createPackageCoverageMap[sid] || null,
            lineTotal: packageCovered ? 0 : Math.max(0, (unitPrice * quantity) - discountAmount),
        };
    };
    const createDrawerServicesSubtotal = createSelectedServiceRows.reduce((sum, service) => sum + getCreateServiceMeta(service).lineTotal, 0);
    const getCreateServiceSequenceStart = (serviceOrId) => {
        const sid = String(serviceOrId?.id ?? serviceOrId);
        const targetIndex = Number.isInteger(serviceOrId?.lineIndex)
            ? serviceOrId.lineIndex
            : createSelectedServices.findIndex((id) => String(id) === sid);
        const explicitStart = serviceLineMapValue(createForm.data.service_starts, targetIndex, sid);
        if (explicitStart) return explicitStart;

        return createForm.data.scheduled_start || '';
    };
    const createCustomerHasGiftCards = (createSelectedCustomer?.active_gift_cards || []).length > 0
        && Number(createSelectedCustomer?.gift_card_balance || 0) > 0;
    const editCustomerHasGiftCards = (editSelectedCustomer?.active_gift_cards || []).length > 0
        && Number(editSelectedCustomer?.gift_card_balance || 0) > 0;
    const createCustomerGiftBalance = Number(createSelectedCustomer?.gift_card_balance || 0);
    const editCustomerGiftBalance = Number(editSelectedCustomer?.gift_card_balance || 0);
    const createGiftCardShortfall = Math.max(0, createEstimatedServicesTotal - createCustomerGiftBalance);
    const editGiftCardShortfall = Math.max(0, editEstimatedServicesTotal - editCustomerGiftBalance);
    const createStartForAvailability = createStartRef.current?.value || createForm.data.scheduled_start || '';
    const createEndForAvailability = createForm.data.scheduled_end || calculateSuggestedEndWithServiceMeta(createStartForAvailability, createSelectedServices, createForm.data);
    const editStartForAvailability = editStartRef.current?.value || editForm.data.scheduled_start || '';
    const editEndForAvailability = editForm.data.scheduled_end || calculateSuggestedEndWithServiceMeta(editStartForAvailability, editSelectedServices, editForm.data);

    const buildStaffAvailabilityMap = () => {
        return Object.fromEntries(normalizedStaffProfiles.map((staff) => {
            return [String(staff.id), {
                busy: false,
                label: 'Available',
            }];
        }));
    };

    const createFallbackStaffAvailability = buildStaffAvailabilityMap(createStartForAvailability, createEndForAvailability);
    const editFallbackStaffAvailability = buildStaffAvailabilityMap(editStartForAvailability, editEndForAvailability, editingId);

    useEffect(() => {
        const startValue = createStartRef.current?.value || createForm.data.scheduled_start || '';
        const endValue = createForm.data.scheduled_end || calculateSuggestedEndWithServiceMeta(startValue, createSelectedServices, createForm.data);
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
    }, [createForm.data.scheduled_start, createForm.data.scheduled_end, createForm.data.service_durations, createForm.data.service_extra_minutes, createSelectedServices, createStartMount]);

    useEffect(() => {
        const startValue = editStartRef.current?.value || editForm.data.scheduled_start || '';
        const endValue = editForm.data.scheduled_end || calculateSuggestedEndWithServiceMeta(startValue, editSelectedServices, editForm.data);
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
    const updateAdditionalServiceRow = (index, field, value) => {
        completeForm.setData('additional_services', (completeForm.data.additional_services || []).map((row, rowIndex) => rowIndex === index ? { ...row, [field]: value } : row));
    };
    const addAdditionalServiceRow = () => completeForm.setData('additional_services', [...(completeForm.data.additional_services || []), { service_id: '', staff_profile_id: '', quantity: 1 }]);
    const removeAdditionalServiceRow = (index) => completeForm.setData('additional_services', (completeForm.data.additional_services || []).filter((_, rowIndex) => rowIndex !== index));
    const toggleCompleteVisitService = (serviceId) => {
        const id = String(serviceId);
        const selectedIds = (completeForm.data.complete_visit_service_ids || []).map((value) => String(value));
        const nextIds = selectedIds.includes(id)
            ? selectedIds.filter((value) => value !== id)
            : [...selectedIds, id];

        completeForm.setData('complete_visit_service_ids', nextIds);
    };

    const createSalonBounds = adminStartBoundsForYmd(createStartYmd, bookingRules);
    const editSalonBounds = adminEditStartBoundsForYmd(editStartYmd, bookingRules);
    const editEndYmd = (editForm.data.scheduled_end || editForm.data.scheduled_start || '').split('T')[0] || editStartYmd;
    const editEndSalonBounds = salonSelectableBoundsForYmd(editEndYmd, bookingRules, slotIntervalMinutes);
    const editingAppt = appointments.find((a) => String(a.id) === String(editingId));
    const editStartDefault = editingAppt ? toDateTimeLocal(editingAppt.scheduled_start) : (editForm.data.scheduled_start || '');
    const completingAppt = appointments.find((a) => String(a.id) === String(completeServiceId));
    const completingCustomer = customers.find((customer) => String(customer.id) === String(completingAppt?.customer_id));
    const completingVisitServiceRows = completingAppt
        ? (Array.isArray(completingAppt.grouped_services) && completingAppt.grouped_services.length > 0
            ? completingAppt.grouped_services
            : [{
                id: completingAppt.id,
                service_id: completingAppt.service_id,
                name: completingAppt.service_name,
                quantity: completingAppt.service_quantity || 1,
                service_unit_price: completingAppt.service_unit_price,
                service_discount_amount: completingAppt.service_discount_amount || 0,
                status: completingAppt.status,
                staff_name: completingAppt.staff_name || 'Unassigned',
                customer_package_id: completingAppt.customer_package_id,
            }])
        : [];
    const selectedCompletionServiceIds = (completeForm.data.complete_visit_service_ids || []).map((id) => String(id));
    const selectedCompletionServiceRows = completingVisitServiceRows.filter((service) => selectedCompletionServiceIds.includes(String(service.id)));
    const completingServiceAmount = selectedCompletionServiceRows.reduce((sum, row) => {
        const service = services.find((item) => String(item.id) === String(row.service_id));
        const quantity = Math.max(1, Number(row.quantity || 1));
        const unitPrice = Number(row.service_unit_price ?? service?.price ?? 0);
        const discountAmount = Number(row.service_discount_amount || 0);

        return sum + (row.customer_package_id ? 0 : Math.max(0, (unitPrice * quantity) - discountAmount));
    }, 0);
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
    const selectedAdditionalServiceLines = (completeForm.data.additional_services || [])
        .map((row) => {
            const service = services.find((item) => String(item.id) === String(row.service_id));
            const staff = normalizedStaffProfiles.find((item) => String(item.id) === String(row.staff_profile_id));
            const quantity = Math.max(1, Number(row.quantity || 1));
            const unitPrice = Number(service?.price || 0);
            const lineTotal = quantity * unitPrice;

            return {
                service_id: row.service_id,
                label: service ? service.name : 'Unknown service',
                staffName: staff?.name || 'Same staff',
                quantity,
                unitPrice,
                lineTotal,
            };
        })
        .filter((line) => String(line.service_id || '') !== '');
    const selectedAdditionalServicesAmount = selectedAdditionalServiceLines.reduce((sum, line) => sum + line.lineTotal, 0);
    const previewTotalAmount = completingServiceAmount + selectedProductsAmount + selectedAdditionalServicesAmount;
    const completingCustomerHasGiftCards = (completingCustomer?.active_gift_cards || []).length > 0
        && Number(completingCustomer?.gift_card_balance || 0) > 0;
    const completingCustomerGiftBalance = Number(completingCustomer?.gift_card_balance || 0);
    const completingGiftCardShortfall = completingCustomerHasGiftCards
        ? Math.max(0, previewTotalAmount - completingCustomerGiftBalance)
        : 0;
    const boardOpen = salonClockBoundary(bookingRules, 'opening_time', '09:00');
    const boardClose = salonClockBoundary(bookingRules, 'closing_time', '22:00');
    const boardStartMinutes = boardOpen.h * 60 + boardOpen.m;
    const boardEndMinutes = Math.max(boardStartMinutes + 60, boardClose.h * 60 + boardClose.m);
    const boardTotalMinutes = Math.max(60, boardEndMinutes - boardStartMinutes);
    const boardHourMarks = Array.from({ length: Math.ceil(boardTotalMinutes / 60) + 1 }, (_, idx) => boardStartMinutes + (idx * 60));
    const boardSlotInterval = Math.max(15, Math.min(60, Number(slotIntervalMinutes || 30)));
    const boardSlotMarks = Array.from(
        { length: Math.ceil(boardTotalMinutes / boardSlotInterval) },
        (_, idx) => boardStartMinutes + (idx * boardSlotInterval),
    ).filter((minutes) => minutes < boardEndMinutes);
    const boardCanvasHeight = (boardHourMarks.length - 1) * 80;
    const boardStaffShortLabel = (staff) => {
        const initials = String(staff?.name || '')
            .trim()
            .split(/\s+/)
            .filter(Boolean)
            .map((part) => part[0])
            .join('')
            .slice(0, 2)
            .toUpperCase();

        return initials || (staff?.id ? `#${staff.id}` : '?');
    };
    const boardStaffProfiles = normalizedStaffProfiles;
    const boardStaffOptions = [{ value: '', label: 'Auto / Unassigned' }, ...boardStaffProfiles.map((s) => ({ value: String(s.id), label: s.name }))];
    const boardStaffList = boardStaffFilter === 'all'
        ? boardStaffProfiles
        : boardStaffProfiles.filter((staff) => String(staff.id) === String(boardStaffFilter));
    useEffect(() => {
        if (boardStaffFilter !== 'all' && !boardStaffProfiles.some((staff) => String(staff.id) === String(boardStaffFilter))) {
            setBoardStaffFilter('all');
        }
    }, [boardStaffFilter, boardStaffProfiles]);
    const boardScheduleMap = useMemo(() => Object.fromEntries((staffSchedules || [])
        .filter((schedule) => schedule.schedule_date)
        .map((schedule) => [`${schedule.staff_profile_id}-${schedule.schedule_date}`, schedule])), [staffSchedules]);
    const boardScheduleForStaff = (staffId) => boardScheduleMap[`${staffId}-${boardDate}`] || null;
    const boardStaffIsOff = (staffId) => Boolean(boardScheduleForStaff(staffId)?.is_day_off);
    const boardAppointments = appointments.filter((appt) => sameLocalDate(appt.scheduled_start, boardDate));
    const boardBlocks = (appointmentBlocks || []).filter((block) => sameLocalDate(block.starts_at, boardDate));
    const boardGlobalBlocks = boardBlocks.filter((block) => !block.staff_profile_id);
    const boardCardsByStaff = boardStaffList.map((staff) => {
        const cards = layoutOverlappingAppointments(boardAppointments
            .filter((appt) => String(appt.staff_profile_id || '') === String(staff.id))
            .map((appt) => {
                const startMinutes = dateTimeLocalMinutes(appt.scheduled_start);
                const rawEndMinutes = dateTimeLocalMinutes(appt.scheduled_end || appt.scheduled_start);
                if (Number.isNaN(startMinutes)) return null;
                const endMinutes = Math.max(startMinutes + 30, Number.isNaN(rawEndMinutes) ? startMinutes : rawEndMinutes);
                const top = Math.max(0, ((startMinutes - boardStartMinutes) / boardTotalMinutes) * 100);
                const height = Math.max(7, (((endMinutes) - startMinutes) / boardTotalMinutes) * 100);
                const isPaid = appt.status === 'completed' && appt.checkout_status === 'paid';
                const category = serviceCategoryMap[String(appt.service_id)] || `${appt.service_name || ''} Uncategorized`;

                return {
                    ...appt,
                    cardStyle: getAppointmentCardStyle(category, isPaid),
                    category,
                    endMinutes,
                    isPaid,
                    startMinutes,
                    top,
                    height,
                    timeLabel: `${formatMinutesAmPm(startMinutes)} - ${formatMinutesAmPm(endMinutes)}`,
                };
            }).filter(Boolean));
        const blocks = [...boardGlobalBlocks, ...boardBlocks.filter((block) => String(block.staff_profile_id || '') === String(staff.id))]
            .map((block) => {
                const startMinutes = dateTimeLocalMinutes(block.starts_at);
                const rawEndMinutes = dateTimeLocalMinutes(block.ends_at || block.starts_at);
                if (Number.isNaN(startMinutes)) return null;
                const endMinutes = Math.max(startMinutes + 15, Number.isNaN(rawEndMinutes) ? startMinutes : rawEndMinutes);
                const top = Math.max(0, ((startMinutes - boardStartMinutes) / boardTotalMinutes) * 100);
                const height = Math.max(4, ((endMinutes - startMinutes) / boardTotalMinutes) * 100);

                return {
                    ...block,
                    endMinutes,
                    startMinutes,
                    top,
                    height,
                    timeLabel: `${formatMinutesAmPm(startMinutes)} - ${formatMinutesAmPm(endMinutes)}`,
                };
            }).filter(Boolean);

        return { staff, cards, blocks };
    });
    const defaultBoardActionMinutes = () => {
        const todayYmd = salonTodayYmd();
        let minutes = boardStartMinutes;

        if (boardDate === todayYmd) {
            minutes = salonNowMinutes();
        }

        const snapped = Math.ceil(minutes / boardSlotInterval) * boardSlotInterval;

        return Math.max(boardStartMinutes, Math.min(boardEndMinutes - boardSlotInterval, snapped));
    };
    const quickActionForStaff = (staff, staffIndex) => {
        const minutes = defaultBoardActionMinutes();

        return {
            staffId: staff?.id ? String(staff.id) : '',
            staffIndex,
            minutes,
            startsAt: calendarSlotToDateTimeLocal(minutes),
            endsAt: calendarSlotToDateTimeLocal(minutes + Math.max(15, slotIntervalMinutes || 30)),
        };
    };
    const moveBoardAppointment = (appointmentId, staffId, minutes) => {
        if (!appointmentId || boardStaffIsOff(staffId)) return;

        setBoardMoveError('');
        router.patch(route('appointments.board-move', appointmentId), {
            staff_profile_id: staffId,
            scheduled_start: calendarSlotToDateTimeLocal(minutes),
        }, {
            preserveScroll: true,
            onError: (errors) => {
                setBoardMoveError(errors.staff_profile_id || errors.scheduled_start || errors.appointment || 'Could not move appointment.');
            },
            onFinish: () => setDraggingAppointmentId(null),
        });
    };
    const appointmentQueueSortDirection = ['today', 'upcoming'].includes(String(statusFilter || '')) ? 'asc' : 'desc';
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
        const rows = [...group.appointments].sort((a, b) => dateTimeLocalMs(a.scheduled_start) - dateTimeLocalMs(b.scheduled_start));
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
                service_id: row.service_id,
                name: row.service_name,
                quantity: row.service_quantity || 1,
                service_unit_price: row.service_unit_price,
                service_discount_amount: row.service_discount_amount || 0,
                service_duration_minutes: row.service_duration_minutes,
                service_extra_minutes: row.service_extra_minutes || 0,
                scheduled_start: row.scheduled_start,
                status: row.status,
                staff_profile_id: row.staff_profile_id,
                staff_name: row.staff_name || 'Unassigned',
                customer_package_id: row.customer_package_id,
            })),
            awaiting_checkout: rows.some((row) => row.awaiting_checkout),
            checkout_invoice_id: rows.find((row) => row.checkout_invoice_id)?.checkout_invoice_id || null,
            invoice_total: Math.max(...rows.map((row) => Number(row.invoice_total || 0))),
            invoice_amount_paid: Math.max(...rows.map((row) => Number(row.invoice_amount_paid || 0))),
            invoice_balance_due: Math.max(...rows.map((row) => Number(row.invoice_balance_due || 0))),
        };
    }).sort((a, b) => {
        const aTime = dateTimeLocalMs(a.scheduled_start);
        const bTime = dateTimeLocalMs(b.scheduled_start);
        const safeATime = Number.isNaN(aTime) ? 0 : aTime;
        const safeBTime = Number.isNaN(bTime) ? 0 : bTime;

        return appointmentQueueSortDirection === 'asc'
            ? safeATime - safeBTime
            : safeBTime - safeATime;
    });
    const selectedCalendarAppointment = calendarAppointmentId
        ? appointmentQueueRows.find((row) => String(row.id) === String(calendarAppointmentId) || (row.grouped_services || []).some((service) => String(service.id) === String(calendarAppointmentId)))
        : null;
    const selectedCalendarCompletableServices = selectedCalendarAppointment?.grouped_services?.filter((service) => COMPLETABLE_SERVICE_STATUSES.includes(service.status)) || [];
    const selectedCalendarCanFinish = selectedCalendarCompletableServices.length > 0;
    const openCalendarAppointmentDrawer = (appt) => {
        setCalendarQuickAction(null);
        setCalendarDrawer(null);
        setCalendarAppointmentId(appt.id);
    };

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
                {!isStaff ? <section className="ta-card p-3">
                    <div className="flex items-center gap-2">
                        <input ref={importFileRef} type="file" accept=".csv,text/csv" className="hidden" onChange={handleAppointmentsImport} />
                        <button type="button" className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700" onClick={() => importFileRef.current?.click()}>Import CSV</button>
                        <button type="button" className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700" onClick={() => { window.location.href = route('data-transfer.template', { entity: 'appointments' }); }}>Template CSV</button>
                        <button type="button" className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700" onClick={() => { window.location.href = route('data-transfer.export', { entity: 'appointments' }); }}>Export CSV</button>
                    </div>
                </section> : null}
                {!isStaff && flash?.created_tax_invoice_id ? (
                    <div className="ta-card flex flex-wrap items-center justify-between gap-3 border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-900">
                        <span>Tax invoice is ready for this visit — open it to adjust lines, issue the receipt, or record payment.</span>
                        <Link href={route('finance.invoices.show', flash.created_tax_invoice_id)} className="font-semibold text-indigo-700 underline">
                            Open invoice
                        </Link>
                    </div>
                ) : null}
                {!isStaff && canCheckout && appointments.some((a) => a.awaiting_checkout) ? (
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
                {!isStaff ? <section className="ta-card p-5">
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
                                createForm.setData((prev) => ({
                                    ...prev,
                                    scheduled_start: v,
                                    scheduled_end: createEndManuallySet && prev.scheduled_end
                                        ? prev.scheduled_end
                                        : calculateSuggestedEndWithServiceMeta(v, prev.service_ids, prev),
                                }));
                            });
                            createForm.post(route('appointments.store'), {
                                onSuccess: () => {
                                    createForm.reset();
                                    const next = clampStaffStartDatetimeLocal(defaultStart || '', bookingRules, slotIntervalMinutes);
                                    createForm.setData('scheduled_start', next);
                                    createForm.setData('service_ids', []);
                                    createForm.setData('service_id', '');
                                    setCreateServiceSearch('');
                                    setCreateStartYmd((defaultStart || '').split('T')[0] || salonTodayYmd());
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
                                <input type="radio" name="create_customer_mode" className="text-indigo-600" checked={createCustomerMode === 'new'} onChange={() => { setCreateCustomerMode('new'); setCreateSelectedCustomerId(''); applyCustomerToCreateForm(null); }} />
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
                            <label className="ta-field-label">{createCustomerMode === 'existing' ? 'Phone number' : 'Phone (optional)'}</label>
                            <input className="ta-input" value={createForm.data.customer_phone} onChange={(e) => createForm.setData('customer_phone', e.target.value)} disabled={createCustomerMode === 'existing' && !createSelectedCustomerId} />
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
                                        className="block w-full border-b border-l-4 border-b-slate-100 px-3 py-2 text-left text-xs text-slate-700 hover:bg-slate-50"
                                        style={{ borderLeftColor: getServiceAccentColor(s) }}
                                        onClick={() => handleCreateServiceChange([...createSelectedServices, String(s.id)])}
                                    >
                                        <div className="font-medium text-slate-700">{s.name}</div>
                                        <div className="mt-0.5 text-[11px] text-slate-500">{s.category || 'Uncategorized'} • {s.duration_minutes}m • <span className="font-bold text-slate-700">{formatMoney(s.price, currencyCode)}</span></div>
                                    </button>
                                ))}
                                {createFilteredServices.length === 0 ? <div className="px-3 py-2 text-xs text-slate-500">No more services found.</div> : null}
                            </div>
                            <div className="mt-2 flex flex-wrap gap-2">
                                {createSelectedServices.map((id, index) => {
                                    const s = services.find((x) => String(x.id) === String(id));
                                    if (!s) return null;
                                    const packageCoverage = createPackageCoverageMap[String(id)];
                                    const isCovered = (createForm.data.package_service_ids || []).includes(String(id));
                                    return (
                                        <div key={serviceLineKey(index)} className="flex items-center gap-2">
                                            <button type="button" className="rounded-full border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs text-indigo-700" onClick={() => handleCreateServiceChange(createSelectedServices.filter((_, selectedIndex) => selectedIndex !== index))}>
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
                                <label className="ta-field-label">Staff</label>
                                <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700">Hidden for multi-service bookings. Assign staff per service below.</div>
                            </div>
                        ) : (
                            <div>
                                <SearchableSelect label="Staff" value={createForm.data.staff_profile_id} onChange={(id) => createForm.setData('staff_profile_id', id)} options={staffOptions} placeholder="Search staff" />
                                <p className="mt-1 text-xs font-semibold text-slate-700">Select staff or leave auto / unassigned.</p>
                                {fieldError(createForm, 'staff_profile_id')}
                            </div>
                        )}
                        {createHasMultipleServices ? (
                            <div className="md:col-span-2 rounded-lg border border-slate-200 bg-slate-50/70 p-3">
                                <p className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-700">Staff Per Service</p>
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
                                                <SearchableSelect
                                                    value={createForm.data.staff_assignments?.[assignmentKey] || ''}
                                                    onChange={(id) => {
                                                        createForm.clearErrors('staff_profile_id', 'staff_assignments');
                                                        const nextAssignments = {
                                                            ...(createForm.data.staff_assignments || {}),
                                                            [assignmentKey]: id,
                                                        };
                                                        createForm.setData({
                                                            ...createForm.data,
                                                            staff_assignments: nextAssignments,
                                                            staff_profile_id: hasAssignmentsForAllServices(createSelectedServices, nextAssignments)
                                                                ? ''
                                                                : createForm.data.staff_profile_id,
                                                        });
                                                    }}
                                                    options={[{ value: '', label: 'Use default / auto' }, ...normalizedStaffProfiles.map((s) => {
                                                        const availability = createStaffAvailability[String(s.id)] || createFallbackStaffAvailability[String(s.id)];
                                                        return { value: String(s.id), label: `${s.name}${availability ? ` (${availability.label})` : ''}` };
                                                    })]}
                                                    placeholder="Search staff"
                                                />
                                            </div>
                                        );
                                    })}
                                </div>
                                <p className="mt-2 text-xs font-semibold text-slate-700">Staff marked busy already have an overlapping appointment in the current schedule view.</p>
                                {fieldError(createForm, 'staff_assignments')}
                            </div>
                        ) : null}
                        <div>
                            <label className="ta-field-label">Scheduled Start</label>
                            <p className="mb-1 text-xs font-semibold text-slate-700">Walk-ins can start from the current time; future bookings can be scheduled up to the booking horizon.</p>
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
                        <div><label className="ta-field-label">Status</label><select className="ta-input" value={createForm.data.status} onChange={(e) => createForm.setData('status', e.target.value)}><option value="confirmed">confirmed</option><option value="pending">pending</option></select>{fieldError(createForm, 'status')}</div>
                        <div className="md:col-span-4"><input className="ta-input" value={createForm.data.notes} onChange={(e) => createForm.setData('notes', e.target.value)} placeholder="Notes" />{fieldError(createForm, 'notes')}</div>
                        <button className="ta-btn-primary" disabled={createForm.processing}>Create</button>
                    </form>
                </section> : null}

                <section className="ta-card p-4">
                    <label className="ta-field-label mb-2 block">Filter Status</label>
                    <div className="flex flex-wrap gap-2">
                        {[
                            { value: '', label: 'All' },
                            { value: 'today', label: 'Today' },
                            { value: 'needs_pay', label: 'Needs Pay' },
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
                                                {!isStaff ? <button className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700" onClick={() => startEdit(a)}>Edit</button> : null}
                                                {['confirmed', 'in_progress'].includes(a.status) && (
                                                    <button className="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700" onClick={() => openCompleteService(a)}>
                                                        {canCheckout ? 'Finish / Pay' : 'Finish Service'}
                                                    </button>
                                                )}
                                                {!isStaff && a.status === 'completed' && a.awaiting_checkout ? (
                                                    a.checkout_invoice_id ? (
                                                        <Link
                                                            href={route('finance.invoices.show', a.checkout_invoice_id)}
                                                            className="inline-flex rounded-lg border border-amber-300 bg-white px-2.5 py-1 text-xs font-medium text-amber-900 hover:bg-amber-50"
                                                        >
                                                            Checkout
                                                        </Link>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            className="inline-flex rounded-lg border border-amber-300 bg-white px-2.5 py-1 text-xs font-medium text-amber-900 hover:bg-amber-50"
                                                            onClick={() => router.post(route('appointments.checkout', a.id))}
                                                        >
                                                            Checkout
                                                        </button>
                                                    )
                                                ) : null}
                                                {!isStaff ? (a.next_statuses || []).filter((next) => !['in_progress', 'completed'].includes(next)).map((next) => <button key={next} className="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50" onClick={() => transition(a.id, next)}>{statusLabels[next] || next}</button>) : null}
                                                {!isStaff ? <button
                                                    type="button"
                                                    className="rounded-lg border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-800 hover:bg-rose-100"
                                                    onClick={() => setDeleteAppointmentId(a.id)}
                                                >
                                                    Delete permanently
                                                </button> : null}
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

            <Modal show={Boolean(completeServiceId)} maxWidth="5xl" onClose={() => setCompleteServiceId(null)}>
                <div className="p-4 sm:p-6">
                    <h3 className="mb-4 text-base font-semibold text-slate-800">Complete Service for Appointment #{completeServiceId}</h3>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            completeForm.transform((data) => ({
                                ...data,
                                complete_visit_service_ids: (data.complete_visit_service_ids || []).map((id) => String(id)),
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
                        {completingVisitServiceRows.length > 1 ? (
                            <div className="md:col-span-2 rounded-xl border border-slate-200 p-4">
                                <div className="mb-3 flex items-center justify-between gap-3">
                                    <h4 className="text-sm font-semibold text-slate-700">Finish services</h4>
                                    <button
                                        type="button"
                                        className="rounded-lg border border-slate-200 px-3 py-1 text-xs font-medium text-slate-700"
                                        onClick={() => completeForm.setData('complete_visit_service_ids', completingVisitServiceRows
                                            .filter((service) => COMPLETABLE_SERVICE_STATUSES.includes(service.status))
                                            .map((service) => String(service.id)))}
                                    >
                                        Select all available
                                    </button>
                                </div>
                                <div className="space-y-2">
                                    {completingVisitServiceRows.map((service) => {
                                        const isCompletable = COMPLETABLE_SERVICE_STATUSES.includes(service.status);
                                        const checked = selectedCompletionServiceIds.includes(String(service.id));
                                        const quantity = Math.max(1, Number(service.quantity || 1));

                                        return (
                                            <label key={service.id} className={`flex items-start gap-3 rounded-lg border px-3 py-2 text-sm ${isCompletable ? 'cursor-pointer border-slate-200 bg-white' : 'border-slate-100 bg-slate-50 text-slate-400'}`}>
                                                <input
                                                    type="checkbox"
                                                    className="mt-1 rounded border-slate-300"
                                                    checked={checked}
                                                    disabled={!isCompletable}
                                                    onChange={() => toggleCompleteVisitService(service.id)}
                                                />
                                                <span className="min-w-0 flex-1">
                                                    <span className="block font-semibold text-slate-800">
                                                        {service.name || 'Service'}{quantity > 1 ? ` x ${quantity}` : ''}
                                                    </span>
                                                    <span className="mt-0.5 block text-xs text-slate-500">
                                                        {service.staff_name || 'Unassigned'} - {statusLabels[service.status] || service.status || 'Unknown'}
                                                    </span>
                                                </span>
                                            </label>
                                        );
                                    })}
                                </div>
                                {fieldError(completeForm, 'complete_visit_service_ids')}
                            </div>
                        ) : null}
                        <div className="md:col-span-2">
                            <label className="ta-field-label">Service Report (optional)</label>
                            <textarea className="ta-input min-h-[120px]" value={completeForm.data.service_report} onChange={(e) => completeForm.setData('service_report', e.target.value)} />
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
                            <p className="mt-1 text-xs text-slate-500">Gift card payments still use the full tax invoice total, including VAT. You can also link gift card usage to this visit from Loyalty → Consume Gift Card.</p>
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
                                            {selectedCompletionServiceRows.length > 0 ? (
                                                selectedCompletionServiceRows.map((row) => {
                                                    const service = services.find((item) => String(item.id) === String(row.service_id));
                                                    const quantity = Math.max(1, Number(row.quantity || 1));
                                                    const unitPrice = Number(row.service_unit_price ?? service?.price ?? 0);
                                                    const discountAmount = Number(row.service_discount_amount || 0);
                                                    const lineTotal = row.customer_package_id ? 0 : Math.max(0, (unitPrice * quantity) - discountAmount);

                                                    return (
                                                        <div key={row.id} className="flex items-center justify-between">
                                                            <span>
                                                                Service ({row.name || service?.name || 'Selected service'}{quantity > 1 ? ` x ${quantity}` : ''})
                                                                {row.customer_package_id ? ' - Package session' : ''}
                                                            </span>
                                                            <span className="font-medium">{formatMoney(lineTotal, currencyCode)}</span>
                                                        </div>
                                                    );
                                                })
                                            ) : (
                                                <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">
                                                    Select at least one service to finish.
                                                </div>
                                            )}
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
                                            {selectedAdditionalServiceLines.length > 0 ? (
                                                selectedAdditionalServiceLines.map((line, idx) => (
                                                    <div key={`${line.service_id}-${idx}`} className="mt-1 flex items-center justify-between text-xs text-slate-600">
                                                        <span>{line.label} x {line.quantity} - {line.staffName}</span>
                                                        <span>{formatMoney(line.lineTotal, currencyCode)}</span>
                                                    </div>
                                                ))
                                            ) : (
                                                <div className="mt-1 text-xs text-slate-500">No additional services selected.</div>
                                            )}
                                            <div className="mt-2 flex items-center justify-between border-t border-slate-200 pt-2 text-sm font-semibold text-slate-900">
                                                <span>Estimated total</span>
                                                <span>{formatMoney(previewTotalAmount, currencyCode)}</span>
                                            </div>
                                            {completingCustomer && completingCustomerHasGiftCards ? (
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
                                <h4 className="text-sm font-semibold text-slate-700">Additional Services</h4>
                                <button type="button" className="rounded-lg border border-slate-200 px-3 py-1 text-xs font-medium text-slate-700" onClick={addAdditionalServiceRow}>Add Service</button>
                            </div>
                            {(completeForm.data.additional_services || []).length === 0 ? (
                                <div className="text-xs text-slate-500">No additional services selected.</div>
                            ) : null}
                            {(completeForm.data.additional_services || []).map((row, index) => {
                                const selected = services.find((service) => String(service.id) === String(row.service_id));
                                const quantity = Math.max(1, Number(row.quantity || 1));
                                const unitPrice = Number(selected?.price || 0);

                                return (
                                    <div key={index} className="grid gap-3 rounded-xl border border-slate-100 bg-slate-50/60 p-3 lg:grid-cols-12">
                                        <div className="lg:col-span-5">
                                            <SearchableSelect
                                                label="Service"
                                                value={row.service_id}
                                                onChange={(id) => updateAdditionalServiceRow(index, 'service_id', id)}
                                                options={serviceOptions}
                                                placeholder="Search service"
                                            />
                                            {fieldError(completeForm, `additional_services.${index}.service_id`)}
                                        </div>
                                        <div className="lg:col-span-3">
                                            <SearchableSelect
                                                label="Staff"
                                                value={row.staff_profile_id}
                                                onChange={(id) => updateAdditionalServiceRow(index, 'staff_profile_id', id)}
                                                options={[{ value: '', label: 'Same staff' }, ...normalizedStaffProfiles.map((staff) => ({ value: String(staff.id), label: staff.name }))]}
                                                placeholder="Search staff"
                                            />
                                            {fieldError(completeForm, `additional_services.${index}.staff_profile_id`)}
                                        </div>
                                        <div className="grid gap-3 sm:grid-cols-2 lg:col-span-4 lg:grid-cols-1 xl:grid-cols-2">
                                            <div>
                                                <label className="ta-field-label">Qty</label>
                                                <input className="ta-input" type="number" min="1" value={row.quantity} onChange={(e) => updateAdditionalServiceRow(index, 'quantity', e.target.value)} />
                                                {fieldError(completeForm, `additional_services.${index}.quantity`)}
                                            </div>
                                            <div>
                                                <label className="ta-field-label">Amount</label>
                                                <div className="flex flex-col gap-2 sm:flex-row lg:flex-col xl:flex-row">
                                                    <div className="ta-input flex min-h-[38px] items-center whitespace-nowrap">{formatMoney(unitPrice * quantity, currencyCode)}</div>
                                                    <button type="button" className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-700" onClick={() => removeAdditionalServiceRow(index)}>Remove</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
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
                            <button className="ta-btn-primary" disabled={completeForm.processing || selectedCompletionServiceIds.length === 0}>
                                {checkoutFlow === 'pay' && canCheckout ? 'Finish & pay' : 'Finish service'}
                            </button>
                        </div>
                    </form>
                </div>
            </Modal>

            {!isStaff ? <Modal show={Boolean(editingId)} maxWidth="5xl" onClose={() => setEditingId(null)}>
                <div className="max-h-[90vh] overflow-auto bg-[#0f0f10] p-6 text-white [&_.ta-field-label]:mb-1 [&_.ta-field-label]:block [&_.ta-field-label]:text-xs [&_.ta-field-label]:font-bold [&_.ta-field-label]:uppercase [&_.ta-field-label]:text-slate-400 [&_.ta-input]:rounded-md [&_.ta-input]:border [&_.ta-input]:border-white/15 [&_.ta-input]:bg-[#18181a] [&_.ta-input]:px-3 [&_.ta-input]:py-3 [&_.ta-input]:text-sm [&_.ta-input]:text-white">
                    <div className="mb-5 flex items-start justify-between border-b border-white/10 pb-5">
                        <div>
                            <h3 className="text-2xl font-black text-white">Edit appointment #{editingId}</h3>
                            <p className="mt-1 text-sm text-slate-400">Update the client, services, staff, status, and scheduled time.</p>
                        </div>
                        <button type="button" className="text-2xl leading-none text-slate-300 hover:text-white" onClick={() => setEditingId(null)}>x</button>
                    </div>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            const nextData = buildEditTimingPayload(editStartRef.current?.value || editForm.data.scheduled_start || '');
                            const [ymd] = nextData.scheduled_start.split('T');
                            if (ymd) setEditStartYmd(ymd);
                            flushSync(() => editForm.setData(nextData));
                            editForm.clearErrors();
                            router.put(route('appointments.update', editingId), nextData, {
                                preserveScroll: true,
                                onStart: () => setEditSaving(true),
                                onError: (errors) => editForm.setError(errors),
                                onSuccess: () => {
                                    setEditingId(null);
                                    setEditCustomerMode('new');
                                    setEditSelectedCustomerId('');
                                    setEditSelectedPackageId('');
                                },
                                onFinish: () => setEditSaving(false),
                            });
                        }}
                        className="grid gap-5 md:grid-cols-2"
                    >
                        <div className="md:col-span-2 flex flex-wrap items-center gap-4 rounded-md border border-white/10 bg-white/[0.03] px-4 py-4">
                            <span className="text-xs font-bold uppercase text-slate-400">Customer type</span>
                            <label className="inline-flex cursor-pointer items-center gap-2 text-sm font-semibold text-slate-200">
                                <input type="radio" name="edit_customer_mode" className="text-violet-500" checked={editCustomerMode === 'new'} onChange={() => { setEditCustomerMode('new'); setEditSelectedCustomerId(''); }} />
                                Keep / edit details
                            </label>
                            <label className="inline-flex cursor-pointer items-center gap-2 text-sm font-semibold text-slate-200">
                                <input type="radio" name="edit_customer_mode" className="text-violet-500" checked={editCustomerMode === 'existing'} onChange={() => { setEditCustomerMode('existing'); setEditSelectedCustomerId(''); }} />
                                Link to existing customer
                            </label>
                            <label className="inline-flex cursor-pointer items-center gap-2 text-sm font-semibold text-slate-200">
                                <input type="radio" name="edit_customer_mode" className="text-violet-500" checked={editCustomerMode === 'package'} onChange={() => { setEditCustomerMode('package'); setEditSelectedCustomerId(''); applyCustomerToEditForm(null); }} />
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
                                    <option value="">Choose a customer...</option>
                                    {customers.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}{c.phone ? ` - ${c.phone}` : ''}
                                        </option>
                                    ))}
                                </select>
                                {editCustomerHasGiftCards ? (
                                    <p className="mt-2 text-sm font-semibold text-emerald-300">Gift card remaining balance: {formatMoney(editCustomerGiftBalance, currencyCode)}</p>
                                ) : null}
                            </div>
                        ) : null}
                        {editCustomerMode === 'package' && editSelectedCustomerId ? (
                            <div className="md:col-span-2 rounded-md border border-emerald-400/30 bg-emerald-500/10 p-4">
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
                                    <option value="">Choose customer package...</option>
                                    {editAvailablePackages.map((pkg) => (
                                        <option key={pkg.id} value={pkg.id}>
                                            {pkg.package_name}
                                        </option>
                                    ))}
                                </select>
                                {editSelectedPackage ? (
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {editSelectedPackage.services.map((service) => (
                                            <span key={service.id} className={`rounded-full px-2 py-1 text-xs ${service.remaining_sessions > 0 ? 'border border-emerald-300/40 bg-emerald-400/10 text-emerald-200' : 'border border-white/10 bg-white/5 text-slate-500'}`}>
                                                {service.name} {service.remaining_sessions}/{service.included_sessions} left
                                            </span>
                                        ))}
                                    </div>
                                ) : null}
                            </div>
                        ) : null}
                        <div><label className="ta-field-label">{editCustomerMode === 'existing' ? 'Name' : 'Full name'}</label><input className="ta-input" value={editForm.data.customer_name} onChange={(e) => editForm.setData('customer_name', e.target.value)} required />{fieldError(editForm, 'customer_name')}</div>
                        <div><label className="ta-field-label">{editCustomerMode === 'existing' ? 'Phone number' : 'Phone (optional)'}</label><input className="ta-input" value={editForm.data.customer_phone} onChange={(e) => editForm.setData('customer_phone', e.target.value)} />{fieldError(editForm, 'customer_phone')}</div>
                        <div><label className="ta-field-label">Email</label><input className="ta-input" type="email" value={editForm.data.customer_email} onChange={(e) => editForm.setData('customer_email', e.target.value)} />{fieldError(editForm, 'customer_email')}</div>
                        <div>
                            <label className="ta-field-label">Services</label>
                            <input className="ta-input" value={editServiceSearch} onChange={(e) => setEditServiceSearch(e.target.value)} placeholder="Search by service or category" />
                            <div className="mt-2 max-h-52 overflow-auto rounded-md border border-white/15 bg-[#18181a]">
                                {editFilteredServices.map((s) => (
                                    <button
                                        key={s.id}
                                        type="button"
                                        className="block w-full border-b border-l-4 border-b-white/10 px-3 py-3 text-left text-xs text-slate-300 hover:bg-white/5"
                                        style={{ borderLeftColor: getServiceAccentColor(s) }}
                                        onClick={() => handleEditServiceChange([...editSelectedServices, String(s.id)])}
                                    >
                                        <div className="font-bold text-white">{s.name}</div>
                                        <div className="mt-0.5 text-[11px] text-slate-400">{s.category || 'Uncategorized'} - {s.duration_minutes}m - <span className="font-bold text-slate-200">{formatMoney(s.price, currencyCode)}</span></div>
                                    </button>
                                ))}
                                {editFilteredServices.length === 0 ? <div className="px-3 py-2 text-xs text-slate-500">No more services found.</div> : null}
                            </div>
                            <div className="mt-2 flex flex-wrap gap-2">
                                {editSelectedServices.map((id, index) => {
                                    const s = services.find((x) => String(x.id) === String(id));
                                    if (!s) return null;
                                    return (
                                        <button key={serviceLineKey(index)} type="button" className="rounded-full border border-rose-300/40 bg-rose-500/10 px-2 py-1 text-xs font-semibold text-rose-200" onClick={() => handleEditServiceChange(editSelectedServices.filter((_, selectedIndex) => selectedIndex !== index))}>
                                            {s.name}{Number(serviceLineMapValue(editForm.data.service_quantities, index, id, 1)) > 1 ? ` x${serviceLineMapValue(editForm.data.service_quantities, index, id, 1)}` : ''} x
                                        </button>
                                    );
                                })}
                            </div>
                            {fieldError(editForm, 'service_id')}
                            {fieldError(editForm, 'service_ids')}
                        </div>
                        {editSelectedCustomer && editCustomerHasGiftCards ? (
                            <div className="md:col-span-2 rounded-md border border-white/10 bg-white/[0.03] p-4">
                                <p className="text-xs font-bold uppercase text-slate-400">Gift card check</p>
                                <div className="mt-2 flex flex-wrap gap-4 text-sm text-slate-200">
                                    <span>Gift card balance: <strong>{formatMoney(editCustomerGiftBalance, currencyCode)}</strong></span>
                                    <span>Estimated services total: <strong>{formatMoney(editEstimatedServicesTotal, currencyCode)}</strong></span>
                                </div>
                                {editGiftCardShortfall > 0 ? (
                                    <p className="mt-2 text-sm font-semibold text-rose-300">Warning: selected services exceed gift card balance by {formatMoney(editGiftCardShortfall, currencyCode)}.</p>
                                ) : null}
                                {!editGiftCardShortfall && editCustomerGiftBalance > 0 && editSelectedServices.length > 0 ? (
                                    <p className="mt-2 text-xs text-emerald-300">Selected services fit within the current gift card balance.</p>
                                ) : null}
                            </div>
                        ) : null}
                        {editHasMultipleServices ? (
                            <div>
                                <label className="ta-field-label">Staff</label>
                                <div className="rounded-md border border-white/10 bg-white/[0.03] px-3 py-3 text-sm font-semibold text-slate-300">Hidden for multi-service bookings. Assign staff per service below.</div>
                            </div>
                        ) : (
                            <div>
                                <SearchableSelect variant="dark" label="Staff" value={editForm.data.staff_profile_id} onChange={(id) => editForm.setData('staff_profile_id', id)} options={staffOptions} placeholder="Search staff" />
                                <p className="mt-1 text-xs font-semibold text-slate-400">Select staff or leave auto / unassigned.</p>
                                {fieldError(editForm, 'staff_profile_id')}
                            </div>
                        )}
                        {editHasMultipleServices ? (
                            <div className="md:col-span-2 rounded-md border border-white/10 bg-white/[0.03] p-4">
                                <p className="mb-2 text-xs font-bold uppercase text-slate-400">Staff per service</p>
                                <div className="grid gap-2 md:grid-cols-2">
                                    {editSelectedServices.map((serviceId, index) => {
                                        const service = services.find((s) => String(s.id) === String(serviceId));
                                        const assignmentKey = serviceLineKey(index);
                                        return (
                                            <div key={`edit-staff-${assignmentKey}`}>
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
                                                <SearchableSelect
                                                    variant="dark"
                                                    value={editForm.data.staff_assignments?.[assignmentKey] || ''}
                                                    onChange={(id) => {
                                                        editForm.clearErrors('staff_profile_id', 'staff_assignments');
                                                        const nextAssignments = {
                                                            ...(editForm.data.staff_assignments || {}),
                                                            [assignmentKey]: id,
                                                        };
                                                        editForm.setData({
                                                            ...editForm.data,
                                                            staff_assignments: nextAssignments,
                                                            staff_profile_id: hasAssignmentsForAllServices(editSelectedServices, nextAssignments)
                                                                ? ''
                                                                : editForm.data.staff_profile_id,
                                                        });
                                                    }}
                                                    options={[{ value: '', label: 'Use default / auto' }, ...normalizedStaffProfiles.map((s) => {
                                                        const availability = editStaffAvailability[String(s.id)] || editFallbackStaffAvailability[String(s.id)];
                                                        return { value: String(s.id), label: `${s.name}${availability ? ` (${availability.label})` : ''}` };
                                                    })]}
                                                    placeholder="Search staff"
                                                />
                                            </div>
                                        );
                                    })}
                                </div>
                                <p className="mt-2 text-xs font-semibold text-slate-400">Staff marked busy already have an overlapping appointment in the current schedule view.</p>
                                {fieldError(editForm, 'staff_assignments')}
                            </div>
                        ) : null}
                        <div><label className="ta-field-label">Status</label><select className="ta-input" value={editForm.data.status} onChange={(e) => editForm.setData('status', e.target.value)}><option value="pending">pending</option><option value="confirmed">confirmed</option><option value="in_progress">in_progress</option><option value="completed">completed</option><option value="cancelled">cancelled</option><option value="no_show">no_show</option></select>{fieldError(editForm, 'status')}</div>
                        <div>
                            <label className="ta-field-label">Scheduled Start</label>
                            <p className="mb-1 text-xs font-semibold text-slate-400">Walk-ins can start from the current time; future bookings can be scheduled up to the booking horizon.</p>
                            <input
                                key={`edit-start-${editStartMountKey}`}
                                ref={editStartRef}
                                className="ta-input [color-scheme:dark]"
                                type="datetime-local"
                                value={editForm.data.scheduled_start || editStartDefault}
                                onInput={(e) => syncEditStartFromInput(e.currentTarget.value)}
                                onChange={(e) => syncEditStartFromInput(e.currentTarget.value)}
                                required
                            />
                            {fieldError(editForm, 'scheduled_start')}
                        </div>
                        <div>
                            <label className="ta-field-label">Scheduled End</label>
                            <input
                                className="ta-input [color-scheme:dark]"
                                type="datetime-local"
                                value={editForm.data.scheduled_end || ''}
                                onInput={(e) => handleEditEndChange(e.currentTarget.value)}
                                onChange={(e) => handleEditEndChange(e.currentTarget.value)}
                            />
                            {fieldError(editForm, 'scheduled_end')}
                        </div>
                        <div className="md:col-span-2"><label className="ta-field-label">Notes</label><input className="ta-input" value={editForm.data.notes} onChange={(e) => editForm.setData('notes', e.target.value)} placeholder="Notes" />{fieldError(editForm, 'notes')}</div>
                        <div className="md:col-span-2 flex justify-end gap-2 border-t border-white/10 pt-5">
                            <button type="button" className="rounded-full border border-white/15 px-5 py-2 text-sm font-bold text-slate-200 hover:bg-white/5" onClick={() => setEditingId(null)}>Close</button>
                            <button className="rounded-full bg-violet-500 px-5 py-2 text-sm font-bold text-white hover:bg-violet-400 disabled:opacity-60" disabled={editSaving}>Save</button>
                        </div>
                    </form>
                </div>
            </Modal> : null}

            {!isStaff ? <ConfirmActionModal
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
            /> : null}

            <Modal show={showBoardView} maxWidth="full" onClose={() => {
                setShowBoardView(false);
                setCalendarQuickAction(null);
                setCalendarDrawer(null);
                setCalendarAppointmentId(null);
                setBoardStaffMenu(null);
            }}>
                <div className="appointment-board flex h-[92vh] overflow-hidden bg-[#0b0b0c] font-sans text-white antialiased">
                    <div className="flex min-w-0 flex-1 flex-col">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-white/10 bg-[#111112] px-5 py-3">
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => setBoardDate(salonTodayYmd())}
                                    className="rounded-full border border-white/15 px-4 py-2 text-sm font-semibold text-slate-100 hover:bg-white/5"
                                >
                                    Today
                                </button>
                                <div className="flex overflow-hidden rounded-full border border-white/15">
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setBoardDate(shiftYmdByDays(boardDate, -1));
                                        }}
                                        className="px-3 py-2 text-sm text-slate-200 hover:bg-white/5"
                                        aria-label="Previous day"
                                    >
                                        &lt;
                                    </button>
                                    <input
                                        className="w-36 border-x border-white/15 bg-transparent px-3 py-2 text-center text-sm font-semibold text-white [color-scheme:dark]"
                                        type="date"
                                        value={boardDate}
                                        onChange={(e) => setBoardDate(e.target.value)}
                                    />
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setBoardDate(shiftYmdByDays(boardDate, 1));
                                        }}
                                        className="px-3 py-2 text-sm text-slate-200 hover:bg-white/5"
                                        aria-label="Next day"
                                    >
                                        &gt;
                                    </button>
                                </div>
                                <select
                                    className="rounded-full border border-white/15 bg-[#151516] px-4 py-2 text-sm font-semibold text-white"
                                    value={boardStaffFilter}
                                    onChange={(e) => setBoardStaffFilter(e.target.value)}
                                >
                                    <option value="all" className="bg-[#151516] text-white">All team</option>
                                    {boardStaffProfiles.map((staff) => (
                                        <option key={staff.id} value={staff.id} className="bg-[#151516] text-white">{boardStaffShortLabel(staff)}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="flex items-center gap-2">
                                {boardMoveError ? (
                                    <div className="rounded-full border border-rose-400/40 bg-rose-500/15 px-3 py-2 text-xs font-semibold text-rose-100">
                                        {boardMoveError}
                                    </div>
                                ) : null}
                                <button
                                    type="button"
                                    onClick={() => {
                                        const firstStaff = boardStaffList[0]?.id || boardStaffProfiles[0]?.id || '';
                                        setBoardStaffMenu(null);
                                        openCalendarQuickAction(firstStaff, defaultBoardActionMinutes(), 0);
                                    }}
                                    className="rounded-full border border-violet-400/50 bg-violet-500/15 px-4 py-2 text-sm font-semibold text-violet-50 hover:bg-violet-500/25"
                                >
                                    Add
                                </button>
                                <button type="button" onClick={() => setShowBoardView(false)} className="rounded-full border border-white/15 px-4 py-2 text-sm font-semibold text-slate-200 hover:bg-white/5">Close</button>
                            </div>
                        </div>

                        <div className="flex min-h-0 flex-1 overflow-hidden">
                            {selectedCalendarAppointment ? (
                                <aside className="flex w-[390px] shrink-0 flex-col border-r border-white/10 bg-[#0f0f10]">
                                    <div className="flex items-start justify-between border-b border-white/10 px-5 py-4">
                                        <div className="min-w-0">
                                            <div className="text-xs font-bold uppercase tracking-wide text-violet-300">Appointment</div>
                                            <h3 className="mt-1 truncate text-xl font-black text-white">{selectedCalendarAppointment.customer_name || 'Client'}</h3>
                                            <p className="mt-1 text-sm text-slate-400">{selectedCalendarAppointment.customer_phone || 'No phone'}</p>
                                        </div>
                                        <button type="button" className="text-2xl leading-none text-slate-300 hover:text-white" onClick={() => setCalendarAppointmentId(null)}>x</button>
                                    </div>

                                    <div className="min-h-0 flex-1 space-y-4 overflow-auto px-5 py-5">
                                        <div className="rounded-lg border border-white/10 bg-[#18181a] p-4">
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <div className="text-xs font-bold uppercase tracking-wide text-slate-500">Time</div>
                                                    <div className="mt-1 text-sm font-bold text-white">
                                                        {formatDateTime(selectedCalendarAppointment.scheduled_start)}
                                                    </div>
                                                </div>
                                                <span className="rounded-full border border-white/10 px-2.5 py-1 text-xs font-bold capitalize text-slate-200">
                                                    {String(selectedCalendarAppointment.status || '').replace('_', ' ')}
                                                </span>
                                            </div>
                                            <div className="mt-4 border-t border-white/10 pt-4">
                                                <div className="text-xs font-bold uppercase tracking-wide text-slate-500">Team</div>
                                                <div className="mt-1 text-sm font-bold text-white">{selectedCalendarAppointment.staff_name || 'Unassigned'}</div>
                                            </div>
                                        </div>

                                        <div className="rounded-lg border border-white/10 bg-[#18181a] p-4">
                                            <div className="mb-3 text-xs font-bold uppercase tracking-wide text-slate-500">Services</div>
                                            <div className="space-y-3">
                                                {(selectedCalendarAppointment.grouped_services || [{
                                                    id: selectedCalendarAppointment.id,
                                                    name: selectedCalendarAppointment.service_name,
                                                    status: selectedCalendarAppointment.status,
                                                    staff_name: selectedCalendarAppointment.staff_name,
                                                    quantity: selectedCalendarAppointment.service_quantity || 1,
                                                }]).map((service) => (
                                                    <div key={service.id} className="rounded-md border border-white/10 bg-[#111112] px-3 py-3">
                                                        <div className="flex items-start justify-between gap-3">
                                                            <div className="min-w-0">
                                                                <div className="truncate text-sm font-bold text-white">
                                                                    {service.name || 'Service'}{Number(service.quantity || 1) > 1 ? ` x${service.quantity}` : ''}
                                                                </div>
                                                                <div className="mt-1 text-xs text-slate-400">{service.staff_name || 'Unassigned'}</div>
                                                            </div>
                                                            <span className="shrink-0 text-xs font-bold capitalize text-slate-300">{String(service.status || '').replace('_', ' ')}</span>
                                                        </div>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>

                                        <div className="rounded-lg border border-white/10 bg-[#18181a] p-4">
                                            <div className="mb-3 text-xs font-bold uppercase tracking-wide text-slate-500">Checkout</div>
                                            <div className="space-y-2 text-sm">
                                                <div className="flex justify-between gap-3 text-slate-300">
                                                    <span>Total</span>
                                                    <span className="font-bold text-white">{formatMoney(selectedCalendarAppointment.invoice_total || 0, currencyCode)}</span>
                                                </div>
                                                <div className="flex justify-between gap-3 text-slate-300">
                                                    <span>Paid</span>
                                                    <span className="font-bold text-white">{formatMoney(selectedCalendarAppointment.invoice_amount_paid || 0, currencyCode)}</span>
                                                </div>
                                                <div className="flex justify-between gap-3 border-t border-white/10 pt-2 text-slate-300">
                                                    <span>Balance</span>
                                                    <span className="font-black text-white">{formatMoney(selectedCalendarAppointment.invoice_balance_due || 0, currencyCode)}</span>
                                                </div>
                                                {selectedCalendarAppointment.awaiting_checkout ? (
                                                    <div className="mt-3 rounded-md border border-amber-400/30 bg-amber-400/10 px-3 py-2 text-xs font-bold text-amber-100">
                                                        Needs checkout/payment
                                                    </div>
                                                ) : null}
                                            </div>
                                        </div>

                                        {selectedCalendarAppointment.notes && !isSeedReferenceNote(selectedCalendarAppointment.notes) ? (
                                            <div className="rounded-lg border border-white/10 bg-[#18181a] p-4">
                                                <div className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">Notes</div>
                                                <p className="whitespace-pre-wrap text-sm text-slate-200">{selectedCalendarAppointment.notes}</p>
                                            </div>
                                        ) : null}
                                    </div>

                                    <div className="space-y-2 border-t border-white/10 p-5">
                                        {selectedCalendarCanFinish ? (
                                            <button
                                                type="button"
                                                className="w-full rounded-full bg-violet-500 px-4 py-2.5 text-sm font-black text-white hover:bg-violet-400"
                                                onClick={() => openCompleteService(selectedCalendarAppointment, canCheckout && canFinishAndPayNow ? 'pay' : null)}
                                            >
                                                {canCheckout && canFinishAndPayNow ? 'Complete & pay' : 'Complete appointment'}
                                            </button>
                                        ) : null}
                                        {selectedCalendarAppointment.status === 'confirmed' ? (
                                            <button type="button" className="w-full rounded-full border border-white/15 px-4 py-2.5 text-sm font-bold text-slate-100 hover:bg-white/5" onClick={() => openStartService(selectedCalendarAppointment)}>
                                                Start service
                                            </button>
                                        ) : null}
                                        {selectedCalendarAppointment.awaiting_checkout ? (
                                            selectedCalendarAppointment.checkout_invoice_id ? (
                                                <Link href={route('finance.invoices.show', selectedCalendarAppointment.checkout_invoice_id)} className="block w-full rounded-full border border-amber-300/40 bg-amber-300/10 px-4 py-2.5 text-center text-sm font-bold text-amber-100 hover:bg-amber-300/20">
                                                    Open invoice & pay
                                                </Link>
                                            ) : (
                                                <button type="button" className="w-full rounded-full border border-amber-300/40 bg-amber-300/10 px-4 py-2.5 text-sm font-bold text-amber-100 hover:bg-amber-300/20" onClick={() => router.post(route('appointments.checkout', selectedCalendarAppointment.id))}>
                                                    Create checkout
                                                </button>
                                            )
                                        ) : null}
                                        <button type="button" className="w-full rounded-full border border-white/15 px-4 py-2.5 text-sm font-bold text-slate-100 hover:bg-white/5" onClick={() => startEdit(selectedCalendarAppointment)}>
                                            Edit details
                                        </button>
                                    </div>
                                </aside>
                            ) : null}
                            <div className="flex min-h-0 min-w-0 flex-1 overflow-auto">
                            <div className="sticky left-0 z-30 w-16 shrink-0 border-r border-white/10 bg-[#0b0b0c]">
                                <div className="h-28 border-b border-white/10" />
                                <div className="relative" style={{ height: `${boardCanvasHeight}px` }}>
                                    {boardHourMarks.slice(0, -1).map((minutes) => (
                                        <div key={minutes} className="flex h-20 items-start justify-end border-b border-white/10 px-2 pt-2 text-right text-xs font-semibold leading-tight text-white">
                                            <span>{formatHourLabel(Math.floor(minutes / 60)).replace(' ', '\n')}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>

                            <div className="relative flex min-w-max flex-1">
                                {calendarQuickAction ? (
                                    <div
                                        className="absolute z-40 w-64 overflow-hidden rounded-lg border border-white/10 bg-[#242424] shadow-2xl"
                                        style={{
                                            left: `${Math.max(8, (calendarQuickAction.staffIndex * 288) + 16)}px`,
                                            top: `${112 + Math.max(0, ((calendarQuickAction.minutes - boardStartMinutes) / boardTotalMinutes) * boardCanvasHeight)}px`,
                                        }}
                                    >
                                        <div className="flex items-center justify-between bg-white/5 px-4 py-3">
                                            <div className="text-lg font-semibold text-white">
                                                {formatTimeFromDateTimeLocal(calendarQuickAction.startsAt)}
                                            </div>
                                            <button type="button" className="text-xl leading-none text-slate-300 hover:text-white" onClick={() => setCalendarQuickAction(null)}>x</button>
                                        </div>
                                        <div className="space-y-1 p-3">
                                            <button type="button" onClick={() => seedCreateFromCalendar(calendarQuickAction)} className="flex w-full items-center gap-3 rounded-md px-3 py-3 text-left text-sm font-semibold text-white hover:bg-white/5">
                                                <span className="grid h-7 w-7 place-items-center rounded-full border border-white/15 text-base">+</span>
                                                Add appointment
                                            </button>
                                            <button type="button" onClick={() => seedCreateFromCalendar(calendarQuickAction, true)} className="flex w-full items-center gap-3 rounded-md px-3 py-3 text-left text-sm font-semibold text-white hover:bg-white/5">
                                                <span className="grid h-7 w-7 place-items-center rounded-full border border-white/15 text-base">G</span>
                                                Add group appointment
                                            </button>
                                            <button type="button" onClick={() => seedBlockedTimeFromCalendar(calendarQuickAction)} className="flex w-full items-center gap-3 rounded-md px-3 py-3 text-left text-sm font-semibold text-white hover:bg-white/5">
                                                <span className="grid h-7 w-7 place-items-center rounded-full border border-white/15 text-base">B</span>
                                                Add blocked time
                                            </button>
                                        </div>
                                    </div>
                                ) : null}

                                {boardCardsByStaff.map(({ staff, cards, blocks }, staffIndex) => {
                                    const staffOff = boardStaffIsOff(staff.id);
                                    const schedule = boardScheduleForStaff(staff.id);
                                    const openBookingForStaff = () => {
                                        const action = quickActionForStaff(staff, staffIndex);
                                        setBoardStaffMenu(null);
                                        seedCreateFromCalendar(action);
                                    };

                                    return (
                                    <div key={staff.id} className={`relative w-72 shrink-0 border-r border-white/10 ${staffOff ? 'bg-[#121212]' : ''}`}>
                                        <div className="sticky top-0 z-20 flex h-28 flex-col items-center justify-center gap-2 border-b border-white/10 bg-[#171718] px-4">
                                            <button
                                                type="button"
                                                aria-label="Open team member board actions"
                                                className="group flex max-w-full flex-col items-center gap-2"
                                                onClick={() => {
                                                    setCalendarQuickAction(null);
                                                    setBoardStaffMenu((current) => String(current?.staffId || '') === String(staff.id) ? null : { staffId: String(staff.id), staffIndex });
                                                }}
                                            >
                                                <span className="grid h-14 w-14 place-items-center rounded-full border-2 border-teal-300 bg-[#262628] text-sm font-semibold text-white shadow-[0_0_0_3px_rgba(124,58,237,0.45)] group-hover:border-teal-200">
                                                    {boardStaffShortLabel(staff)}
                                                </span>
                                                <span className="flex max-w-full items-center gap-1 text-center text-xs font-semibold text-slate-300 group-hover:text-white">
                                                    <span className="truncate">{staff.name || 'Team member'}</span>
                                                    <span className="text-[10px] leading-none text-slate-400 group-hover:text-white">v</span>
                                                </span>
                                            </button>
                                            {String(boardStaffMenu?.staffId || '') === String(staff.id) ? (
                                                <div className="absolute left-4 top-[5.75rem] z-50 w-56 overflow-hidden rounded-lg border border-white/15 bg-[#202020] p-2 text-sm shadow-2xl">
                                                    <button type="button" className="flex w-full items-center gap-3 rounded-md border border-white/40 px-3 py-2.5 text-left font-semibold text-white">
                                                        <span className="text-base">[]</span>
                                                        Day view
                                                    </button>
                                                    <button type="button" className="mt-1 flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left font-semibold text-white hover:bg-white/5">
                                                        <span className="text-base">|||</span>
                                                        3 day view
                                                    </button>
                                                    <button type="button" className="flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left font-semibold text-white hover:bg-white/5">
                                                        <span className="text-base">|||</span>
                                                        Week view
                                                    </button>
                                                    <button type="button" className="flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left font-semibold text-white hover:bg-white/5">
                                                        <span className="text-base">#</span>
                                                        Month view
                                                    </button>
                                                    <div className="my-2 border-t border-white/10" />
                                                    <div className="px-3 py-1 text-sm font-semibold text-slate-200">Actions</div>
                                                    <button
                                                        type="button"
                                                        className="block w-full rounded-md px-3 py-2 text-left font-semibold text-white hover:bg-white/5"
                                                        onClick={openBookingForStaff}
                                                    >
                                                        Add booking
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="block w-full rounded-md px-3 py-2 text-left font-semibold text-white hover:bg-white/5"
                                                        onClick={openBookingForStaff}
                                                    >
                                                        Add service
                                                    </button>
                                                    <button
                                                        type="button"
                                                        className="block w-full rounded-md px-3 py-2 text-left font-semibold text-white hover:bg-white/5"
                                                        onClick={() => {
                                                            const action = quickActionForStaff(staff, staffIndex);
                                                            setBoardStaffMenu(null);
                                                            seedBlockedTimeFromCalendar(action);
                                                        }}
                                                    >
                                                        Add blocked time
                                                    </button>
                                                    <Link
                                                        href={route('schedules.index', { staff_profile_id: staff.id, date_from: boardDate, date_to: boardDate })}
                                                        className="block rounded-md px-3 py-2 font-semibold text-white hover:bg-white/5"
                                                    >
                                                        Edit shift
                                                    </Link>
                                                    <button
                                                        type="button"
                                                        className="block w-full rounded-md px-3 py-2 text-left font-semibold text-white hover:bg-white/5"
                                                        onClick={() => {
                                                            const action = quickActionForStaff(staff, staffIndex);
                                                            setBoardStaffMenu(null);
                                                            seedTimeOffFromCalendar(action);
                                                        }}
                                                    >
                                                        Add time off
                                                    </button>
                                                    <Link href={route('staff.index', { search: staff.name || '' })} className="block rounded-md px-3 py-2 font-semibold text-white hover:bg-white/5">
                                                        View team member
                                                    </Link>
                                                </div>
                                            ) : null}
                                        </div>
                                        <div className="relative" style={{ height: `${boardCanvasHeight}px` }}>
                                            {boardHourMarks.slice(0, -1).map((minutes) => (
                                                <div key={`${staff.id}-${minutes}`} className="h-20 border-b border-white/10" />
                                            ))}
                                            {staffOff ? (
                                                <div className="absolute inset-0 z-30 flex items-start justify-center bg-zinc-900/80 px-4 py-6 text-center">
                                                    <div>
                                                        <div className="text-xs font-black uppercase tracking-wide text-zinc-400">Off today</div>
                                                        <div className="mt-2 text-sm font-semibold text-zinc-200">{schedule?.notes || 'No appointments can be dropped here.'}</div>
                                                    </div>
                                                </div>
                                            ) : null}
                                            {!staffOff && boardSlotMarks.map((minutes) => {
                                                const top = Math.max(0, ((minutes - boardStartMinutes) / boardTotalMinutes) * 100);
                                                const height = Math.max(1, (boardSlotInterval / boardTotalMinutes) * 100);
                                                return (
                                                    <button
                                                        key={`${staff.id}-slot-${minutes}`}
                                                        type="button"
                                                        onDragOver={(event) => {
                                                            if (!draggingAppointmentId) return;
                                                            event.preventDefault();
                                                        }}
                                                        onDrop={(event) => {
                                                            event.preventDefault();
                                                            const appointmentId = event.dataTransfer.getData('text/plain') || draggingAppointmentId;
                                                            moveBoardAppointment(appointmentId, staff.id, minutes);
                                                        }}
                                                        onClick={() => openCalendarQuickAction(staff.id, minutes, staffIndex)}
                                                        className="absolute left-0 w-full border-t border-white/[0.035] text-left text-[0px] hover:bg-violet-500/10 focus:bg-violet-500/15"
                                                        style={{ top: `${top}%`, height: `${height}%` }}
                                                        aria-label={`Create at ${calendarSlotToDateTimeLocal(minutes)}`}
                                                    />
                                                );
                                            })}
                                            {blocks.map((block) => (
                                                <div
                                                    key={`block-${block.id}`}
                                                    className="absolute left-2 right-2 z-10 overflow-hidden rounded-md border border-white/10 bg-[repeating-linear-gradient(135deg,rgba(255,255,255,0.12)_0,rgba(255,255,255,0.12)_1px,rgba(255,255,255,0.04)_1px,rgba(255,255,255,0.04)_6px)] px-3 py-2 text-left"
                                                    style={{ top: `${block.top}%`, height: `${block.height}%` }}
                                                >
                                                    <div className="flex items-start justify-between gap-2">
                                                        <div>
                                                            <div className="text-xs font-semibold text-white">{block.timeLabel}</div>
                                                            <div className="mt-1 text-xs font-semibold text-slate-100">{block.title}</div>
                                                        </div>
                                                        {!isStaff ? (
                                                            <button
                                                                type="button"
                                                                className="text-xs font-bold text-slate-400 hover:text-white"
                                                                onClick={() => router.delete(route('appointments.blocked-time.destroy', block.id), { preserveScroll: true })}
                                                                aria-label="Remove blocked time"
                                                            >
                                                                x
                                                            </button>
                                                        ) : null}
                                                    </div>
                                                </div>
                                            ))}
                                            {cards.map((appt) => {
                                                const canBoardFinishPay = canCheckout && canFinishAndPayNow && ['confirmed', 'in_progress'].includes(appt.status);

                                                return (
                                                <div
                                                    key={appt.id}
                                                    role="button"
                                                    tabIndex={0}
                                                    draggable={!appt.isPaid && !['completed', 'cancelled', 'no_show'].includes(appt.status)}
                                                    onDragStart={(event) => {
                                                        event.dataTransfer.setData('text/plain', String(appt.id));
                                                        event.dataTransfer.effectAllowed = 'move';
                                                        setDraggingAppointmentId(appt.id);
                                                    }}
                                                    onDragEnd={() => setDraggingAppointmentId(null)}
                                                    onClick={() => {
                                                        openCalendarAppointmentDrawer(appt);
                                                    }}
                                                    onKeyDown={(event) => {
                                                        if (event.key === 'Enter' || event.key === ' ') {
                                                            event.preventDefault();
                                                            openCalendarAppointmentDrawer(appt);
                                                        }
                                                    }}
                                                    className={`absolute overflow-hidden rounded-md border p-2 text-left shadow-lg transition hover:scale-[1.01] ${draggingAppointmentId === appt.id ? 'opacity-60' : ''}`}
                                                    style={{
                                                        ...appt.cardStyle,
                                                        top: `${appt.top}%`,
                                                        height: `${appt.height}%`,
                                                        left: `calc(${appt.left}% + 0.5rem)`,
                                                        width: `calc(${appt.width}% - 0.75rem)`,
                                                        zIndex: appt.zIndex + 10,
                                                    }}
                                                >
                                                    <div className="text-xs font-semibold text-black">{appt.timeLabel}</div>
                                                    <div className="mt-1 text-sm font-semibold leading-tight text-black">{appt.customer_name}</div>
                                                    <div className="text-xs font-semibold leading-tight text-black">{appt.service_name}</div>
                                                    {Number(appt.invoice_amount_paid || 0) > 0 ? <div className="mt-1 text-[11px] font-black leading-tight text-black">Paid {formatMoney(appt.invoice_amount_paid, currencyCode)}</div> : null}
                                                    {appt.customer_package_id ? <div className="mt-1 text-[11px] font-semibold text-black">Package session</div> : null}
                                                    {appt.awaiting_checkout ? <div className="mt-1 text-[11px] font-semibold text-black">Needs payment</div> : null}
                                                    {canBoardFinishPay ? (
                                                        <button
                                                            type="button"
                                                            className="mt-2 rounded-full bg-black/80 px-2 py-1 text-[11px] font-bold text-white hover:bg-black"
                                                            onClick={(event) => {
                                                                event.stopPropagation();
                                                                openCompleteService(appt, 'pay');
                                                            }}
                                                        >
                                                            Finish &amp; pay
                                                        </button>
                                                    ) : null}
                                                </div>
                                            );
                                            })}
                                        </div>
                                    </div>
                                );
                                })}
                                {boardCardsByStaff.length === 0 ? (
                                    <div className="flex flex-1 items-center justify-center text-sm text-slate-400">No staff selected.</div>
                                ) : null}
                            </div>
                            </div>
                        </div>
                    </div>

                    {calendarDrawer ? (
                        <aside className={`flex shrink-0 flex-col border-l border-white/10 bg-[#0f0f10] ${calendarDrawer === 'blocked' ? 'w-[410px]' : 'w-[860px] max-w-[calc(100vw-2rem)]'}`}>
                            <div className="flex items-start justify-between border-b border-white/10 px-6 py-5">
                                <div>
                                    <h3 className="text-2xl font-black text-white">
                                        {calendarDrawer === 'blocked' ? 'Add blocked time' : (calendarDrawer === 'group' ? 'Group appointment' : 'Add appointment')}
                                    </h3>
                                    <p className="mt-1 text-sm text-slate-400">
                                        {calendarDrawer === 'blocked' ? 'Reserve staff time away from client bookings.' : 'Select a client and service for this calendar slot.'}
                                    </p>
                                </div>
                                <button type="button" className="text-2xl leading-none text-slate-300 hover:text-white" onClick={() => setCalendarDrawer(null)}>x</button>
                            </div>

                            {calendarDrawer === 'blocked' ? (
                                <form
                                    className="flex min-h-0 flex-1 flex-col gap-4 overflow-auto px-6 py-5"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        blockForm.post(route('appointments.blocked-time.store'), {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                blockForm.reset();
                                                setCalendarDrawer(null);
                                            },
                                        });
                                    }}
                                >
                                    <div>
                                        <label className="mb-1 block text-xs font-bold uppercase text-slate-400">Team member</label>
                                        <select className="w-full rounded-md border border-white/15 bg-[#18181a] px-3 py-3 text-sm text-white" value={blockForm.data.staff_profile_id} onChange={(e) => blockForm.setData('staff_profile_id', e.target.value)}>
                                            <option value="">All team</option>
                                            {boardStaffProfiles.map((staff) => <option key={staff.id} value={staff.id}>{staff.name}</option>)}
                                        </select>
                                        {fieldError(blockForm, 'staff_profile_id')}
                                    </div>
                                    <div>
                                        <label className="mb-1 block text-xs font-bold uppercase text-slate-400">Title</label>
                                        <input className="w-full rounded-md border border-white/15 bg-[#18181a] px-3 py-3 text-sm text-white" value={blockForm.data.title} onChange={(e) => blockForm.setData('title', e.target.value)} required />
                                        {fieldError(blockForm, 'title')}
                                    </div>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div>
                                            <label className="mb-1 block text-xs font-bold uppercase text-slate-400">Start</label>
                                            <input className="w-full rounded-md border border-white/15 bg-[#18181a] px-3 py-3 text-sm text-white [color-scheme:dark]" type="datetime-local" value={blockForm.data.starts_at} onChange={(e) => blockForm.setData('starts_at', e.target.value)} required />
                                            {fieldError(blockForm, 'starts_at')}
                                        </div>
                                        <div>
                                            <label className="mb-1 block text-xs font-bold uppercase text-slate-400">End</label>
                                            <input className="w-full rounded-md border border-white/15 bg-[#18181a] px-3 py-3 text-sm text-white [color-scheme:dark]" type="datetime-local" value={blockForm.data.ends_at} onChange={(e) => blockForm.setData('ends_at', e.target.value)} required />
                                            {fieldError(blockForm, 'ends_at')}
                                        </div>
                                    </div>
                                    <div>
                                        <label className="mb-1 block text-xs font-bold uppercase text-slate-400">Notes</label>
                                        <textarea className="min-h-[96px] w-full rounded-md border border-white/15 bg-[#18181a] px-3 py-3 text-sm text-white" value={blockForm.data.notes} onChange={(e) => blockForm.setData('notes', e.target.value)} />
                                        {fieldError(blockForm, 'notes')}
                                    </div>
                                    <div className="mt-auto flex justify-end gap-2 border-t border-white/10 pt-4">
                                        <button type="button" className="rounded-full border border-white/15 px-4 py-2 text-sm font-semibold text-slate-200" onClick={() => setCalendarDrawer(null)}>Cancel</button>
                                        <button className="rounded-full bg-violet-500 px-5 py-2 text-sm font-bold text-white hover:bg-violet-400" disabled={blockForm.processing}>Save</button>
                                    </div>
                                </form>
                            ) : (
                                <form
                                    className="flex min-h-0 flex-1 flex-col gap-5 overflow-auto px-6 py-5"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        createForm.post(route('appointments.store'), {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                setCalendarDrawer(null);
                                                createForm.reset();
                                                setCreateCustomerSearch('');
                                                setCreateServiceSearch('');
                                                setCreateSelectedCustomerId('');
                                                setCreateSelectedPackageId('');
                                                setCreateCustomerMode('new');
                                            },
                                        });
                                    }}
                                >
                                    <div className="grid gap-5 xl:grid-cols-[260px_minmax(0,1fr)]">
                                        <div className="border-b border-white/10 pb-5 xl:border-b-0 xl:border-r xl:pb-0 xl:pr-5">
                                            <h4 className="text-xl font-black text-white">Select a client</h4>
                                            <input
                                                className="mt-4 w-full rounded-md border border-violet-500 bg-[#18181a] px-3 py-3 text-sm text-white"
                                                value={createCustomerSearch}
                                                onChange={(e) => setCreateCustomerSearch(e.target.value)}
                                                placeholder="Search client or leave empty"
                                            />
                                            <div className="mt-4 space-y-2">
                                                <button
                                                    type="button"
                                                    className={`flex w-full items-center gap-3 rounded-md px-3 py-3 text-left transition hover:bg-white/5 ${createCustomerMode === 'new' && !createSelectedCustomerId ? 'bg-violet-500/15' : ''}`}
                                                    onClick={startCalendarNewClient}
                                                >
                                                    <span className="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-violet-500/20 text-2xl text-violet-200">+</span>
                                                    <span>
                                                        <span className="block text-sm font-black text-white">Add new client</span>
                                                        <span className="mt-0.5 block text-xs text-slate-400">Create details for this booking</span>
                                                    </span>
                                                </button>
                                                <button
                                                    type="button"
                                                    className={`flex w-full items-center gap-3 rounded-md px-3 py-3 text-left transition hover:bg-white/5 ${createForm.data.customer_name === 'Walk-in Client' ? 'bg-violet-500/15' : ''}`}
                                                    onClick={() => {
                                                        setCreateCustomerMode('new');
                                                        setCreateSelectedCustomerId('');
                                                        setCreateSelectedPackageId('');
                                                        createForm.setData({
                                                            ...createForm.data,
                                                            customer_id: '',
                                                            customer_name: 'Walk-in Client',
                                                            customer_phone: '',
                                                            customer_email: '',
                                                            customer_package_id: '',
                                                            package_service_ids: [],
                                                        });
                                                    }}
                                                >
                                                    <span className="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-violet-500/20 text-sm font-black text-violet-200">WI</span>
                                                    <span>
                                                        <span className="block text-sm font-black text-white">Walk-In</span>
                                                        <span className="mt-0.5 block text-xs text-slate-400">Or leave empty for walk-ins</span>
                                                    </span>
                                                </button>
                                            </div>
                                            <div className="mt-4 max-h-[360px] space-y-1 overflow-auto border-t border-white/10 pt-3">
                                                {createFilteredCustomers.map((customer) => {
                                                    const selected = String(customer.id) === String(createSelectedCustomerId);
                                                    const initials = String(customer.name || 'C').trim().slice(0, 1).toUpperCase();

                                                    return (
                                                        <button
                                                            key={customer.id}
                                                            type="button"
                                                            className={`flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left transition hover:bg-white/5 ${selected ? 'bg-violet-500/15' : ''}`}
                                                            onClick={() => {
                                                                setCreateSelectedCustomerId(String(customer.id));
                                                                setCreateCustomerMode('existing');
                                                                applyCustomerToCreateForm(customer);
                                                            }}
                                                        >
                                                            <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-violet-500/20 text-sm font-black text-violet-200">{initials}</span>
                                                            <span className="min-w-0">
                                                                <span className="block truncate text-sm font-black text-white">{customer.name}</span>
                                                                <span className="block truncate text-xs text-slate-400">{customer.phone || customer.email || 'No contact saved'}</span>
                                                            </span>
                                                        </button>
                                                    );
                                                })}
                                                {createFilteredCustomers.length === 0 ? (
                                                    <div className="rounded-md border border-white/10 px-3 py-4 text-sm text-slate-400">No matching clients.</div>
                                                ) : null}
                                            </div>
                                            {createSelectedCustomerId && createAvailablePackages.length > 0 ? (
                                                <div className="mt-4 rounded-md border border-emerald-400/30 bg-emerald-500/10 p-3">
                                                    <label className="mb-1 block text-xs font-bold uppercase text-emerald-200">Package</label>
                                                    <select
                                                        className="w-full rounded-md border border-emerald-300/30 bg-[#18181a] px-3 py-3 text-sm text-white"
                                                        value={createSelectedPackageId}
                                                        onChange={(e) => {
                                                            const id = e.target.value;
                                                            setCreateSelectedPackageId(id);
                                                            setCreateCustomerMode(id ? 'package' : 'existing');
                                                            createForm.setData({
                                                                ...createForm.data,
                                                                customer_package_id: id,
                                                                package_service_ids: [],
                                                            });
                                                        }}
                                                    >
                                                        <option value="">No package</option>
                                                        {createAvailablePackages.map((pkg) => (
                                                            <option key={pkg.id} value={pkg.id}>
                                                                {pkg.package_name}{pkg.expires_at ? ` - expires ${new Date(pkg.expires_at).toLocaleDateString()}` : ''}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    {createSelectedPackage ? (
                                                        <div className="mt-3 flex flex-wrap gap-2">
                                                            {createSelectedPackage.services.map((service) => (
                                                                <span key={service.id} className={`rounded-full px-2 py-1 text-xs ${service.remaining_sessions > 0 ? 'border border-emerald-300/40 bg-emerald-400/10 text-emerald-100' : 'border border-white/10 bg-white/5 text-slate-500'}`}>
                                                                    {service.name} {service.remaining_sessions}/{service.included_sessions} left
                                                                </span>
                                                            ))}
                                                        </div>
                                                    ) : null}
                                                    {fieldError(createForm, 'customer_package_id')}
                                                    {fieldError(createForm, 'package_service_ids')}
                                                </div>
                                            ) : null}
                                            <div className="mt-4 space-y-3 border-t border-white/10 pt-4">
                                                <input
                                                    ref={calendarClientNameRef}
                                                    className="w-full rounded-md border border-white/15 bg-[#18181a] px-3 py-3 text-sm text-white"
                                                    placeholder="Client name"
                                                    value={createForm.data.customer_name}
                                                    onChange={(e) => createForm.setData('customer_name', e.target.value)}
                                                    required
                                                />
                                                <input
                                                    className="w-full rounded-md border border-white/15 bg-[#18181a] px-3 py-3 text-sm text-white"
                                                    placeholder="Phone"
                                                    value={createForm.data.customer_phone}
                                                    onChange={(e) => createForm.setData('customer_phone', e.target.value)}
                                                />
                                                <input
                                                    className="w-full rounded-md border border-white/15 bg-[#18181a] px-3 py-3 text-sm text-white"
                                                    placeholder="Email"
                                                    type="email"
                                                    value={createForm.data.customer_email}
                                                    onChange={(e) => createForm.setData('customer_email', e.target.value)}
                                                />
                                                {fieldError(createForm, 'customer_name')}
                                            </div>
                                        </div>

                                        <div className="min-w-0">
                                        <div className="mb-3 flex items-center justify-between gap-3">
                                            <h4 className="text-2xl font-black text-white">Select a service</h4>
                                            {createSelectedServiceRows.length > 0 ? (
                                                <div className="text-right">
                                                    <div className="text-[11px] font-bold uppercase text-slate-500">Services total</div>
                                                    <div className="text-sm font-black text-white">{formatMoney(createDrawerServicesSubtotal, currencyCode)}</div>
                                                </div>
                                            ) : null}
                                        </div>

                                        {createSelectedServiceRows.length > 0 ? (
                                            <div className="mb-4 space-y-2">
                                                {createSelectedServiceRows.map((service) => {
                                                    const meta = getCreateServiceMeta(service);
                                                    const serviceStart = getCreateServiceSequenceStart(service);
                                                    const sameServiceCount = createSelectedServices.filter((id) => String(id) === String(service.id)).length;
                                                    const isEditing = String(calendarServiceEditorId) === String(service.lineKey);

                                                    return (
                                                        <button
                                                            key={`selected-drawer-service-${service.lineKey}`}
                                                            type="button"
                                                            className={`w-full rounded-md border px-3 py-3 text-left transition ${isEditing ? 'border-violet-400 bg-violet-500/15' : 'border-white/10 bg-white/[0.03] hover:bg-white/5'}`}
                                                            onClick={() => setCalendarServiceEditorId((current) => (String(current) === String(service.lineKey) ? '' : String(service.lineKey)))}
                                                            aria-expanded={isEditing}
                                                        >
                                                            <div className="flex items-start justify-between gap-3">
                                                                <div>
                                                                    <div className="text-sm font-black text-white">{service.name}{sameServiceCount > 1 ? ` #${service.lineIndex + 1}` : ''}</div>
                                                                    <div className="mt-1 text-xs text-slate-400">
                                                                        {formatTimeFromDateTimeLocal(serviceStart) || 'Start not set'} - {meta.durationMinutes + meta.extraMinutes}m
                                                                        {meta.staffName ? ` - ${meta.staffName}` : ''}
                                                                        {meta.packageCovered ? ' - Package session' : ''}
                                                                    </div>
                                                                </div>
                                                                <div className="flex items-center gap-3">
                                                                    <span className="text-sm font-black text-slate-100">{formatMoney(meta.lineTotal, currencyCode)}</span>
                                                                    <span className="text-xl text-slate-400">{isEditing ? 'v' : '>'}</span>
                                                                </div>
                                                            </div>
                                                        </button>
                                                    );
                                                })}
                                            </div>
                                        ) : null}

                                        {calendarServiceEditor ? (() => {
                                            const meta = getCreateServiceMeta(calendarServiceEditor);
                                            const serviceStart = getCreateServiceSequenceStart(calendarServiceEditor);
                                            const durationChoices = [...new Set([...serviceDurationOptions, meta.durationMinutes])].sort((a, b) => a - b);

                                            return (
                                                <div className="mb-5 rounded-md border border-white/10 bg-white/[0.03] p-4">
                                                    <div className="mb-3 flex items-center justify-between gap-3">
                                                        <div>
                                                            <h5 className="text-xl font-black text-white">Edit service</h5>
                                                            <p className="mt-1 text-xs text-slate-400">{calendarServiceEditor.name}</p>
                                                        </div>
                                                        <button type="button" className="text-xl text-slate-400 hover:text-white" onClick={() => setCalendarServiceEditorId('')} aria-label="Collapse service editor">x</button>
                                                    </div>

                                                    <button type="button" className="mb-4 flex w-full items-center justify-between rounded-md border border-white/10 bg-[#18181a] px-3 py-3 text-left" onClick={() => setCreateServiceSearch(calendarServiceEditor.name)}>
                                                        <span className="text-sm font-bold text-white">{calendarServiceEditor.name}, {meta.durationMinutes + meta.extraMinutes}m</span>
                                                        <span className="text-xl text-slate-400">&gt;</span>
                                                    </button>

                                                    <div className="space-y-4">
                                                        <div>
                                                            <label className="mb-1 block text-xs font-bold uppercase text-slate-400">Team member</label>
                                                            <select
                                                                className="w-full rounded-md border border-white/15 bg-[#18181a] px-3 py-3 text-sm text-white"
                                                                value={meta.staffId}
                                                                onChange={(e) => updateCreateServiceMeta(calendarServiceEditor, { staffId: e.target.value })}
                                                            >
                                                                {boardStaffOptions.map((staff) => <option key={staff.value || 'auto'} value={staff.value}>{staff.label}</option>)}
                                                            </select>
                                                        </div>

                                                        <div className="grid grid-cols-2 gap-3">
                                                            <div>
                                                                <label className="mb-1 block text-xs font-bold uppercase text-slate-400">Service price</label>
                                                                <div className="flex rounded-md border border-white/15 bg-[#18181a]">
                                                                    <span className="border-r border-white/10 px-3 py-3 text-sm font-bold text-slate-400">{currencyCode}</span>
                                                                    <input
                                                                        className="min-w-0 flex-1 bg-transparent px-3 py-3 text-sm font-bold text-white outline-none"
                                                                        type="number"
                                                                        min="0"
                                                                        step="0.01"
                                                                        value={meta.unitPrice}
                                                                        onChange={(e) => updateCreateServiceMeta(calendarServiceEditor, { unitPrice: e.target.value })}
                                                                    />
                                                                </div>
                                                            </div>
                                                            <div>
                                                                <label className="mb-1 block text-xs font-bold uppercase text-slate-400">Discount</label>
                                                                <div className="flex rounded-md border border-white/15 bg-[#18181a]">
                                                                    <span className="border-r border-white/10 px-3 py-3 text-sm font-bold text-slate-400">{currencyCode}</span>
                                                                    <input
                                                                        className="min-w-0 flex-1 bg-transparent px-3 py-3 text-sm font-bold text-white outline-none"
                                                                        type="number"
                                                                        min="0"
                                                                        max={meta.unitPrice * meta.quantity}
                                                                        step="0.01"
                                                                        value={meta.discountAmount}
                                                                        onChange={(e) => updateCreateServiceMeta(calendarServiceEditor, { discountAmount: e.target.value })}
                                                                    />
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <div className="grid grid-cols-2 gap-3">
                                                            <div>
                                                                <label className="mb-1 block text-xs font-bold uppercase text-slate-400">Start time</label>
                                                                <input
                                                                    className="w-full rounded-md border border-white/15 bg-[#18181a] px-3 py-3 text-sm text-white [color-scheme:dark]"
                                                                    type="datetime-local"
                                                                    value={serviceStart}
                                                                    onInput={(e) => updateCreateServiceMeta(calendarServiceEditor, { start: e.target.value })}
                                                                    onChange={(e) => updateCreateServiceMeta(calendarServiceEditor, { start: e.target.value })}
                                                                />
                                                            </div>
                                                            <div>
                                                                <label className="mb-1 block text-xs font-bold uppercase text-slate-400">Duration</label>
                                                                <select
                                                                    className="w-full rounded-md border border-white/15 bg-[#18181a] px-3 py-3 text-sm text-white"
                                                                    value={meta.durationMinutes}
                                                                    onChange={(e) => updateCreateServiceMeta(calendarServiceEditor, { duration: e.target.value })}
                                                                >
                                                                    {durationChoices.map((minutes) => <option key={minutes} value={minutes}>{minutes >= 60 ? `${Math.floor(minutes / 60)}h${minutes % 60 ? ` ${minutes % 60}m` : ''}` : `${minutes}m`}</option>)}
                                                                </select>
                                                            </div>
                                                        </div>

                                                        <div className="grid grid-cols-2 gap-3">
                                                            <div>
                                                                <label className="mb-1 block text-xs font-bold uppercase text-slate-400">Qty</label>
                                                                <input
                                                                    className="w-full rounded-md border border-white/15 bg-[#18181a] px-3 py-3 text-sm text-white"
                                                                    type="number"
                                                                    min="1"
                                                                    value={meta.quantity}
                                                                    onChange={(e) => updateCreateServiceMeta(calendarServiceEditor, { quantity: e.target.value })}
                                                                />
                                                            </div>
                                                            <div>
                                                                <label className="mb-1 block text-xs font-bold uppercase text-slate-400">Extra time</label>
                                                                <div className="flex gap-2">
                                                                    <input
                                                                        className="min-w-0 flex-1 rounded-md border border-white/15 bg-[#18181a] px-3 py-3 text-sm text-white"
                                                                        type="number"
                                                                        min="0"
                                                                        step="5"
                                                                        value={meta.extraMinutes}
                                                                        onChange={(e) => updateCreateServiceMeta(calendarServiceEditor, { extra: e.target.value })}
                                                                    />
                                                                    <button type="button" className="rounded-full border border-white/15 px-3 text-sm font-bold text-slate-200 hover:bg-white/5" onClick={() => updateCreateServiceMeta(calendarServiceEditor, { extra: meta.extraMinutes + 15 })}>+15</button>
                                                                </div>
                                                            </div>
                                                        </div>

                                                        <div className="flex items-center justify-between rounded-md border border-white/10 bg-[#18181a] px-3 py-3">
                                                            <div>
                                                                <div className="text-xs font-bold uppercase text-slate-500">Adjusted total</div>
                                                                <div className="text-sm font-black text-white">{formatMoney(meta.lineTotal, currencyCode)}</div>
                                                            </div>
                                                            <button
                                                                type="button"
                                                                className="text-sm font-bold text-rose-300 hover:text-rose-200"
                                                                onClick={() => {
                                                                    handleCreateServiceChange(createSelectedServices.filter((_, index) => index !== calendarServiceEditor.lineIndex));
                                                                    setCalendarServiceEditorId('');
                                                                }}
                                                            >
                                                                Remove service
                                                            </button>
                                                        </div>
                                                        {createSelectedPackage && meta.packageCoverage ? (
                                                            <label className="flex items-center justify-between gap-3 rounded-md border border-emerald-400/30 bg-emerald-500/10 px-3 py-3 text-sm text-emerald-100">
                                                                <span>
                                                                    <span className="block font-bold">Use package session</span>
                                                                    <span className="mt-0.5 block text-xs text-emerald-200">{meta.packageCoverage.remaining_sessions} session{meta.packageCoverage.remaining_sessions === 1 ? '' : 's'} left for this service</span>
                                                                </span>
                                                                <input
                                                                    type="checkbox"
                                                                    className="h-4 w-4 rounded border-emerald-300 bg-[#18181a] text-emerald-500"
                                                                    checked={meta.packageCovered}
                                                                    disabled={meta.packageCoverage.remaining_sessions < 1 && !meta.packageCovered}
                                                                    onChange={(e) => createForm.setData('package_service_ids', e.target.checked
                                                                        ? [...new Set([...(createForm.data.package_service_ids || []), meta.sid])]
                                                                        : (createForm.data.package_service_ids || []).filter((serviceId) => String(serviceId) !== meta.sid))}
                                                                />
                                                            </label>
                                                        ) : null}
                                                    </div>
                                                </div>
                                            );
                                        })() : null}

                                        <input className="w-full rounded-md border border-violet-500 bg-[#18181a] px-3 py-3 text-sm text-white" value={createServiceSearch} onChange={(e) => setCreateServiceSearch(e.target.value)} placeholder="Search by service name" />
                                        <div className="mt-4 max-h-[360px] space-y-5 overflow-auto pr-1">
                                            {Object.entries(createFilteredServices.reduce((groups, service) => {
                                                const key = service.category || 'Uncategorized';
                                                groups[key] = [...(groups[key] || []), service];
                                                return groups;
                                            }, {})).map(([category, categoryServices]) => (
                                                <div key={category}>
                                                    <div className="mb-2 flex items-center gap-2">
                                                        <h5 className="text-base font-black text-white">{category}</h5>
                                                        <span className="rounded-full bg-white/10 px-2 py-0.5 text-xs font-bold text-slate-300">{categoryServices.length}</span>
                                                    </div>
                                                    <div className="space-y-1">
                                                        {categoryServices.map((service) => {
                                                            const selectedCount = createSelectedServices.filter((id) => String(id) === String(service.id)).length;
                                                            const serviceAccentColor = getServiceAccentColor(service);
                                                            return (
                                                                <button
                                                                    key={service.id}
                                                                    type="button"
                                                                    className={`flex w-full items-start justify-between border-l-4 px-3 py-3 text-left hover:bg-white/5 ${selectedCount > 0 ? 'bg-violet-500/10' : ''}`}
                                                                    style={{ borderLeftColor: serviceAccentColor }}
                                                                    onClick={() => {
                                                                        const sid = String(service.id);
                                                                        const nextIds = [...createSelectedServices, sid];
                                                                        handleCreateServiceChange(nextIds);
                                                                        setCalendarServiceEditorId(serviceLineKey(nextIds.length - 1));
                                                                    }}
                                                                >
                                                                    <span>
                                                                        <span className="block text-sm font-bold text-white">{service.name}</span>
                                                                        <span className="mt-1 block text-xs text-slate-400">{service.duration_minutes}m{selectedCount > 0 ? ` - selected ${selectedCount}` : ''}</span>
                                                                    </span>
                                                                    <span className="text-sm font-bold text-slate-200">{formatMoney(service.price, currencyCode)}</span>
                                                                </button>
                                                            );
                                                        })}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                        {fieldError(createForm, 'service_ids')}
                                    </div>
                                    </div>

                                    <div className="grid grid-cols-2 gap-3">
                                        <div>
                                            <label className="mb-1 block text-xs font-bold uppercase text-slate-400">Team member</label>
                                            <select className="w-full rounded-md border border-white/15 bg-[#18181a] px-3 py-3 text-sm text-white" value={createForm.data.staff_profile_id} onChange={(e) => createForm.setData('staff_profile_id', e.target.value)}>
                                                {boardStaffOptions.map((staff) => <option key={staff.value || 'auto'} value={staff.value}>{staff.label}</option>)}
                                            </select>
                                            {fieldError(createForm, 'staff_profile_id')}
                                        </div>
                                        <div>
                                            <label className="mb-1 block text-xs font-bold uppercase text-slate-400">Status</label>
                                            <select className="w-full rounded-md border border-white/15 bg-[#18181a] px-3 py-3 text-sm text-white" value={createForm.data.status} onChange={(e) => createForm.setData('status', e.target.value)}>
                                                <option value="confirmed">confirmed</option>
                                                <option value="pending">pending</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-2 gap-3">
                                        <div>
                                            <label className="mb-1 block text-xs font-bold uppercase text-slate-400">Start</label>
                                            <input className="w-full rounded-md border border-white/15 bg-[#18181a] px-3 py-3 text-sm text-white [color-scheme:dark]" type="datetime-local" value={createForm.data.scheduled_start} onInput={(e) => syncCreateStartFromInput(e.currentTarget.value)} onChange={(e) => syncCreateStartFromInput(e.currentTarget.value)} required />
                                            {fieldError(createForm, 'scheduled_start')}
                                        </div>
                                        <div>
                                            <label className="mb-1 block text-xs font-bold uppercase text-slate-400">End</label>
                                            <input className="w-full rounded-md border border-white/15 bg-[#18181a] px-3 py-3 text-sm text-white [color-scheme:dark]" type="datetime-local" value={createForm.data.scheduled_end} onInput={(e) => handleCreateEndChange(e.target.value)} onChange={(e) => handleCreateEndChange(e.target.value)} />
                                        </div>
                                    </div>
                                    <textarea className="min-h-[82px] w-full rounded-md border border-white/15 bg-[#18181a] px-3 py-3 text-sm text-white" value={createForm.data.notes} onChange={(e) => createForm.setData('notes', e.target.value)} placeholder="Appointment notes" />
                                    <div className="mt-auto flex justify-end gap-2 border-t border-white/10 pt-4">
                                        <button type="button" className="rounded-full border border-white/15 px-4 py-2 text-sm font-semibold text-slate-200" onClick={() => setCalendarDrawer(null)}>Cancel</button>
                                        <button className="rounded-full bg-violet-500 px-5 py-2 text-sm font-bold text-white hover:bg-violet-400" disabled={createForm.processing}>Save appointment</button>
                                    </div>
                                </form>
                            )}
                        </aside>
                    ) : null}
                </div>
            </Modal>
        </AuthenticatedLayout>
    );
}
