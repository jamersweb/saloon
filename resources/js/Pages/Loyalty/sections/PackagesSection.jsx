export default function PackagesSection({
    fieldError,
    canManage,
    packageForm,
    assignPackageForm,
    consumePackageForm,
    customers,
    packages,
    customerPackages,
}) {
    return (
        <div className="space-y-6">
            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Create service package</h3>
                <form onSubmit={(e) => { e.preventDefault(); packageForm.post(route('loyalty.packages.store'), { onSuccess: () => packageForm.reset('name', 'description', 'usage_limit', 'initial_value', 'validity_days') }); }} className="grid gap-3 md:grid-cols-6">
                    <div><label className="ta-field-label">Name</label><input className="ta-input" value={packageForm.data.name} onChange={(e) => packageForm.setData('name', e.target.value)} required />{fieldError(packageForm, 'name')}</div>
                    <div><label className="ta-field-label">Description</label><input className="ta-input" value={packageForm.data.description} onChange={(e) => packageForm.setData('description', e.target.value)} />{fieldError(packageForm, 'description')}</div>
                    <div><label className="ta-field-label">Usage limit</label><input className="ta-input" type="number" min="1" value={packageForm.data.usage_limit} onChange={(e) => packageForm.setData('usage_limit', e.target.value)} />{fieldError(packageForm, 'usage_limit')}</div>
                    <div><label className="ta-field-label">Initial value</label><input className="ta-input" type="number" min="0" step="0.01" value={packageForm.data.initial_value} onChange={(e) => packageForm.setData('initial_value', e.target.value)} />{fieldError(packageForm, 'initial_value')}</div>
                    <div><label className="ta-field-label">Validity days</label><input className="ta-input" type="number" min="1" value={packageForm.data.validity_days} onChange={(e) => packageForm.setData('validity_days', e.target.value)} />{fieldError(packageForm, 'validity_days')}</div>
                    <button className="ta-btn-primary" disabled={packageForm.processing || !canManage}>Create package</button>
                </form>
            </section>

            <section className="ta-card p-5">
                <h3 className="mb-4 text-sm font-semibold text-slate-700">Assign package</h3>
                <form onSubmit={(e) => { e.preventDefault(); assignPackageForm.post(route('loyalty.packages.assign'), { onSuccess: () => assignPackageForm.reset('notes') }); }} className="grid gap-3 md:grid-cols-4">
                    <div><label className="ta-field-label">Customer</label><select className="ta-input" value={assignPackageForm.data.customer_id} onChange={(e) => assignPackageForm.setData('customer_id', e.target.value)} required><option value="">Select customer</option>{customers.map((customer) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}</select>{fieldError(assignPackageForm, 'customer_id')}</div>
                    <div><label className="ta-field-label">Package</label><select className="ta-input" value={assignPackageForm.data.service_package_id} onChange={(e) => assignPackageForm.setData('service_package_id', e.target.value)} required><option value="">Select package</option>{packages.filter((pkg) => pkg.is_active).map((pkg) => <option key={pkg.id} value={pkg.id}>{pkg.name}</option>)}</select>{fieldError(assignPackageForm, 'service_package_id')}</div>
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
                        <tbody>{customerPackages.map((pkg) => <tr key={pkg.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-700">{pkg.customer_name}</td><td className="px-5 py-3 text-slate-600">{pkg.package_name}</td><td className="px-5 py-3 text-slate-600">{pkg.remaining_sessions ?? 'n/a'}</td><td className="px-5 py-3 text-slate-600">{pkg.remaining_value ?? 'n/a'}</td><td className="px-5 py-3 text-slate-600">{pkg.status}</td></tr>)}</tbody>
                    </table>
                </div>
            </section>
        </div>
    );
}
