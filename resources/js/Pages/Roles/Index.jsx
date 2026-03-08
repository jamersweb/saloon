import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const fieldError = (form, field) => form.errors?.[field] ? <p className="mt-1 text-xs text-red-600">{form.errors[field]}</p> : null;

export default function RolesIndex({ roles, permissionCatalog }) {
    const { flash } = usePage().props;
    const [editingRoleId, setEditingRoleId] = useState(null);

    const permissionKeys = useMemo(() => Object.keys(permissionCatalog || {}), [permissionCatalog]);
    const systemRoleNames = new Set(['owner', 'manager', 'staff', 'customer']);

    const createForm = useForm({ name: '', label: '', permissions: [] });
    const editForm = useForm({ label: '', permissions: [] });

    const startEdit = (role) => {
        setEditingRoleId(role.id);
        editForm.setData({
            label: role.label,
            permissions: role.permissions || [],
        });
        editForm.clearErrors();
    };

    const togglePermission = (form, key) => {
        const next = form.data.permissions.includes(key)
            ? form.data.permissions.filter((x) => x !== key)
            : [...form.data.permissions, key];

        form.setData('permissions', next);
    };

    return (
        <AuthenticatedLayout header="Roles & Permissions">
            <Head title="Roles & Permissions" />
            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Create Role</h3>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            createForm.post(route('roles.store'), { onSuccess: () => createForm.reset('name', 'label', 'permissions') });
                        }}
                        className="space-y-4"
                    >
                        <div className="grid gap-3 md:grid-cols-2">
                            <div>
                                <label className="ta-field-label">Role Name</label>
                                <input className="ta-input" placeholder="Role name (e.g. frontdesk_agent)" value={createForm.data.name} onChange={(e) => createForm.setData('name', e.target.value)} required />
                                {fieldError(createForm, 'name')}
                            </div>
                            <div>
                                <label className="ta-field-label">Role Label</label>
                                <input className="ta-input" placeholder="Role label (e.g. Frontdesk Agent)" value={createForm.data.label} onChange={(e) => createForm.setData('label', e.target.value)} required />
                                {fieldError(createForm, 'label')}
                            </div>
                        </div>
                        <div className="grid gap-2 md:grid-cols-2">
                            {permissionKeys.map((permissionKey) => (
                                <label key={permissionKey} className="flex items-start gap-2 rounded-lg border border-slate-200 p-3">
                                    <input
                                        type="checkbox"
                                        checked={createForm.data.permissions.includes(permissionKey)}
                                        onChange={() => togglePermission(createForm, permissionKey)}
                                        className="mt-1"
                                    />
                                    <span>
                                        <span className="block text-sm font-medium text-slate-700">{permissionKey}</span>
                                        <span className="block text-xs text-slate-500">{permissionCatalog[permissionKey]}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                        <button className="ta-btn-primary" disabled={createForm.processing}>Create Role</button>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4">
                        <h3 className="text-sm font-semibold text-slate-700">Roles</h3>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th className="px-5 py-3">Role</th>
                                    <th className="px-5 py-3">Code</th>
                                    <th className="px-5 py-3">Users</th>
                                    <th className="px-5 py-3">Permissions</th>
                                    <th className="px-5 py-3">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {roles.map((role) => (
                                    <tr key={role.id} className="border-t border-slate-100">
                                        <td className="px-5 py-3 font-medium text-slate-700">{role.label}</td>
                                        <td className="px-5 py-3 text-slate-600">{role.name}</td>
                                        <td className="px-5 py-3 text-slate-600">{role.users_count}</td>
                                        <td className="px-5 py-3 text-slate-600">{role.permissions?.length ?? 0}</td>
                                        <td className="px-5 py-3">
                                            <div className="flex gap-2">
                                                <button className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700" onClick={() => startEdit(role)}>Edit</button>
                                                {!systemRoleNames.has(role.name) && (
                                                    <button className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700" onClick={() => router.delete(route('roles.destroy', role.id))}>
                                                        Delete
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                {editingRoleId && (
                    <section className="ta-card p-5">
                        <h3 className="mb-4 text-sm font-semibold text-slate-700">Edit Role</h3>
                        <form onSubmit={(e) => {
                            e.preventDefault();
                            editForm.put(route('roles.update', editingRoleId), { onSuccess: () => setEditingRoleId(null) });
                        }} className="space-y-4">
                            <div>
                                <label className="ta-field-label">Label</label>
                                <input className="ta-input" value={editForm.data.label} onChange={(e) => editForm.setData('label', e.target.value)} required />
                                {fieldError(editForm, 'label')}
                            </div>
                            <div className="grid gap-2 md:grid-cols-2">
                                {permissionKeys.map((permissionKey) => (
                                    <label key={permissionKey} className="flex items-start gap-2 rounded-lg border border-slate-200 p-3">
                                        <input
                                            type="checkbox"
                                            checked={editForm.data.permissions.includes(permissionKey)}
                                            onChange={() => togglePermission(editForm, permissionKey)}
                                            className="mt-1"
                                        />
                                        <span>
                                            <span className="block text-sm font-medium text-slate-700">{permissionKey}</span>
                                            <span className="block text-xs text-slate-500">{permissionCatalog[permissionKey]}</span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                            <div className="flex gap-2">
                                <button className="ta-btn-primary" disabled={editForm.processing}>Save</button>
                                <button type="button" className="ta-btn-secondary" onClick={() => setEditingRoleId(null)}>Cancel</button>
                            </div>
                        </form>
                    </section>
                )}
            </div>
        </AuthenticatedLayout>
    );
}










