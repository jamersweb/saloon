import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const statusLabels = { pending: 'Pending', confirmed: 'Confirm', in_progress: 'Start', completed: 'Complete', cancelled: 'Cancel', no_show: 'No-show' };
const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;
const toDateTimeLocal = (value) => value ? new Date(value).toISOString().slice(0, 16) : '';
const formatDateTime = (value) => value ? new Date(value).toLocaleString() : 'N/A';

export default function AppointmentsIndex({ appointments, services, staffProfiles, inventoryItems, statusFilter, bookingRules }) {
    const { flash, auth } = usePage().props;
    const canManageRules = Boolean(auth?.permissions?.can_manage_schedules);
    const [editingId, setEditingId] = useState(null);
    const [startServiceId, setStartServiceId] = useState(null);
    const [completeServiceId, setCompleteServiceId] = useState(null);

    const createForm = useForm({ customer_name: '', customer_phone: '', customer_email: '', service_id: '', staff_profile_id: '', scheduled_start: '', scheduled_end: '', status: 'confirmed', notes: '' });
    const editForm = useForm({ customer_name: '', customer_phone: '', customer_email: '', service_id: '', staff_profile_id: '', scheduled_start: '', scheduled_end: '', status: 'confirmed', notes: '' });
    const startForm = useForm({ intake_notes: '', service_notes: '', before_photo: null });
    const completeForm = useForm({ service_report: '', completion_notes: '', materials_used: '', after_photo: null, products: [{ inventory_item_id: '', quantity: 1, notes: '' }] });
    const rulesForm = useForm({
        slot_interval_minutes: bookingRules?.slot_interval_minutes ?? 15,
        min_advance_minutes: bookingRules?.min_advance_minutes ?? 30,
        max_advance_days: bookingRules?.max_advance_days ?? 60,
        public_requires_approval: Boolean(bookingRules?.public_requires_approval ?? true),
        allow_customer_cancellation: Boolean(bookingRules?.allow_customer_cancellation ?? true),
        cancellation_cutoff_hours: bookingRules?.cancellation_cutoff_hours ?? 12,
    });

    const startEdit = (appt) => {
        setEditingId(appt.id);
        editForm.setData({
            customer_name: appt.customer_name || '',
            customer_phone: appt.customer_phone || '',
            customer_email: appt.customer_email || '',
            service_id: appt.service_id || '',
            staff_profile_id: appt.staff_profile_id || '',
            scheduled_start: toDateTimeLocal(appt.scheduled_start),
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
            service_notes: appt.service_execution?.service_notes || appt.notes || '',
            before_photo: null,
        });
        startForm.clearErrors();
    };

    const openCompleteService = (appt) => {
        setCompleteServiceId(appt.id);
        setStartServiceId(null);
        completeForm.setData({
            service_report: appt.notes || '',
            completion_notes: appt.service_execution?.completion_notes || '',
            materials_used: appt.service_execution?.materials_used || '',
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

    return (
        <AuthenticatedLayout header="Appointments">
            <Head title="Appointments" />
            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Booking Rules</h3>
                    <form onSubmit={(e) => { e.preventDefault(); rulesForm.patch(route('booking-rules.update')); }} className="grid gap-3 md:grid-cols-3">
                        <div><label className="ta-field-label">Slot Interval Minutes</label><input className="ta-input" type="number" min="5" max="120" value={rulesForm.data.slot_interval_minutes} onChange={(e) => rulesForm.setData('slot_interval_minutes', e.target.value)} required />{fieldError(rulesForm, 'slot_interval_minutes')}</div>
                        <div><label className="ta-field-label">Min Advance Minutes</label><input className="ta-input" type="number" min="0" max="10080" value={rulesForm.data.min_advance_minutes} onChange={(e) => rulesForm.setData('min_advance_minutes', e.target.value)} required />{fieldError(rulesForm, 'min_advance_minutes')}</div>
                        <div><label className="ta-field-label">Max Advance Days</label><input className="ta-input" type="number" min="1" max="365" value={rulesForm.data.max_advance_days} onChange={(e) => rulesForm.setData('max_advance_days', e.target.value)} required />{fieldError(rulesForm, 'max_advance_days')}</div>
                        <div><label className="ta-field-label">Cancellation Cutoff Hours</label><input className="ta-input" type="number" min="0" max="168" value={rulesForm.data.cancellation_cutoff_hours} onChange={(e) => rulesForm.setData('cancellation_cutoff_hours', e.target.value)} required />{fieldError(rulesForm, 'cancellation_cutoff_hours')}</div>
                        <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={rulesForm.data.public_requires_approval} onChange={(e) => rulesForm.setData('public_requires_approval', e.target.checked)} />Public booking requires approval</label>
                        <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={rulesForm.data.allow_customer_cancellation} onChange={(e) => rulesForm.setData('allow_customer_cancellation', e.target.checked)} />Allow customer cancellation</label>
                        <button className="ta-btn-primary md:col-span-3" disabled={rulesForm.processing || !canManageRules}>Save Booking Rules</button>
                    </form>
                </section>

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Create Appointment</h3>
                    <form onSubmit={(e) => { e.preventDefault(); createForm.post(route('appointments.store'), { onSuccess: () => createForm.reset() }); }} className="grid gap-3 md:grid-cols-4">
                        <div><label className="ta-field-label">Customer Name</label><input className="ta-input" value={createForm.data.customer_name} onChange={(e) => createForm.setData('customer_name', e.target.value)} required />{fieldError(createForm, 'customer_name')}</div>
                        <div><label className="ta-field-label">Phone</label><input className="ta-input" value={createForm.data.customer_phone} onChange={(e) => createForm.setData('customer_phone', e.target.value)} required />{fieldError(createForm, 'customer_phone')}</div>
                        <div><label className="ta-field-label">Email</label><input className="ta-input" value={createForm.data.customer_email} onChange={(e) => createForm.setData('customer_email', e.target.value)} />{fieldError(createForm, 'customer_email')}</div>
                        <div><label className="ta-field-label">Service</label><select className="ta-input" value={createForm.data.service_id} onChange={(e) => createForm.setData('service_id', e.target.value)} required><option value="">Service</option>{services.map((s) => <option key={s.id} value={s.id}>{s.name} ({s.duration_minutes}m)</option>)}</select>{fieldError(createForm, 'service_id')}</div>
                        <div><label className="ta-field-label">Staff Profile</label><select className="ta-input" value={createForm.data.staff_profile_id} onChange={(e) => createForm.setData('staff_profile_id', e.target.value)}><option value="">Unassigned Staff</option>{staffProfiles.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>{fieldError(createForm, 'staff_profile_id')}</div>
                        <div><label className="ta-field-label">Scheduled Start</label><input className="ta-input" type="datetime-local" value={createForm.data.scheduled_start} onChange={(e) => createForm.setData('scheduled_start', e.target.value)} required />{fieldError(createForm, 'scheduled_start')}</div>
                        <div><label className="ta-field-label">Scheduled End</label><input className="ta-input" type="datetime-local" value={createForm.data.scheduled_end} onChange={(e) => createForm.setData('scheduled_end', e.target.value)} />{fieldError(createForm, 'scheduled_end')}</div>
                        <div><label className="ta-field-label">Status</label><select className="ta-input" value={createForm.data.status} onChange={(e) => createForm.setData('status', e.target.value)}><option value="confirmed">confirmed</option><option value="pending">pending</option></select>{fieldError(createForm, 'status')}</div>
                        <div className="md:col-span-4"><input className="ta-input" value={createForm.data.notes} onChange={(e) => createForm.setData('notes', e.target.value)} placeholder="Notes" />{fieldError(createForm, 'notes')}</div>
                        <button className="ta-btn-primary" disabled={createForm.processing}>Create</button>
                    </form>
                </section>

                <section className="ta-card p-4">
                    <label className="ta-field-label mr-2">Filter Status</label>
                    <select className="ta-input inline-block w-auto min-w-[180px]" value={statusFilter || ''} onChange={(e) => changeFilter(e.target.value)}>
                        <option value="">All</option>
                        {Object.keys(statusLabels).map((status) => <option key={status} value={status}>{status}</option>)}
                    </select>
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
                                            <div className="font-medium text-slate-700">{a.customer_name}</div>
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
                                                {a.status === 'in_progress' && <button className="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700" onClick={() => openCompleteService(a)}>Finish Service</button>}
                                                {(a.next_statuses || []).filter((next) => !['in_progress', 'completed'].includes(next)).map((next) => <button key={next} className="rounded-lg border border-slate-200 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50" onClick={() => transition(a.id, next)}>{statusLabels[next] || next}</button>)}
                                                {a.next_statuses?.includes('cancelled') && <button className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700" onClick={() => router.delete(route('appointments.destroy', a.id))}>Cancel</button>}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                {startServiceId && (
                    <section className="ta-card p-5">
                        <h3 className="mb-4 text-sm font-semibold text-slate-700">Start Service for Appointment #{startServiceId}</h3>
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
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <div className="md:col-span-2">
                                <label className="ta-field-label">Client Intake Notes</label>
                                <textarea className="ta-input min-h-[110px]" value={startForm.data.intake_notes} onChange={(e) => startForm.setData('intake_notes', e.target.value)} />
                                {fieldError(startForm, 'intake_notes')}
                            </div>
                            <div className="md:col-span-2">
                                <label className="ta-field-label">Staff Service Notes</label>
                                <textarea className="ta-input min-h-[110px]" value={startForm.data.service_notes} onChange={(e) => startForm.setData('service_notes', e.target.value)} />
                                {fieldError(startForm, 'service_notes')}
                            </div>
                            <div className="md:col-span-2">
                                <label className="ta-field-label">Before Photo</label>
                                <input className="ta-input" type="file" accept="image/*" onChange={(e) => startForm.setData('before_photo', e.target.files?.[0] || null)} />
                                {fieldError(startForm, 'before_photo')}
                            </div>
                            <div className="md:col-span-2 flex gap-2">
                                <button className="ta-btn-primary" disabled={startForm.processing}>Start Service</button>
                                <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setStartServiceId(null)}>Cancel</button>
                            </div>
                        </form>
                    </section>
                )}

                {completeServiceId && (
                    <section className="ta-card p-5">
                        <h3 className="mb-4 text-sm font-semibold text-slate-700">Complete Service for Appointment #{completeServiceId}</h3>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                completeForm.post(route('appointments.service-complete', completeServiceId), {
                                    forceFormData: true,
                                    onSuccess: () => {
                                        setCompleteServiceId(null);
                                        completeForm.reset();
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
                            <div className="md:col-span-2 flex gap-2">
                                <button className="ta-btn-primary" disabled={completeForm.processing}>Finish Service</button>
                                <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setCompleteServiceId(null)}>Cancel</button>
                            </div>
                        </form>
                    </section>
                )}

                {editingId && (
                    <section className="ta-card p-5">
                        <h3 className="mb-4 text-sm font-semibold text-slate-700">Edit Appointment #{editingId}</h3>
                        <form onSubmit={(e) => { e.preventDefault(); editForm.put(route('appointments.update', editingId), { onSuccess: () => setEditingId(null) }); }} className="grid gap-3 md:grid-cols-4">
                            <div><label className="ta-field-label">Customer Name</label><input className="ta-input" value={editForm.data.customer_name} onChange={(e) => editForm.setData('customer_name', e.target.value)} required />{fieldError(editForm, 'customer_name')}</div>
                            <div><label className="ta-field-label">Customer Phone</label><input className="ta-input" value={editForm.data.customer_phone} onChange={(e) => editForm.setData('customer_phone', e.target.value)} required />{fieldError(editForm, 'customer_phone')}</div>
                            <div><label className="ta-field-label">Customer Email</label><input className="ta-input" value={editForm.data.customer_email} onChange={(e) => editForm.setData('customer_email', e.target.value)} />{fieldError(editForm, 'customer_email')}</div>
                            <div><label className="ta-field-label">Service</label><select className="ta-input" value={editForm.data.service_id} onChange={(e) => editForm.setData('service_id', e.target.value)} required><option value="">Service</option>{services.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>{fieldError(editForm, 'service_id')}</div>
                            <div><label className="ta-field-label">Staff Profile</label><select className="ta-input" value={editForm.data.staff_profile_id} onChange={(e) => editForm.setData('staff_profile_id', e.target.value)}><option value="">Unassigned Staff</option>{staffProfiles.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}</select>{fieldError(editForm, 'staff_profile_id')}</div>
                            <div><label className="ta-field-label">Scheduled Start</label><input className="ta-input" type="datetime-local" value={editForm.data.scheduled_start} onChange={(e) => editForm.setData('scheduled_start', e.target.value)} required />{fieldError(editForm, 'scheduled_start')}</div>
                            <div><label className="ta-field-label">Scheduled End</label><input className="ta-input" type="datetime-local" value={editForm.data.scheduled_end} onChange={(e) => editForm.setData('scheduled_end', e.target.value)} />{fieldError(editForm, 'scheduled_end')}</div>
                            <div><label className="ta-field-label">Status</label><select className="ta-input" value={editForm.data.status} onChange={(e) => editForm.setData('status', e.target.value)}><option value="pending">pending</option><option value="confirmed">confirmed</option><option value="in_progress">in_progress</option><option value="completed">completed</option><option value="cancelled">cancelled</option><option value="no_show">no_show</option></select>{fieldError(editForm, 'status')}</div>
                            <div className="md:col-span-4"><input className="ta-input" value={editForm.data.notes} onChange={(e) => editForm.setData('notes', e.target.value)} placeholder="Notes" />{fieldError(editForm, 'notes')}</div>
                            <div className="md:col-span-4 flex gap-2"><button className="ta-btn-primary" disabled={editForm.processing}>Save</button><button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setEditingId(null)}>Cancel</button></div>
                        </form>
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
