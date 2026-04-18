import ConfirmActionModal from '@/Components/ConfirmActionModal';
import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;

export default function StaffIndex({ staffProfiles, roles, showDeleted = false, trashedCount = 0 }) {
    const { flash } = usePage().props;
    const [editingId, setEditingId] = useState(null);
    const [uiError, setUiError] = useState('');
    const [staffConfirm, setStaffConfirm] = useState(null);
    const [staffConfirmBusy, setStaffConfirmBusy] = useState(false);
    const safeRoles = Array.isArray(roles) ? roles : [];
    const safeStaffProfiles = Array.isArray(staffProfiles) ? staffProfiles : [];

    const createForm = useForm({ name: '', email: '', phone: '', skills: '', hourly_rate: '', password: '', role_id: '' });
    const editForm = useForm({ name: '', email: '', phone: '', skills: '', hourly_rate: '', is_active: true, role_id: '' });

    const toUserFriendlyError = (errors, fallback) => {
        const first = Object.values(errors || {}).find((v) => typeof v === 'string' && v.trim() !== '');
        return first || fallback;
    };

    const closeEditModal = () => {
        setEditingId(null);
        editForm.clearErrors();
    };

    const staffConfirmCopy = (c) => {
        if (!c) return { title: '', message: '', confirmText: '', confirmClassName: undefined };
        const name = c.name || 'this staff member';
        if (c.action === 'restore') {
            return {
                title: 'Restore to team list?',
                message: `${name} will appear again in schedules and assignments.`,
                confirmText: 'Restore',
                confirmClassName: 'rounded-xl bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-60',
            };
        }
        if (c.action === 'deactivate') {
            return {
                title: 'Deactivate this staff member?',
                message: `${name} stays in the team list but cannot be assigned until reactivated.`,
                confirmText: 'Deactivate',
                confirmClassName: 'rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700 disabled:opacity-60',
            };
        }
        return {
            title: 'Remove from team?',
            message: `${name} will be hidden from schedules and assignments. You can restore them from Removed staff.`,
            confirmText: 'Remove',
            confirmClassName: undefined,
        };
    };

    const startEdit = (staff) => {
        if (!staff || !staff.id) {
            setUiError('Could not load this staff member. Please refresh and try again.');
            return;
        }
        setEditingId(staff.id);
        editForm.setData({
            name: staff.user?.name || '',
            email: staff.user?.email || '',
            phone: staff.phone || '',
            skills: (staff.skills || []).join(', '),
            hourly_rate: staff.hourly_rate != null ? String(staff.hourly_rate) : '',
            is_active: Boolean(staff.is_active),
            role_id: staff.user?.role_id ? String(staff.user.role_id) : '',
        });
        editForm.clearErrors();
    };

    return (
        <AuthenticatedLayout header="Staff">
            <Head title="Staff" />
            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}
                {uiError && <div className="ta-card border-red-200 bg-red-50 p-3 text-sm text-red-700">{uiError}</div>}

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Create Staff Member</h3>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            createForm.transform((d) => ({
                                ...d,
                                hourly_rate: d.hourly_rate === '' ? null : d.hourly_rate,
                            }));
                            createForm.post(route('staff.store'), {
                                onSuccess: () => {
                                    setUiError('');
                                    createForm.reset();
                                },
                                onError: (errors) => {
                                    setUiError(toUserFriendlyError(errors, 'Could not add staff member. Please check fields and try again.'));
                                },
                            });
                        }}
                        className="grid gap-3 md:grid-cols-2 xl:grid-cols-4"
                    >
                        <div><label className="ta-field-label">Full Name</label><input className="ta-input" placeholder="Full name" value={createForm.data.name} onChange={(e) => createForm.setData('name', e.target.value)} required />{fieldError(createForm, 'name')}</div>
                        <div><label className="ta-field-label">Email</label><input className="ta-input" placeholder="Email" value={createForm.data.email} onChange={(e) => createForm.setData('email', e.target.value)} required />{fieldError(createForm, 'email')}</div>
                        <div><label className="ta-field-label">Phone</label><input className="ta-input" placeholder="Phone" value={createForm.data.phone} onChange={(e) => createForm.setData('phone', e.target.value)} />{fieldError(createForm, 'phone')}</div>
                        <div><label className="ta-field-label">Role</label><select className="ta-input" value={createForm.data.role_id} onChange={(e) => createForm.setData('role_id', e.target.value)} required><option value="">Role</option>{safeRoles.map((r) => <option key={r.id} value={r.id}>{r.label}</option>)}</select>{fieldError(createForm, 'role_id')}</div>
                        <div className="md:col-span-2"><label className="ta-field-label">Skills</label><input className="ta-input" placeholder="Comma separated skills" value={createForm.data.skills} onChange={(e) => createForm.setData('skills', e.target.value)} />{fieldError(createForm, 'skills')}</div>
                        <div><label className="ta-field-label">Hourly rate (payroll)</label><input className="ta-input" type="number" min="0" step="0.01" placeholder="Optional" value={createForm.data.hourly_rate} onChange={(e) => createForm.setData('hourly_rate', e.target.value)} />{fieldError(createForm, 'hourly_rate')}</div>
                        <div><label className="ta-field-label">Optional Password</label><input className="ta-input" placeholder="Optional password" value={createForm.data.password} onChange={(e) => createForm.setData('password', e.target.value)} />{fieldError(createForm, 'password')}</div>
                        <div className="md:col-span-2 xl:col-span-4">
                            <button className="ta-btn-primary" disabled={createForm.processing}>Add Staff</button>
                        </div>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
                        <h3 className="text-sm font-semibold text-slate-700">{showDeleted ? 'Removed staff' : 'Team List'}</h3>
                        {(showDeleted || trashedCount > 0) ? (
                            <button
                                type="button"
                                className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50"
                                onClick={() => router.get(route('staff.index'), showDeleted ? {} : { show_deleted: 1 }, { preserveState: true, replace: true })}
                            >
                                {showDeleted ? 'Back to active team' : `Removed staff (${trashedCount})`}
                            </button>
                        ) : null}
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-5 py-3">Code</th>
                                    <th className="px-5 py-3">Name</th>
                                    <th className="px-5 py-3">Email</th>
                                    <th className="px-5 py-3">Role</th>
                                    <th className="px-5 py-3">Phone</th>
                                    <th className="px-5 py-3">Skills</th>
                                    <th className="px-5 py-3">Status</th>
                                    <th className="px-5 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {safeStaffProfiles.map((s) => (
                                    <tr key={s.id} className="border-t border-slate-100">
                                        <td className="px-5 py-3 font-medium text-slate-700">{s.employee_code}</td>
                                        <td className="px-5 py-3 text-slate-600">{s.user?.name}</td>
                                        <td className="px-5 py-3 text-slate-600">{s.user?.email}</td>
                                        <td className="px-5 py-3 text-slate-600">{s.user?.role_label || '-'}</td>
                                        <td className="px-5 py-3 text-slate-600">{s.phone || '-'}</td>
                                        <td className="px-5 py-3 text-slate-600">{(s.skills || []).join(', ') || '-'}</td>
                                        <td className="px-5 py-3">
                                            {s.deleted_at ? (
                                                <span className="rounded-full bg-slate-200 px-2.5 py-1 text-xs font-semibold text-slate-700">Removed</span>
                                            ) : (
                                                <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${s.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-700'}`}>{s.is_active ? 'Active' : 'Inactive'}</span>
                                            )}
                                        </td>
                                        <td className="px-5 py-3">
                                            <div className="flex flex-wrap gap-2">
                                                {showDeleted ? (
                                                    <button
                                                        type="button"
                                                        className="rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700"
                                                        onClick={() => setStaffConfirm({ action: 'restore', id: s.id, name: s.user?.name || '' })}
                                                    >
                                                        Restore
                                                    </button>
                                                ) : (
                                                    <>
                                                        <button type="button" className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700" onClick={() => startEdit(s)}>Edit</button>
                                                        <button
                                                            type="button"
                                                            className="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800"
                                                            onClick={() => setStaffConfirm({ action: 'deactivate', id: s.id, name: s.user?.name || '' })}
                                                        >
                                                            Deactivate
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700"
                                                            onClick={() => setStaffConfirm({ action: 'remove', id: s.id, name: s.user?.name || '' })}
                                                        >
                                                            Remove
                                                        </button>
                                                    </>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <ConfirmActionModal
                    show={Boolean(staffConfirm)}
                    {...(staffConfirm ? staffConfirmCopy(staffConfirm) : {})}
                    onClose={() => !staffConfirmBusy && setStaffConfirm(null)}
                    processing={staffConfirmBusy}
                    onConfirm={() => {
                        if (!staffConfirm) return;
                        setStaffConfirmBusy(true);
                        const finish = () => {
                            setStaffConfirmBusy(false);
                            setStaffConfirm(null);
                        };
                        const onError = (errors) => {
                            setUiError(toUserFriendlyError(errors, 'Could not complete this action.'));
                        };
                        if (staffConfirm.action === 'restore') {
                            router.post(route('staff.restore', staffConfirm.id), {}, { onFinish: finish, onError });
                            return;
                        }
                        if (staffConfirm.action === 'deactivate') {
                            router.post(route('staff.deactivate', staffConfirm.id), {}, { onFinish: finish, onError });
                            return;
                        }
                        router.delete(route('staff.destroy', staffConfirm.id), { onFinish: finish, onError });
                    }}
                />

                <Modal show={Boolean(editingId)} onClose={closeEditModal} maxWidth="2xl">
                    <section className="ta-card p-5">
                        <h3 className="mb-4 text-sm font-semibold text-slate-700">Edit Staff #{editingId}</h3>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                editForm.transform((d) => ({
                                    ...d,
                                    hourly_rate: d.hourly_rate === '' ? null : d.hourly_rate,
                                }));
                                editForm.put(route('staff.update', editingId), {
                                    onSuccess: () => {
                                        setUiError('');
                                        closeEditModal();
                                    },
                                    onError: (errors) => {
                                        setUiError(toUserFriendlyError(errors, 'Could not update staff member. Please check fields and try again.'));
                                    },
                                });
                            }}
                            className="grid gap-3 md:grid-cols-2 xl:grid-cols-4"
                        >
                            <div><label className="ta-field-label">Name</label><input className="ta-input" value={editForm.data.name} onChange={(e) => editForm.setData('name', e.target.value)} required />{fieldError(editForm, 'name')}</div>
                            <div><label className="ta-field-label">Email</label><input className="ta-input" value={editForm.data.email} onChange={(e) => editForm.setData('email', e.target.value)} required />{fieldError(editForm, 'email')}</div>
                            <div><label className="ta-field-label">Role</label><select className="ta-input" value={editForm.data.role_id} onChange={(e) => editForm.setData('role_id', e.target.value)} required><option value="">Role</option>{safeRoles.map((r) => <option key={r.id} value={r.id}>{r.label}</option>)}</select>{fieldError(editForm, 'role_id')}</div>
                            <div><label className="ta-field-label">Phone</label><input className="ta-input" value={editForm.data.phone} onChange={(e) => editForm.setData('phone', e.target.value)} />{fieldError(editForm, 'phone')}</div>
                            <div className="flex items-center"><label className="text-sm text-slate-600"><input type="checkbox" checked={editForm.data.is_active} onChange={(e) => editForm.setData('is_active', e.target.checked)} className="mr-2" />Active</label>{fieldError(editForm, 'is_active')}</div>
                            <div><label className="ta-field-label">Hourly rate</label><input className="ta-input" type="number" min="0" step="0.01" value={editForm.data.hourly_rate} onChange={(e) => editForm.setData('hourly_rate', e.target.value)} />{fieldError(editForm, 'hourly_rate')}</div>
                            <div className="md:col-span-2 xl:col-span-2"><label className="ta-field-label">Skills</label><input className="ta-input" value={editForm.data.skills} onChange={(e) => editForm.setData('skills', e.target.value)} placeholder="Comma separated skills" />{fieldError(editForm, 'skills')}</div>
                            <div className="md:col-span-4 flex gap-2"><button className="ta-btn-primary" disabled={editForm.processing}>Save</button><button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={closeEditModal}>Cancel</button></div>
                        </form>
                    </section>
                </Modal>
            </div>
        </AuthenticatedLayout>
    );
}





