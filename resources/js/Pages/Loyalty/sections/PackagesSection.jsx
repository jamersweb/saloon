import { router } from '@inertiajs/react';

const formatMoney = (value, currencyCode = 'AED') =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency: currencyCode, minimumFractionDigits: 2 }).format(Number(value || 0));

const toggleService = (form, serviceId) => {
    const id = Number(serviceId);
    const current = Array.isArray(form.data.salon_service_ids) ? form.data.salon_service_ids.map(Number) : [];
    const nextSelected = current.includes(id) ? current.filter((entry) => entry !== id) : [...current, id];
    const nextQuantities = { ...(form.data.service_quantities || {}) };
    if (nextSelected.includes(id)) {
        nextQuantities[String(id)] = Number(nextQuantities[String(id)] || 1);
    } else {
        delete nextQuantities[String(id)];
    }
    form.setData(
        'salon_service_ids',
        nextSelected,
    );
    form.setData('service_quantities', nextQuantities);
};

const resetPackageForm = (form) => {
    form.reset('name', 'description', 'price', 'usage_limit', 'initial_value', 'validity_days', 'services_per_visit_limit', 'salon_service_ids', 'service_quantities');
    form.setData('is_active', true);
};

function PackageEditor({
    title,
    form,
    fieldError,
    canManage,
    salonServices,
    currencyCode,
    submitLabel,
    onSubmit,
    onCancel = null,
}) {
    return (
        <section className="ta-card p-5">
            <div className="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h3 className="text-sm font-semibold text-slate-700">{title}</h3>
                    <p className="mt-1 text-xs text-slate-500">Bundle multiple services, set the package sale price, included sessions, optional wallet value, expiry, and how many services can be redeemed in one visit.</p>
                </div>
                {onCancel ? <button type="button" onClick={onCancel} className="ta-btn-secondary">Cancel</button> : null}
            </div>
            <form onSubmit={onSubmit} className="space-y-4">
                <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <div>
                        <label className="ta-field-label">Package name</label>
                        <input className="ta-input" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                        {fieldError(form, 'name')}
                    </div>
                    <div>
                        <label className="ta-field-label">Package price</label>
                        <input className="ta-input" type="number" min="0" step="0.01" value={form.data.price} onChange={(e) => form.setData('price', e.target.value)} required />
                        <p className="mt-1 text-xs text-slate-500">What the customer pays for the package.</p>
                        {fieldError(form, 'price')}
                    </div>
                    <div>
                        <label className="ta-field-label">Included sessions</label>
                        <input className="ta-input" type="number" min="1" value={form.data.usage_limit} onChange={(e) => form.setData('usage_limit', e.target.value)} />
                        <p className="mt-1 text-xs text-slate-500">Leave empty if the package is value-only.</p>
                        {fieldError(form, 'usage_limit')}
                    </div>
                    <div>
                        <label className="ta-field-label">Wallet value</label>
                        <input className="ta-input" type="number" min="0" step="0.01" value={form.data.initial_value} onChange={(e) => form.setData('initial_value', e.target.value)} />
                        <p className="mt-1 text-xs text-slate-500">Optional prepaid balance for partial usage.</p>
                        {fieldError(form, 'initial_value')}
                    </div>
                    <div>
                        <label className="ta-field-label">Validity days</label>
                        <input className="ta-input" type="number" min="1" value={form.data.validity_days} onChange={(e) => form.setData('validity_days', e.target.value)} />
                        {fieldError(form, 'validity_days')}
                    </div>
                    <div>
                        <label className="ta-field-label">Services per visit</label>
                        <input className="ta-input" type="number" min="1" value={form.data.services_per_visit_limit} onChange={(e) => form.setData('services_per_visit_limit', e.target.value)} />
                        <p className="mt-1 text-xs text-slate-500">Suggested control so one visit does not consume too many package services at once.</p>
                        {fieldError(form, 'services_per_visit_limit')}
                    </div>
                    <label className="mt-6 inline-flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" checked={Boolean(form.data.is_active)} onChange={(e) => form.setData('is_active', e.target.checked)} />
                        Active package
                    </label>
                    <div className="md:col-span-2 xl:col-span-1">
                        <label className="ta-field-label">Description</label>
                        <input className="ta-input" value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                        {fieldError(form, 'description')}
                    </div>
                </div>

                <div>
                    <div className="mb-2 flex items-center justify-between gap-3">
                        <label className="ta-field-label mb-0">Included services</label>
                        <span className="text-xs text-slate-500">{(form.data.salon_service_ids || []).length} selected</span>
                    </div>
                    <div className="grid max-h-64 gap-2 overflow-y-auto rounded-xl border border-slate-200 p-3 md:grid-cols-2 xl:grid-cols-3">
                        {salonServices.map((service) => {
                            const checked = (form.data.salon_service_ids || []).map(Number).includes(Number(service.id));

                            return (
                                <label key={service.id} className={`rounded-xl border p-3 text-sm transition ${checked ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-white text-slate-700'}`}>
                                    <span className="flex items-start gap-3">
                                        <input type="checkbox" checked={checked} onChange={() => toggleService(form, service.id)} />
                                        <span className="min-w-0">
                                            <span className="block font-medium">{service.name}</span>
                                            <span className="block text-xs text-slate-500">{service.category || 'Uncategorized'} • {service.duration_minutes} min • {formatMoney(service.price, currencyCode)}</span>
                                            {checked ? (
                                                <span className="mt-2 block">
                                                    <span className="text-[11px] uppercase tracking-wide text-slate-500">Sessions in package</span>
                                                    <input
                                                        className="ta-input mt-1"
                                                        type="number"
                                                        min="1"
                                                        value={form.data.service_quantities?.[String(service.id)] ?? 1}
                                                        onChange={(e) => form.setData('service_quantities', {
                                                            ...(form.data.service_quantities || {}),
                                                            [String(service.id)]: e.target.value,
                                                        })}
                                                    />
                                                </span>
                                            ) : null}
                                        </span>
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                    {fieldError(form, 'salon_service_ids')}
                </div>

                <div className="flex items-center justify-between gap-3 border-t border-slate-200 pt-4">
                    <p className="text-xs text-slate-500">Recommended: set both sessions and expiry. Use wallet value only if the package should also behave like stored credit.</p>
                    <button className="ta-btn-primary" disabled={form.processing || !canManage}>{submitLabel}</button>
                </div>
            </form>
        </section>
    );
}

export default function PackagesSection({
    fieldError,
    canManage,
    currencyCode,
    packageForm,
    editPackageForm,
    editingPackageId,
    setEditingPackageId,
    startEditPackage,
    assignPackageForm,
    consumePackageForm,
    customers,
    packages,
    customerPackages,
    salonServices,
}) {
    const activePackages = packages.filter((pkg) => pkg.is_active);

    return (
        <div className="space-y-6">
            <PackageEditor
                title={editingPackageId ? 'Edit package' : 'Create package'}
                form={editingPackageId ? editPackageForm : packageForm}
                fieldError={fieldError}
                canManage={canManage}
                salonServices={salonServices}
                currencyCode={currencyCode}
                submitLabel={editingPackageId ? 'Save package' : 'Create package'}
                onSubmit={(e) => {
                    e.preventDefault();
                    if (editingPackageId) {
                        editPackageForm.put(route('loyalty.packages.update', editingPackageId), {
                            onSuccess: () => {
                                setEditingPackageId(null);
                                resetPackageForm(editPackageForm);
                            },
                        });
                        return;
                    }

                    packageForm.post(route('loyalty.packages.store'), {
                        onSuccess: () => resetPackageForm(packageForm),
                    });
                }}
                onCancel={editingPackageId ? () => {
                    setEditingPackageId(null);
                    resetPackageForm(editPackageForm);
                } : null}
            />

            <section className="ta-card overflow-hidden">
                <div className="border-b border-slate-200 px-5 py-4">
                    <h3 className="text-sm font-semibold text-slate-700">Package library</h3>
                    <p className="mt-1 text-xs text-slate-500">Manage package templates before assigning them to customers.</p>
                    {fieldError({ errors: packageForm.errors }, 'packages')}
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th className="px-5 py-3">Package</th>
                                <th className="px-5 py-3">Included services</th>
                                <th className="px-5 py-3">Settings</th>
                                <th className="px-5 py-3">Assigned</th>
                                <th className="px-5 py-3">Status</th>
                                <th className="px-5 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {packages.map((pkg) => (
                                <tr key={pkg.id} className="border-t border-slate-100 align-top">
                                    <td className="px-5 py-4 text-slate-700">
                                        <div className="font-medium">{pkg.name}</div>
                                        <div className="mt-1 text-xs text-slate-500">{formatMoney(pkg.price, currencyCode)}</div>
                                        {pkg.description ? <div className="mt-1 text-xs text-slate-500">{pkg.description}</div> : null}
                                    </td>
                                    <td className="px-5 py-4 text-slate-600">
                                        <div className="flex flex-wrap gap-2">
                                            {(pkg.salon_services || []).map((service) => (
                                                <span key={service.id} className="rounded-full bg-slate-100 px-2 py-1 text-xs text-slate-700">
                                                    {service.name}
                                                    {service.included_sessions ? ` x${service.included_sessions}` : ''}
                                                </span>
                                            ))}
                                        </div>
                                    </td>
                                    <td className="px-5 py-4 text-xs text-slate-600">
                                        <div>Sessions: {pkg.usage_limit ?? 'Unlimited'}</div>
                                        <div className="mt-1">Wallet: {pkg.initial_value !== null ? formatMoney(pkg.initial_value, currencyCode) : 'None'}</div>
                                        <div className="mt-1">Valid for: {pkg.validity_days ? `${pkg.validity_days} days` : 'No expiry'}</div>
                                        <div className="mt-1">Per visit: {pkg.services_per_visit_limit ?? 'No limit'}</div>
                                    </td>
                                    <td className="px-5 py-4 text-slate-600">{pkg.customer_packages_count}</td>
                                    <td className="px-5 py-4 text-slate-600">{pkg.is_active ? 'Active' : 'Inactive'}</td>
                                    <td className="px-5 py-4 text-right">
                                        <div className="flex justify-end gap-2">
                                            <button type="button" onClick={() => startEditPackage(pkg)} className="ta-btn-secondary" disabled={!canManage}>Edit</button>
                                            <button
                                                type="button"
                                                onClick={() => {
                                                    if (!window.confirm(`Delete package "${pkg.name}"?`)) return;
                                                    router.delete(route('loyalty.packages.destroy', pkg.id), { preserveScroll: true });
                                                }}
                                                className="rounded-lg border border-rose-200 px-3 py-2 text-xs font-medium text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                                disabled={!canManage || Number(pkg.customer_packages_count || 0) > 0}
                                                title={Number(pkg.customer_packages_count || 0) > 0 ? 'Packages with assigned customers cannot be deleted.' : 'Delete package'}
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </section>

            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Assign package</h3>
                <form onSubmit={(e) => { e.preventDefault(); assignPackageForm.post(route('loyalty.packages.assign'), { onSuccess: () => assignPackageForm.reset('notes') }); }} className="grid gap-3 md:grid-cols-4">
                    <div><label className="ta-field-label">Customer</label><select className="ta-input" value={assignPackageForm.data.customer_id} onChange={(e) => assignPackageForm.setData('customer_id', e.target.value)} required><option value="">Select customer</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}</select>{fieldError(assignPackageForm, 'customer_id')}</div>
                    <div><label className="ta-field-label">Package</label><select className="ta-input" value={assignPackageForm.data.service_package_id} onChange={(e) => assignPackageForm.setData('service_package_id', e.target.value)} required><option value="">Select package</option>{activePackages.map((pkg) => <option key={pkg.id} value={pkg.id}>{pkg.name} - {formatMoney(pkg.price, currencyCode)}</option>)}</select>{fieldError(assignPackageForm, 'service_package_id')}</div>
                    <div><label className="ta-field-label">Notes</label><input className="ta-input" value={assignPackageForm.data.notes} onChange={(e) => assignPackageForm.setData('notes', e.target.value)} />{fieldError(assignPackageForm, 'notes')}</div>
                    <button className="ta-btn-primary" disabled={assignPackageForm.processing || !canManage}>Assign package</button>
                </form>
            </section>

            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Consume package balance</h3>
                <form onSubmit={(e) => { e.preventDefault(); consumePackageForm.post(route('loyalty.packages.consume', consumePackageForm.data.customer_package_id), { onSuccess: () => consumePackageForm.reset('sessions_used', 'value_used', 'notes') }); }} className="grid gap-3 md:grid-cols-5">
                    <div><label className="ta-field-label">Customer package</label><select className="ta-input" value={consumePackageForm.data.customer_package_id} onChange={(e) => consumePackageForm.setData('customer_package_id', e.target.value)} required><option value="">Select active package</option>{customerPackages.filter((pkg) => pkg.status === 'active').map((pkg) => <option key={pkg.id} value={pkg.id}>{pkg.customer_name} - {pkg.package_name}</option>)}</select>{fieldError(consumePackageForm, 'customer_package_id')}</div>
                    <div><label className="ta-field-label">Sessions used</label><input className="ta-input" type="number" min="0" value={consumePackageForm.data.sessions_used} onChange={(e) => consumePackageForm.setData('sessions_used', e.target.value)} />{fieldError(consumePackageForm, 'sessions_used')}</div>
                    <div><label className="ta-field-label">Value used</label><input className="ta-input" type="number" min="0" step="0.01" value={consumePackageForm.data.value_used} onChange={(e) => consumePackageForm.setData('value_used', e.target.value)} />{fieldError(consumePackageForm, 'value_used')}</div>
                    <div><label className="ta-field-label">Notes</label><input className="ta-input" value={consumePackageForm.data.notes} onChange={(e) => consumePackageForm.setData('notes', e.target.value)} />{fieldError(consumePackageForm, 'notes')}</div>
                    <button className="ta-btn-primary" disabled={consumePackageForm.processing || !canManage || !consumePackageForm.data.customer_package_id}>Consume package</button>
                </form>
            </section>

            <section className="ta-card overflow-hidden">
                <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Customer packages</h3></div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Package</th><th className="px-5 py-3">Sessions</th><th className="px-5 py-3">Value</th><th className="px-5 py-3">Status</th></tr></thead>
                        <tbody>{customerPackages.map((pkg) => <tr key={pkg.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-700">{pkg.customer_name}</td><td className="px-5 py-3 text-slate-600">{pkg.package_name}</td><td className="px-5 py-3 text-slate-600">{pkg.remaining_sessions ?? 'n/a'}</td><td className="px-5 py-3 text-slate-600">{pkg.remaining_value !== null ? formatMoney(pkg.remaining_value, currencyCode) : 'n/a'}</td><td className="px-5 py-3 text-slate-600">{pkg.status}</td></tr>)}</tbody>
                    </table>
                </div>
            </section>
        </div>
    );
}
