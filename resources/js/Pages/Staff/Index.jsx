import AppFlashPopup from '@/Components/AppFlashPopup';
import ConfirmActionModal from '@/Components/ConfirmActionModal';
import Modal from '@/Components/Modal';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;

export default function StaffIndex({ staffProfiles, roles, filters, showDeleted = false, trashedCount = 0 }) {
    const { flash } = usePage().props;
    const [editingId, setEditingId] = useState(null);
    const [uiError, setUiError] = useState('');
    const [staffConfirm, setStaffConfirm] = useState(null);
    const [staffConfirmBusy, setStaffConfirmBusy] = useState(false);
    const importFileRef = useRef(null);
    const safeRoles = Array.isArray(roles) ? roles : [];
    const safeStaffProfiles = Array.isArray(staffProfiles?.data) ? staffProfiles.data : [];
    const filterForm = useForm({
        search: filters?.search || '',
        role_id: filters?.role_id ? String(filters.role_id) : '',
        status: filters?.status || (showDeleted ? 'removed' : 'all'),
        per_page: String(filters?.per_page || 10),
    });

    const createForm = useForm({ name: '', email: '', phone: '', skills: '', hourly_rate: '', password: '', role_id: '' });
    const editForm = useForm({ name: '', email: '', phone: '', skills: '', hourly_rate: '', is_active: true, role_id: '', password: '', password_confirmation: '' });

    useEffect(() => {
        filterForm.setData({
            search: filters?.search || '',
            role_id: filters?.role_id ? String(filters.role_id) : '',
            status: filters?.status || (showDeleted ? 'removed' : 'all'),
            per_page: String(filters?.per_page || 10),
        });
    }, [filters?.search, filters?.role_id, filters?.status, filters?.per_page, showDeleted]);

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
            password: '',
            password_confirmation: '',
        });
        editForm.clearErrors();
    };

    const handleUsersImport = (event) => {
        const file = event.target.files?.[0];
        if (!file) return;

        router.post(route('data-transfer.import', { entity: 'users' }), { csv_file: file }, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                if (importFileRef.current) importFileRef.current.value = '';
            },
        });
    };

    const applyFilters = () => {
        router.get(route('staff.index'), {
            search: filterForm.data.search || undefined,
            role_id: filterForm.data.role_id || undefined,
            status: filterForm.data.status,
            per_page: filterForm.data.per_page,
            show_deleted: showDeleted ? 1 : undefined,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    const resetFilters = () => {
        const defaults = {
            search: '',
            role_id: '',
            status: showDeleted ? 'removed' : 'all',
            per_page: '10',
        };

        filterForm.setData(defaults);

        router.get(route('staff.index'), {
            status: defaults.status,
            per_page: defaults.per_page,
            show_deleted: showDeleted ? 1 : undefined,
        }, {
            preserveState: true,
            replace: true,
        });
    };

    return (
        <AuthenticatedLayout header="Staff">
            <Head title="Staff" />
            <div className="space-y-6">
                <AppFlashPopup />
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
                        <div>
                            <h3 className="text-sm font-semibold text-slate-700">{showDeleted ? 'Removed staff' : 'Team List'}</h3>
                            <p className="mt-1 text-xs text-slate-500">Showing {staffProfiles?.from || 0}-{staffProfiles?.to || 0} of {staffProfiles?.total || 0}</p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <input ref={importFileRef} type="file" accept=".csv,text/csv" className="hidden" onChange={handleUsersImport} />
                            <button type="button" className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50" onClick={() => importFileRef.current?.click()}>
                                Import CSV
                            </button>
                            <button type="button" className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50" onClick={() => { window.location.href = route('data-transfer.template', { entity: 'users' }); }}>
                                Template CSV
                            </button>
                            <button type="button" className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50" onClick={() => { window.location.href = route('data-transfer.export', { entity: 'users' }); }}>
                                Export CSV
                            </button>
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
                    </div>
                    <div className="grid gap-3 border-b border-slate-200 bg-slate-50 px-5 py-4 sm:grid-cols-2 xl:grid-cols-5">
                        <input className="ta-input w-full min-w-0 sm:col-span-2 md:col-span-2" placeholder="Search code, name, email, or phone" value={filterForm.data.search} onChange={(e) => filterForm.setData('search', e.target.value)} />
                        <select className="ta-input w-full min-w-0" value={filterForm.data.role_id} onChange={(e) => filterForm.setData('role_id', e.target.value)}>
                            <option value="">All roles</option>
                            {safeRoles.map((role) => <option key={role.id} value={role.id}>{role.label}</option>)}
                        </select>
                        <select className="ta-input w-full min-w-0" value={filterForm.data.status} onChange={(e) => filterForm.setData('status', e.target.value)}>
                            {showDeleted ? (
                                <option value="removed">Removed only</option>
                            ) : (
                                <>
                                    <option value="all">All staff</option>
                                    <option value="active">Active only</option>
                                    <option value="inactive">Inactive only</option>
                                </>
                            )}
                        </select>
                        <div className="grid gap-3 sm:flex sm:flex-wrap sm:items-center">
                            <select className="ta-input w-full min-w-0 sm:max-w-[140px]" value={filterForm.data.per_page} onChange={(e) => filterForm.setData('per_page', e.target.value)}>
                                {[10, 25, 50, 100].map((size) => <option key={size} value={size}>{size} / page</option>)}
                            </select>
                            <button type="button" className="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-700 sm:w-auto" onClick={applyFilters}>Apply Filters</button>
                            <button type="button" className="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-500 sm:w-auto" onClick={resetFilters}>Reset</button>
                        </div>
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
                                {safeStaffProfiles.length === 0 && (
                                    <tr>
                                        <td colSpan="8" className="px-5 py-8 text-center text-sm text-slate-500">No staff match the current filters.</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-3 text-xs text-slate-600">
                        <span>Page {staffProfiles?.current_page || 1} of {staffProfiles?.last_page || 1}</span>
                        <div className="flex flex-wrap gap-2">
                            {(staffProfiles?.links || []).map((link) => (
                                <Link
                                    key={`${link.label}-${link.url || 'null'}`}
                                    href={link.url || '#'}
                                    preserveState
                                    className={`rounded-lg border px-3 py-1.5 ${link.active ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-600'} ${!link.url ? 'pointer-events-none opacity-50' : 'hover:bg-slate-50'}`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
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
                            <div><label className="ta-field-label">New Password</label><input className="ta-input" type="password" value={editForm.data.password} onChange={(e) => editForm.setData('password', e.target.value)} placeholder="Leave blank to keep current password" />{fieldError(editForm, 'password')}</div>
                            <div><label className="ta-field-label">Confirm Password</label><input className="ta-input" type="password" value={editForm.data.password_confirmation} onChange={(e) => editForm.setData('password_confirmation', e.target.value)} placeholder="Repeat new password" />{fieldError(editForm, 'password_confirmation')}</div>
                            <div className="md:col-span-4 flex gap-2"><button className="ta-btn-primary" disabled={editForm.processing}>Save</button><button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={closeEditModal}>Cancel</button></div>
                        </form>
                    </section>
                </Modal>
            </div>
        </AuthenticatedLayout>
    );
}
