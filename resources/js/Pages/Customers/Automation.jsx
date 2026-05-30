import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

const buildTimeZoneParts = (value, timeZone) => {
    if (!value) {
        return null;
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const formatter = new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    });

    const parts = Object.fromEntries(
        formatter
            .formatToParts(date)
            .filter((part) => part.type !== 'literal')
            .map((part) => [part.type, part.value]),
    );

    return parts.year ? parts : null;
};

const formatDateTimeInZone = (value, timeZone) => {
    const parts = buildTimeZoneParts(value, timeZone);

    if (!parts) {
        return 'N/A';
    }

    const hour = Number(parts.hour || '0');
    const hour12 = hour % 12 || 12;
    const suffix = hour >= 12 ? 'PM' : 'AM';

    return `${parts.year}-${parts.month}-${parts.day}, ${String(hour12).padStart(2, '0')}:${parts.minute} ${suffix}`;
};

export default function Automation({ tags, customerOptions, customers, customerFilters, contacts, contactFilters, dueServices, recentLogs, segmentRules, campaignTemplates, metaTemplates, campaigns }) {
    const { flash, auth, app_timezone: appTimezone = 'Asia/Dubai' } = usePage().props;
    const canManage = Boolean(auth?.permissions?.can_manage_crm_automation);
    const [editingRuleId, setEditingRuleId] = useState(null);
    const [editingMetaTemplateId, setEditingMetaTemplateId] = useState(null);

    const tagForm = useForm({ name: '', color: '#4f46e5', is_active: true });
    const assignForm = useForm({ customer_id: '', customer_tag_id: '' });
    const singleMessageForm = useForm({
        customer_id: '',
        channel: 'whatsapp',
        message: '',
        whatsapp_message_type: 'text',
        whatsapp_template_id: '',
        whatsapp_template_variables: '',
    });
    const customerFilterForm = useForm({
        search: customerFilters?.search || '',
        tag_id: customerFilters?.tag_id || '',
        tag_state: customerFilters?.tag_state || 'all',
        active_status: customerFilters?.active_status || 'all',
        sort: customerFilters?.sort || 'name_asc',
        per_page: String(customerFilters?.per_page || 10),
    });
    const contactFilterForm = useForm({
        search: contactFilters?.search || '',
        tag_id: contactFilters?.tag_id || '',
        tag_state: contactFilters?.tag_state || 'all',
        active_status: contactFilters?.active_status || 'active',
        per_page: String(contactFilters?.per_page || 10),
    });
    const ruleForm = useForm({ name: '', customer_tag_id: '', criteria: 'inactivity_days', threshold_value: '', lookback_days: '', is_active: true });
    const editRuleForm = useForm({ name: '', customer_tag_id: '', criteria: 'inactivity_days', threshold_value: '', lookback_days: '', is_active: true });
    const templateForm = useForm({
        name: '',
        channel: 'sms',
        content: 'Hi {name}, we have a special offer for you.',
        whatsapp_message_type: 'text',
        whatsapp_template_name: '',
        whatsapp_template_language_code: 'en_US',
        is_active: true,
    });
    const campaignForm = useForm({ name: '', campaign_template_id: '', audience_type: 'all', customer_tag_id: '', inactivity_days: '', scheduled_at: '' });
    const metaTemplateForm = useForm({
        name: '',
        language: 'en_US',
        category: 'UTILITY',
        header_type: 'none',
        header_text: '',
        header_example: '',
        header_media_handle: '',
        header_media_file: null,
        body_text: 'Hello {{1}}',
        footer_text: '',
        example_values: 'Customer',
        buttons: [
            { type: 'QUICK_REPLY', text: '', url: '', phone_number: '' },
            { type: 'QUICK_REPLY', text: '', url: '', phone_number: '' },
        ],
    });

    const removeTag = (customerId, tagId) => router.delete(route('customers.automation.tags.remove'), { data: { customer_id: customerId, customer_tag_id: tagId } });

    const startEditRule = (rule) => {
        setEditingRuleId(rule.id);
        editRuleForm.setData({
            name: rule.name,
            customer_tag_id: tags.find((t) => t.name === rule.tag_name)?.id || '',
            criteria: rule.criteria,
            threshold_value: rule.threshold_value,
            lookback_days: rule.lookback_days || '',
            is_active: Boolean(rule.is_active),
        });
        editRuleForm.clearErrors();
    };

    const loadMetaTemplateIntoForm = (template) => {
        const header = template.components?.find((component) => component.type === 'HEADER') || null;
        const body = template.components?.find((component) => component.type === 'BODY') || null;
        const footer = template.components?.find((component) => component.type === 'FOOTER') || null;
        const buttonsComponent = template.components?.find((component) => component.type === 'BUTTONS') || null;
        const buttons = (buttonsComponent?.buttons || []).map((button) => ({
            type: button.type || 'QUICK_REPLY',
            text: button.text || '',
            url: button.url || '',
            phone_number: button.phone_number || '',
        }));

        while (buttons.length < 2) {
            buttons.push({ type: 'QUICK_REPLY', text: '', url: '', phone_number: '' });
        }

        setEditingMetaTemplateId(template.id);
        const headerFormat = header?.format?.toLowerCase() || 'none';
        metaTemplateForm.setData({
            name: template.name,
            language: template.language,
            category: template.category || 'UTILITY',
            header_type: ['text', 'image', 'video', 'document'].includes(headerFormat) ? headerFormat : 'none',
            header_text: header?.text || '',
            header_example: header?.example?.header_text?.[0] || '',
            header_media_handle: header?.example?.header_handle?.[0] || '',
            header_media_file: null,
            body_text: body?.text || '',
            footer_text: footer?.text || '',
            example_values: body?.example?.body_text?.[0]?.join(',') || '',
            buttons,
        });
    };

    const resetMetaTemplateEditor = () => {
        setEditingMetaTemplateId(null);
        metaTemplateForm.reset();
        metaTemplateForm.setData({
            name: '',
            language: 'en_US',
            category: 'UTILITY',
            header_type: 'none',
            header_text: '',
            header_example: '',
            header_media_handle: '',
            header_media_file: null,
            body_text: 'Hello {{1}}',
            footer_text: '',
            example_values: 'Customer',
            buttons: [
                { type: 'QUICK_REPLY', text: '', url: '', phone_number: '' },
                { type: 'QUICK_REPLY', text: '', url: '', phone_number: '' },
            ],
        });
    };

    useEffect(() => {
        if (!flash?.whatsapp_header_media_handle) {
            return;
        }

        metaTemplateForm.setData((data) => ({
            ...data,
            header_media_handle: flash.whatsapp_header_media_handle,
            header_media_file: null,
        }));
    }, [flash?.whatsapp_header_media_handle]);

    useEffect(() => {
        customerFilterForm.setData({
            search: customerFilters?.search || '',
            tag_id: customerFilters?.tag_id || '',
            tag_state: customerFilters?.tag_state || 'all',
            active_status: customerFilters?.active_status || 'all',
            sort: customerFilters?.sort || 'name_asc',
            per_page: String(customerFilters?.per_page || 10),
        });
    }, [customerFilters?.search, customerFilters?.tag_id, customerFilters?.tag_state, customerFilters?.active_status, customerFilters?.sort, customerFilters?.per_page]);

    useEffect(() => {
        contactFilterForm.setData({
            search: contactFilters?.search || '',
            tag_id: contactFilters?.tag_id || '',
            tag_state: contactFilters?.tag_state || 'all',
            active_status: contactFilters?.active_status || 'active',
            per_page: String(contactFilters?.per_page || 10),
        });
    }, [contactFilters?.search, contactFilters?.tag_id, contactFilters?.tag_state, contactFilters?.active_status, contactFilters?.per_page]);

    const applyCustomerFilters = () => {
        router.get(route('customers.automation.index'), {
            ...customerFilterForm.data,
            tag_id: customerFilterForm.data.tag_id || undefined,
        }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const resetCustomerFilters = () => {
        const defaults = {
            search: '',
            tag_id: '',
            tag_state: 'all',
            active_status: 'all',
            sort: 'name_asc',
            per_page: '10',
        };

        customerFilterForm.setData(defaults);

        router.get(route('customers.automation.index'), defaults, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const applyContactFilters = () => {
        router.get(route('customers.automation.index'), {
            ...customerFilterForm.data,
            tag_id: customerFilterForm.data.tag_id || undefined,
            contact_search: contactFilterForm.data.search,
            contact_tag_id: contactFilterForm.data.tag_id || undefined,
            contact_tag_state: contactFilterForm.data.tag_state,
            contact_active_status: contactFilterForm.data.active_status,
            contact_per_page: contactFilterForm.data.per_page,
        }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const resetContactFilters = () => {
        const defaults = {
            search: '',
            tag_id: '',
            tag_state: 'all',
            active_status: 'active',
            per_page: '10',
        };

        contactFilterForm.setData(defaults);

        router.get(route('customers.automation.index'), {
            ...customerFilterForm.data,
            tag_id: customerFilterForm.data.tag_id || undefined,
            contact_search: '',
            contact_tag_id: undefined,
            contact_tag_state: 'all',
            contact_active_status: 'active',
            contact_per_page: '10',
        }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const formatDateTime = (value) => formatDateTimeInZone(value, appTimezone);
    const scheduledDate = campaignForm.data.scheduled_at?.includes('T') ? campaignForm.data.scheduled_at.split('T')[0] : '';
    const scheduledTime = campaignForm.data.scheduled_at?.includes('T') ? campaignForm.data.scheduled_at.split('T')[1].slice(0, 5) : '';
    const campaignTimeOptions = Array.from({ length: 48 }, (_, index) => {
        const hour = String(Math.floor(index / 2)).padStart(2, '0');
        const minute = index % 2 === 0 ? '00' : '30';

        return `${hour}:${minute}`;
    });

    const setScheduledDate = (value) => {
        if (!value) {
            campaignForm.setData('scheduled_at', '');

            return;
        }

        campaignForm.setData('scheduled_at', `${value}T${scheduledTime || '09:00'}`);
    };

    const setScheduledTime = (value) => {
        if (!scheduledDate) {
            return;
        }

        campaignForm.setData('scheduled_at', `${scheduledDate}T${value}`);
    };

    const selectedSingleMessageCustomer = customerOptions.find((customer) => String(customer.id) === String(singleMessageForm.data.customer_id));

    return (
        <AuthenticatedLayout header="CRM Automation">
            <Head title="CRM Automation" />

            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Segments (Tags)</h3>
                    <form className="mb-4 grid gap-3 md:grid-cols-4" onSubmit={(e) => { e.preventDefault(); tagForm.post(route('customers.automation.tags.store'), { onSuccess: () => tagForm.reset('name') }); }}>
                        <input className="ta-input" placeholder="Tag name" value={tagForm.data.name} onChange={(e) => tagForm.setData('name', e.target.value)} required />
                        <div className="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2">
                            <input
                                type="color"
                                className="h-10 w-14 cursor-pointer rounded border-0 bg-transparent p-0"
                                value={tagForm.data.color || '#4f46e5'}
                                onChange={(e) => tagForm.setData('color', e.target.value)}
                                aria-label="Pick tag color"
                            />
                            <input
                                className="min-w-0 flex-1 bg-transparent text-sm text-slate-700 outline-none"
                                placeholder="#4f46e5"
                                value={tagForm.data.color}
                                onChange={(e) => tagForm.setData('color', e.target.value)}
                            />
                        </div>
                        <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={tagForm.data.is_active} onChange={(e) => tagForm.setData('is_active', e.target.checked)} />Active</label>
                        <button className="ta-btn-primary" disabled={tagForm.processing || !canManage}>Create Tag</button>
                    </form>

                    <div className="mb-4 flex flex-wrap gap-2">{tags.map((tag) => <span key={tag.id} className="rounded-full px-2.5 py-1 text-xs font-semibold text-white" style={{ backgroundColor: tag.color }}>{tag.name}</span>)}</div>

                    <form className="grid gap-3 md:grid-cols-4" onSubmit={(e) => { e.preventDefault(); assignForm.post(route('customers.automation.tags.assign')); }}>
                        <label className="ta-field-label">Customer</label><select className="ta-input" value={assignForm.data.customer_id} onChange={(e) => assignForm.setData('customer_id', e.target.value)} required><option value="">Select customer</option>{customerOptions.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>
                        <label className="ta-field-label">Customer Tag</label><select className="ta-input" value={assignForm.data.customer_tag_id} onChange={(e) => assignForm.setData('customer_tag_id', e.target.value)} required><option value="">Select tag</option>{tags.filter((t) => t.is_active).map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select>
                        <button className="ta-btn-primary" disabled={assignForm.processing || !canManage}>Assign Tag</button>
                    </form>
                </section>

                <section className="ta-card p-5">
                    <div className="mb-4 flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-slate-700">Segment Rule Builder (Auto-tag)</h3>
                        <button className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700 disabled:opacity-50" disabled={!canManage} onClick={() => router.post(route('customers.automation.segment-rules.run'))}>Run All Active Rules</button>
                    </div>

                    <form className="mb-4 grid gap-3 md:grid-cols-6" onSubmit={(e) => { e.preventDefault(); ruleForm.post(route('customers.automation.segment-rules.store'), { onSuccess: () => ruleForm.reset('name', 'threshold_value', 'lookback_days') }); }}>
                        <input className="ta-input" placeholder="Rule name" value={ruleForm.data.name} onChange={(e) => ruleForm.setData('name', e.target.value)} required />
                        <label className="ta-field-label">Customer Tag</label><select className="ta-input" value={ruleForm.data.customer_tag_id} onChange={(e) => ruleForm.setData('customer_tag_id', e.target.value)} required><option value="">Target tag</option>{tags.map((tag) => <option key={tag.id} value={tag.id}>{tag.name}</option>)}</select>
                        <label className="ta-field-label">Criteria</label><select className="ta-input" value={ruleForm.data.criteria} onChange={(e) => ruleForm.setData('criteria', e.target.value)}><option value="inactivity_days">Inactivity Days</option><option value="min_spend">Min Spend</option><option value="min_visits">Min Visits</option></select>
                        <input className="ta-input" type="number" min="1" placeholder="Threshold" value={ruleForm.data.threshold_value} onChange={(e) => ruleForm.setData('threshold_value', e.target.value)} required />
                        <input className="ta-input" type="number" min="1" placeholder="Lookback days (opt)" value={ruleForm.data.lookback_days} onChange={(e) => ruleForm.setData('lookback_days', e.target.value)} />
                        <button className="ta-btn-primary" disabled={ruleForm.processing || !canManage}>Create Rule</button>
                    </form>

                    {editingRuleId && (
                        <form className="mb-4 grid gap-3 rounded-xl border border-slate-200 p-3 md:grid-cols-6" onSubmit={(e) => { e.preventDefault(); editRuleForm.put(route('customers.automation.segment-rules.update', editingRuleId), { onSuccess: () => setEditingRuleId(null) }); }}>
                            <input className="ta-input" value={editRuleForm.data.name} onChange={(e) => editRuleForm.setData('name', e.target.value)} required />
                            <label className="ta-field-label">Customer Tag</label><select className="ta-input" value={editRuleForm.data.customer_tag_id} onChange={(e) => editRuleForm.setData('customer_tag_id', e.target.value)} required><option value="">Target tag</option>{tags.map((tag) => <option key={tag.id} value={tag.id}>{tag.name}</option>)}</select>
                            <label className="ta-field-label">Criteria</label><select className="ta-input" value={editRuleForm.data.criteria} onChange={(e) => editRuleForm.setData('criteria', e.target.value)}><option value="inactivity_days">Inactivity Days</option><option value="min_spend">Min Spend</option><option value="min_visits">Min Visits</option></select>
                            <input className="ta-input" type="number" min="1" value={editRuleForm.data.threshold_value} onChange={(e) => editRuleForm.setData('threshold_value', e.target.value)} required />
                            <input className="ta-input" type="number" min="1" value={editRuleForm.data.lookback_days || ''} onChange={(e) => editRuleForm.setData('lookback_days', e.target.value === '' ? null : e.target.value)} />
                            <div className="flex gap-2"><button className="ta-btn-primary" disabled={editRuleForm.processing || !canManage}>Save</button><button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm" onClick={() => setEditingRuleId(null)}>Cancel</button></div>
                        </form>
                    )}

                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-2">Rule</th><th className="px-4 py-2">Tag</th><th className="px-4 py-2">Criteria</th><th className="px-4 py-2">Threshold</th><th className="px-4 py-2">Lookback</th><th className="px-4 py-2">Preview</th><th className="px-4 py-2">Last Run</th><th className="px-4 py-2">Actions</th></tr></thead>
                            <tbody>{segmentRules.map((rule) => <tr key={rule.id} className="border-t border-slate-100"><td className="px-4 py-2 text-slate-700">{rule.name}</td><td className="px-4 py-2"><span className="rounded-full px-2 py-0.5 text-xs font-semibold text-white" style={{ backgroundColor: rule.tag_color }}>{rule.tag_name}</span></td><td className="px-4 py-2 text-slate-600">{rule.criteria}</td><td className="px-4 py-2 text-slate-600">{rule.threshold_value}</td><td className="px-4 py-2 text-slate-600">{rule.lookback_days || '-'}</td><td className="px-4 py-2 font-semibold text-slate-700">{rule.preview_count}</td><td className="px-4 py-2 text-slate-600">{rule.last_run_at ? formatDateTime(rule.last_run_at) : 'Never'}</td><td className="px-4 py-2"><div className="flex flex-wrap gap-2"><button className="rounded-lg border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs text-indigo-700" onClick={() => startEditRule(rule)}>Edit</button><button className="rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-700" onClick={() => router.post(route('customers.automation.segment-rules.preview', rule.id))}>Preview</button><button className="rounded-lg border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs text-emerald-700" onClick={() => router.post(route('customers.automation.segment-rules.run'), { rule_id: rule.id })}>Run</button><button className="rounded-lg border border-red-200 bg-red-50 px-2 py-1 text-xs text-red-700" onClick={() => router.patch(route('customers.automation.segment-rules.deactivate', rule.id))} disabled={!rule.is_active}>Deactivate</button></div></td></tr>)}</tbody>
                        </table>
                    </div>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h3 className="text-sm font-semibold text-slate-700">Customers & Segments</h3>
                            <span className="text-xs text-slate-500">Showing {customers.from || 0}-{customers.to || 0} of {customers.total || 0}</span>
                        </div>
                    </div>
                    <form className="border-b border-slate-100 px-5 py-4" onSubmit={(e) => { e.preventDefault(); applyCustomerFilters(); }}>
                        <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                            <input className="ta-input xl:col-span-2" placeholder="Search name, code, phone, or email" value={customerFilterForm.data.search} onChange={(e) => customerFilterForm.setData('search', e.target.value)} />
                            <select className="ta-input" value={customerFilterForm.data.tag_id} onChange={(e) => customerFilterForm.setData('tag_id', e.target.value)}>
                                <option value="">All tags</option>
                                {tags.map((tag) => <option key={tag.id} value={tag.id}>{tag.name}</option>)}
                            </select>
                            <select className="ta-input" value={customerFilterForm.data.tag_state} onChange={(e) => customerFilterForm.setData('tag_state', e.target.value)}>
                                <option value="all">All tag states</option>
                                <option value="tagged">Tagged only</option>
                                <option value="untagged">Untagged only</option>
                            </select>
                            <select className="ta-input" value={customerFilterForm.data.active_status} onChange={(e) => customerFilterForm.setData('active_status', e.target.value)}>
                                <option value="all">All statuses</option>
                                <option value="active">Active only</option>
                                <option value="inactive">Inactive only</option>
                            </select>
                            <select className="ta-input" value={customerFilterForm.data.sort} onChange={(e) => customerFilterForm.setData('sort', e.target.value)}>
                                <option value="name_asc">Name A-Z</option>
                                <option value="name_desc">Name Z-A</option>
                                <option value="recent">Newest first</option>
                            </select>
                        </div>
                        <div className="mt-3 flex flex-wrap items-center gap-3">
                            <select className="ta-input max-w-[140px]" value={customerFilterForm.data.per_page} onChange={(e) => customerFilterForm.setData('per_page', e.target.value)}>
                                <option value="10">10 / page</option>
                                <option value="25">25 / page</option>
                                <option value="50">50 / page</option>
                                <option value="100">100 / page</option>
                            </select>
                            <button className="ta-btn-primary" type="submit">Apply Filters</button>
                            <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm text-slate-700" onClick={resetCustomerFilters}>Reset</button>
                        </div>
                    </form>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Contact</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Tags</th></tr></thead>
                            <tbody>
                                {customers.data.map((customer) => (
                                    <tr key={customer.id} className="border-t border-slate-100">
                                        <td className="px-5 py-3">
                                            <div className="font-medium text-slate-800">{customer.name}</div>
                                            <div className="text-xs text-slate-500">{customer.customer_code || 'No code'}</div>
                                        </td>
                                        <td className="px-5 py-3 text-slate-600">
                                            <div>{customer.phone || 'No phone'}</div>
                                            <div className="text-xs text-slate-500">{customer.email || 'No email'}</div>
                                        </td>
                                        <td className="px-5 py-3">
                                            <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${customer.is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700'}`}>
                                                {customer.is_active ? 'Active' : 'Inactive'}
                                            </span>
                                        </td>
                                        <td className="px-5 py-3">
                                            <div className="flex flex-wrap gap-2">
                                                {customer.tags.map((tag) => <button key={tag.id} className="rounded-full px-2.5 py-1 text-xs font-semibold text-white" style={{ backgroundColor: tag.color }} onClick={() => removeTag(customer.id, tag.id)}>{tag.name} ×</button>)}
                                                {customer.tags.length === 0 && <span className="text-xs text-slate-400">No tags</span>}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                                {customers.data.length === 0 && (
                                    <tr>
                                        <td colSpan="4" className="px-5 py-8 text-center text-sm text-slate-500">No customers match the current filters.</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                    {customers.links?.length > 3 && (
                        <div className="flex flex-wrap gap-2 border-t border-slate-100 px-5 py-3 text-sm">
                            {customers.links.map((link, i) =>
                                link.url ? (
                                    <Link key={i} href={link.url} className={`rounded-lg px-3 py-1 ${link.active ? 'bg-indigo-100 text-indigo-800' : 'bg-slate-100 text-slate-600'}`} preserveScroll preserveState dangerouslySetInnerHTML={{ __html: link.label }} />
                                ) : (
                                    <span key={i} className="px-3 py-1 text-slate-400" dangerouslySetInnerHTML={{ __html: link.label }} />
                                ),
                            )}
                        </div>
                    )}
                </section>

                <section className="ta-card p-5">
                    <div className="mb-4 flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-slate-700">Due Service Reminders</h3>
                        <button className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700 disabled:opacity-50" disabled={!canManage} onClick={() => router.post(route('customers.automation.due-services.generate'))}>Generate Due Services</button>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-2">Customer</th><th className="px-4 py-2">Service</th><th className="px-4 py-2">Due Date</th><th className="px-4 py-2">Reminder</th><th className="px-4 py-2">Status</th><th className="px-4 py-2">Actions</th></tr></thead>
                            <tbody>
                                {dueServices.map((row) => (
                                    <tr key={row.id} className="border-t border-slate-100">
                                        <td className="px-4 py-2 text-slate-700">{row.customer_name}</td>
                                        <td className="px-4 py-2 text-slate-600">{row.service_name}</td>
                                        <td className="px-4 py-2 text-slate-600">{row.due_date}</td>
                                        <td className="px-4 py-2 text-slate-600">{row.reminder_sent_at ? formatDateTime(row.reminder_sent_at) : '-'}</td>
                                        <td className="px-4 py-2 text-slate-600">{row.status}</td>
                                        <td className="px-4 py-2"><div className="flex gap-2"><button className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs text-indigo-700" onClick={() => router.post(route('customers.automation.due-services.remind', row.id), { channel: 'sms' })}>Remind SMS</button><button className="rounded-lg border border-violet-200 bg-violet-50 px-2.5 py-1 text-xs text-violet-700" onClick={() => router.post(route('customers.automation.due-services.remind', row.id), { channel: 'whatsapp' })}>WhatsApp</button><button className="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs text-amber-700" onClick={() => router.post(route('customers.automation.due-services.remind', row.id), { channel: 'sms', policy: 'fallback_email' })}>SMS?Email</button><button className="rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs text-emerald-700" onClick={() => router.patch(route('customers.automation.due-services.status', row.id), { status: 'booked' })}>Booked</button><button className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs text-red-700" onClick={() => router.patch(route('customers.automation.due-services.status', row.id), { status: 'dismissed' })}>Dismiss</button></div></td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>


                <section className="ta-card p-5">
                    <div className="mb-4 flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-slate-700">Campaign Templates & Scheduling</h3>
                        <button className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700 disabled:opacity-50" disabled={!canManage} onClick={() => router.post(route('customers.automation.campaigns.run-scheduled'))}>Run Due Scheduled</button>
                    </div>

                    <form className="mb-4 grid gap-3 md:grid-cols-4" onSubmit={(e) => { e.preventDefault(); templateForm.post(route('customers.automation.campaign-templates.store'), { onSuccess: () => templateForm.reset('name', 'content', 'whatsapp_template_name') }); }}>
                        <input className="ta-input" placeholder="Template name" value={templateForm.data.name} onChange={(e) => templateForm.setData('name', e.target.value)} required />
                        <label className="ta-field-label">Channel</label><select className="ta-input" value={templateForm.data.channel} onChange={(e) => templateForm.setData('channel', e.target.value)}><option value="sms">SMS</option><option value="email">Email</option><option value="whatsapp">WhatsApp</option></select>
                        <input className="ta-input md:col-span-2" placeholder="Message (use {name})" value={templateForm.data.content} onChange={(e) => templateForm.setData('content', e.target.value)} required={templateForm.data.channel !== 'whatsapp' || templateForm.data.whatsapp_message_type !== 'template'} />
                        {templateForm.data.channel === 'whatsapp' && (
                            <>
                                <label className="ta-field-label">WhatsApp type</label>
                                <select className="ta-input" value={templateForm.data.whatsapp_message_type} onChange={(e) => templateForm.setData('whatsapp_message_type', e.target.value)}>
                                    <option value="text">Text</option>
                                    <option value="template">Meta Template</option>
                                </select>
                                <input className="ta-input" placeholder="Meta template name" value={templateForm.data.whatsapp_template_name} onChange={(e) => templateForm.setData('whatsapp_template_name', e.target.value)} disabled={templateForm.data.whatsapp_message_type !== 'template'} required={templateForm.data.whatsapp_message_type === 'template'} />
                                <input className="ta-input" placeholder="Language code" value={templateForm.data.whatsapp_template_language_code} onChange={(e) => templateForm.setData('whatsapp_template_language_code', e.target.value)} disabled={templateForm.data.whatsapp_message_type !== 'template'} />
                            </>
                        )}
                        <button className="ta-btn-primary md:col-span-4" disabled={templateForm.processing || !canManage}>Create Template</button>
                    </form>

                    <form className="mb-4 grid gap-3 md:grid-cols-6" onSubmit={(e) => { e.preventDefault(); campaignForm.post(route('customers.automation.campaigns.store'), { onSuccess: () => campaignForm.reset('name', 'scheduled_at') }); }}>
                        <input className="ta-input" placeholder="Campaign name" value={campaignForm.data.name} onChange={(e) => campaignForm.setData('name', e.target.value)} required />
                        <label className="ta-field-label">Campaign Template</label><select className="ta-input" value={campaignForm.data.campaign_template_id} onChange={(e) => campaignForm.setData('campaign_template_id', e.target.value)} required><option value="">Template</option>{campaignTemplates.filter((t) => t.is_active).map((t) => <option key={t.id} value={t.id}>{t.name} ({t.channel})</option>)}</select>
                        <label className="ta-field-label">Audience Type</label><select className="ta-input" value={campaignForm.data.audience_type} onChange={(e) => campaignForm.setData('audience_type', e.target.value)}><option value="all">All active customers</option><option value="tag">By tag</option><option value="due_service">Due services</option><option value="inactivity_days">Inactivity days</option></select>
                        <label className="ta-field-label">Customer Tag</label><select className="ta-input" value={campaignForm.data.customer_tag_id} onChange={(e) => campaignForm.setData('customer_tag_id', e.target.value)} disabled={campaignForm.data.audience_type !== 'tag'}><option value="">Tag (if tag audience)</option>{tags.map((tag) => <option key={tag.id} value={tag.id}>{tag.name}</option>)}</select>
                        <input className="ta-input" type="number" min="1" placeholder="Inactivity days" value={campaignForm.data.inactivity_days} onChange={(e) => campaignForm.setData('inactivity_days', e.target.value)} disabled={campaignForm.data.audience_type !== 'inactivity_days'} />
                        <div className="grid gap-2 md:col-span-2 md:grid-cols-2">
                            <input className="ta-input" type="date" value={scheduledDate} onChange={(e) => setScheduledDate(e.target.value)} />
                            <select className="ta-input" value={scheduledTime} onChange={(e) => setScheduledTime(e.target.value)} disabled={!scheduledDate}>
                                <option value="">{scheduledDate ? 'Select Dubai time' : 'Pick date first'}</option>
                                {campaignTimeOptions.map((time) => <option key={time} value={time}>{time} Dubai time</option>)}
                            </select>
                        </div>
                        <button className="ta-btn-primary md:col-span-6" disabled={campaignForm.processing || !canManage}>Create Campaign</button>
                    </form>

                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-2">Campaign</th><th className="px-4 py-2">Template</th><th className="px-4 py-2">Audience</th><th className="px-4 py-2">Schedule</th><th className="px-4 py-2">Status</th><th className="px-4 py-2">Sent</th><th className="px-4 py-2">Failed</th><th className="px-4 py-2">Actions</th></tr></thead>
                            <tbody>{campaigns.map((c) => <tr key={c.id} className="border-t border-slate-100"><td className="px-4 py-2 text-slate-700">{c.name}</td><td className="px-4 py-2 text-slate-600">{c.template_name}</td><td className="px-4 py-2 text-slate-600">{c.audience_type}{c.tag_name ? ` (${c.tag_name})` : ''}{c.inactivity_days ? ` (${c.inactivity_days}d)` : ''}</td><td className="px-4 py-2 text-slate-600">{c.scheduled_at ? formatDateTime(c.scheduled_at) : 'Now/manual'}</td><td className="px-4 py-2 text-slate-600">{c.status}</td><td className="px-4 py-2 text-emerald-700">{c.sent_count}</td><td className="px-4 py-2 text-red-700">{c.failed_count}</td><td className="px-4 py-2"><button className="rounded-lg border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs text-indigo-700 disabled:opacity-50" disabled={!canManage} onClick={() => router.post(route('customers.automation.campaigns.dispatch', c.id))}>Dispatch</button></td></tr>)}</tbody>
                        </table>
                    </div>
                </section>

                <section className="ta-card p-5">
                    <div className="mb-4 flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-slate-700">Meta Template Manager</h3>
                        <button className="rounded-xl border border-slate-200 px-3 py-1.5 text-xs text-slate-700 disabled:opacity-50" disabled={!canManage} onClick={() => router.post(route('customers.automation.whatsapp-templates.sync'))}>Sync From Meta</button>
                    </div>

                    <form className="mb-4 grid gap-3 md:grid-cols-2" onSubmit={(e) => {
                        e.preventDefault();
                        const routeName = editingMetaTemplateId
                            ? route('customers.automation.whatsapp-templates.update', editingMetaTemplateId)
                            : route('customers.automation.whatsapp-templates.store');
                        const action = editingMetaTemplateId ? metaTemplateForm.put : metaTemplateForm.post;
                        action(routeName, { onSuccess: () => resetMetaTemplateEditor() });
                    }}>
                        <input className="ta-input" placeholder="template_name" value={metaTemplateForm.data.name} onChange={(e) => metaTemplateForm.setData('name', e.target.value.toLowerCase())} required />
                        <select className="ta-input" value={metaTemplateForm.data.category} onChange={(e) => metaTemplateForm.setData('category', e.target.value)}>
                            <option value="UTILITY">UTILITY</option>
                            <option value="MARKETING">MARKETING</option>
                            <option value="AUTHENTICATION">AUTHENTICATION</option>
                        </select>
                        <input className="ta-input" placeholder="en_US" value={metaTemplateForm.data.language} onChange={(e) => metaTemplateForm.setData('language', e.target.value)} required />
                        <input className="ta-input" placeholder="Example values comma-separated" value={metaTemplateForm.data.example_values} onChange={(e) => metaTemplateForm.setData('example_values', e.target.value)} />
                        <select className="ta-input" value={metaTemplateForm.data.header_type} onChange={(e) => metaTemplateForm.setData('header_type', e.target.value)}>
                            <option value="none">No header</option>
                            <option value="text">Text header</option>
                            <option value="image">Image header</option>
                            <option value="video">Video header</option>
                            <option value="document">Document header</option>
                        </select>
                        <input className="ta-input" placeholder="Header text" value={metaTemplateForm.data.header_text} onChange={(e) => metaTemplateForm.setData('header_text', e.target.value)} disabled={metaTemplateForm.data.header_type !== 'text'} />
                        <input className="ta-input md:col-span-2" placeholder="Header example value" value={metaTemplateForm.data.header_example} onChange={(e) => metaTemplateForm.setData('header_example', e.target.value)} disabled={metaTemplateForm.data.header_type !== 'text'} />
                        {['image', 'video', 'document'].includes(metaTemplateForm.data.header_type) && (
                            <>
                                <input className="ta-input md:col-span-2" placeholder="Meta header handle" value={metaTemplateForm.data.header_media_handle} onChange={(e) => metaTemplateForm.setData('header_media_handle', e.target.value)} required />
                                <input className="ta-input md:col-span-2" type="file" accept={metaTemplateForm.data.header_type === 'image' ? 'image/*' : metaTemplateForm.data.header_type === 'video' ? 'video/*' : '.pdf,.doc,.docx,.txt,.xls,.xlsx,.ppt,.pptx'} onChange={(e) => metaTemplateForm.setData('header_media_file', e.target.files?.[0] || null)} />
                                <div className="flex items-center gap-2 md:col-span-2">
                                    <button
                                        type="button"
                                        className="rounded-xl border border-slate-200 px-4 py-2 text-sm text-slate-700 disabled:opacity-50"
                                        disabled={metaTemplateForm.processing || !canManage || !metaTemplateForm.data.header_media_file}
                                        onClick={() => metaTemplateForm.post(route('customers.automation.whatsapp-templates.header-media'), {
                                            forceFormData: true,
                                            preserveScroll: true,
                                            onSuccess: () => metaTemplateForm.setData('header_media_file', null),
                                        })}
                                    >
                                        Upload Sample Media to Meta
                                    </button>
                                    <span className="text-xs text-slate-500">Upload a sample file first, then use the returned handle in the template.</span>
                                </div>
                            </>
                        )}
                        <textarea className="ta-input md:col-span-2" rows="3" placeholder="Template body, e.g. Hello {{1}}, your appointment is due." value={metaTemplateForm.data.body_text} onChange={(e) => metaTemplateForm.setData('body_text', e.target.value)} required />
                        <input className="ta-input md:col-span-2" placeholder="Footer text (optional)" value={metaTemplateForm.data.footer_text} onChange={(e) => metaTemplateForm.setData('footer_text', e.target.value)} />
                        {metaTemplateForm.data.buttons.map((button, index) => (
                            <div key={index} className="rounded-xl border border-slate-200 p-3 md:col-span-2">
                                <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Button {index + 1}</div>
                                <div className="grid gap-3 md:grid-cols-3">
                                    <select className="ta-input" value={button.type} onChange={(e) => metaTemplateForm.setData('buttons', metaTemplateForm.data.buttons.map((item, itemIndex) => itemIndex === index ? { ...item, type: e.target.value } : item))}>
                                        <option value="QUICK_REPLY">Quick Reply</option>
                                        <option value="URL">URL</option>
                                        <option value="PHONE_NUMBER">Phone Number</option>
                                    </select>
                                    <input className="ta-input" placeholder="Button text" value={button.text} onChange={(e) => metaTemplateForm.setData('buttons', metaTemplateForm.data.buttons.map((item, itemIndex) => itemIndex === index ? { ...item, text: e.target.value } : item))} />
                                    <input className="ta-input" placeholder={button.type === 'URL' ? 'https://example.com' : button.type === 'PHONE_NUMBER' ? '+923001234567' : 'Not used for quick reply'} value={button.type === 'URL' ? button.url : button.type === 'PHONE_NUMBER' ? button.phone_number : ''} onChange={(e) => metaTemplateForm.setData('buttons', metaTemplateForm.data.buttons.map((item, itemIndex) => itemIndex === index ? { ...item, url: button.type === 'URL' ? e.target.value : '', phone_number: button.type === 'PHONE_NUMBER' ? e.target.value : '' } : item))} disabled={button.type === 'QUICK_REPLY'} />
                                </div>
                            </div>
                        ))}
                        <div className="md:col-span-2 flex gap-2">
                            <button className="ta-btn-primary" disabled={metaTemplateForm.processing || !canManage}>{editingMetaTemplateId ? 'Replace Meta Template' : 'Create Meta Template'}</button>
                            {editingMetaTemplateId && <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm text-slate-700" onClick={resetMetaTemplateEditor}>Cancel</button>}
                        </div>
                    </form>

                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-2">Name</th><th className="px-4 py-2">Language</th><th className="px-4 py-2">Category</th><th className="px-4 py-2">Status</th><th className="px-4 py-2">Quality</th><th className="px-4 py-2">Last Sync</th><th className="px-4 py-2">Actions</th></tr></thead>
                            <tbody>{metaTemplates.map((template) => <tr key={template.id} className="border-t border-slate-100"><td className="px-4 py-2 text-slate-700">{template.name}</td><td className="px-4 py-2 text-slate-600">{template.language}</td><td className="px-4 py-2 text-slate-600">{template.category || '-'}</td><td className="px-4 py-2 text-slate-600">{template.status || '-'}</td><td className="px-4 py-2 text-slate-600">{template.quality_score || '-'}</td><td className="px-4 py-2 text-slate-600">{template.last_synced_at ? formatDateTime(template.last_synced_at) : '-'}</td><td className="px-4 py-2"><div className="flex gap-2"><button type="button" className="rounded-lg border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs text-indigo-700" onClick={() => loadMetaTemplateIntoForm(template)}>Edit</button><button type="button" className="rounded-lg border border-red-200 bg-red-50 px-2 py-1 text-xs text-red-700" onClick={() => router.delete(route('customers.automation.whatsapp-templates.destroy', template.id))}>Delete</button></div></td></tr>)}</tbody>
                        </table>
                    </div>
                </section>

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Send Single Message</h3>
                    <form
                        className="grid gap-3 md:grid-cols-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            singleMessageForm.post(route('customers.automation.messages.single'), {
                                preserveScroll: true,
                                onSuccess: () => singleMessageForm.reset('message', 'whatsapp_template_variables'),
                            });
                        }}
                    >
                        <select className="ta-input" value={singleMessageForm.data.customer_id} onChange={(e) => singleMessageForm.setData('customer_id', e.target.value)} required>
                            <option value="">Select contact</option>
                            {customerOptions.map((customer) => <option key={customer.id} value={customer.id}>{customer.name}</option>)}
                        </select>
                        <select className="ta-input" value={singleMessageForm.data.channel} onChange={(e) => singleMessageForm.setData('channel', e.target.value)}>
                            <option value="whatsapp">WhatsApp</option>
                            <option value="sms">SMS</option>
                            <option value="email">Email</option>
                        </select>
                        {singleMessageForm.data.channel === 'whatsapp' && (
                            <>
                                <select className="ta-input" value={singleMessageForm.data.whatsapp_message_type} onChange={(e) => singleMessageForm.setData('whatsapp_message_type', e.target.value)}>
                                    <option value="text">WhatsApp text</option>
                                    <option value="template">WhatsApp template</option>
                                </select>
                                {singleMessageForm.data.whatsapp_message_type === 'template' && (
                                    <select className="ta-input" value={singleMessageForm.data.whatsapp_template_id} onChange={(e) => singleMessageForm.setData('whatsapp_template_id', e.target.value)} required>
                                        <option value="">Select Meta template</option>
                                        {metaTemplates.map((template) => <option key={template.id} value={template.id}>{template.name} ({template.language})</option>)}
                                    </select>
                                )}
                            </>
                        )}
                        <textarea
                            className="ta-input md:col-span-2"
                            rows="4"
                            placeholder={singleMessageForm.data.whatsapp_message_type === 'template' ? 'Optional internal note for this send. Leave blank for template-only send.' : 'Write your message'}
                            value={singleMessageForm.data.message}
                            onChange={(e) => singleMessageForm.setData('message', e.target.value)}
                        />
                        {singleMessageForm.data.channel === 'whatsapp' && singleMessageForm.data.whatsapp_message_type === 'template' && (
                            <input
                                className="ta-input md:col-span-2"
                                placeholder="Template variables, comma-separated"
                                value={singleMessageForm.data.whatsapp_template_variables}
                                onChange={(e) => singleMessageForm.setData('whatsapp_template_variables', e.target.value)}
                            />
                        )}
                        <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600 md:col-span-2">
                            Recipient: {selectedSingleMessageCustomer ? selectedSingleMessageCustomer.name : 'Select a contact'}.
                            {singleMessageForm.data.channel === 'email' ? ' The customer email will be used.' : ' The customer phone number will be used.'}
                        </div>
                        <div className="md:col-span-2">
                            <button className="ta-btn-primary" disabled={singleMessageForm.processing || !canManage}>Send Message</button>
                        </div>
                    </form>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <h3 className="text-sm font-semibold text-slate-700">Contact List</h3>
                            <span className="text-xs text-slate-500">Showing {contacts.from || 0}-{contacts.to || 0} of {contacts.total || 0}</span>
                        </div>
                    </div>
                    <form className="border-b border-slate-100 px-5 py-4" onSubmit={(e) => { e.preventDefault(); applyContactFilters(); }}>
                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                            <input className="ta-input xl:col-span-2" placeholder="Search name, code, phone, or email" value={contactFilterForm.data.search} onChange={(e) => contactFilterForm.setData('search', e.target.value)} />
                            <select className="ta-input" value={contactFilterForm.data.tag_id} onChange={(e) => contactFilterForm.setData('tag_id', e.target.value)}>
                                <option value="">All tags</option>
                                {tags.map((tag) => <option key={tag.id} value={tag.id}>{tag.name}</option>)}
                            </select>
                            <select className="ta-input" value={contactFilterForm.data.tag_state} onChange={(e) => contactFilterForm.setData('tag_state', e.target.value)}>
                                <option value="all">All tag states</option>
                                <option value="tagged">Tagged only</option>
                                <option value="untagged">Untagged only</option>
                            </select>
                            <select className="ta-input" value={contactFilterForm.data.active_status} onChange={(e) => contactFilterForm.setData('active_status', e.target.value)}>
                                <option value="active">Active only</option>
                                <option value="all">All statuses</option>
                                <option value="inactive">Inactive only</option>
                            </select>
                        </div>
                        <div className="mt-3 flex flex-wrap items-center gap-3">
                            <select className="ta-input max-w-[140px]" value={contactFilterForm.data.per_page} onChange={(e) => contactFilterForm.setData('per_page', e.target.value)}>
                                <option value="10">10 / page</option>
                                <option value="25">25 / page</option>
                                <option value="50">50 / page</option>
                                <option value="100">100 / page</option>
                            </select>
                            <button className="ta-btn-primary" type="submit">Apply Filters</button>
                            <button type="button" className="rounded-xl border border-slate-200 px-4 py-2 text-sm text-slate-700" onClick={resetContactFilters}>Reset</button>
                        </div>
                    </form>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Name</th><th className="px-5 py-3">Phone</th><th className="px-5 py-3">Email</th><th className="px-5 py-3">Tags</th><th className="px-5 py-3">WhatsApp</th><th className="px-5 py-3">Last WA Status</th></tr></thead>
                            <tbody>
                                {contacts.data.map((contact) => <tr key={contact.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-700"><div className="font-medium text-slate-800">{contact.name}</div><div className="text-xs text-slate-500">{contact.customer_code || 'No code'}</div></td><td className="px-5 py-3 text-slate-600">{contact.phone || '-'}</td><td className="px-5 py-3 text-slate-600">{contact.email || '-'}</td><td className="px-5 py-3"><div className="flex flex-wrap gap-2">{contact.tags.map((tag) => <span key={tag.id} className="rounded-full px-2 py-0.5 text-xs font-semibold text-white" style={{ backgroundColor: tag.color }}>{tag.name}</span>)}{contact.tags.length === 0 && <span className="text-xs text-slate-400">No tags</span>}</div></td><td className="px-5 py-3 text-slate-600">{contact.whatsapp_ready ? 'Ready' : 'Invalid / missing'}</td><td className="px-5 py-3 text-slate-600">{contact.last_whatsapp_status || '-'}</td></tr>)}
                                {contacts.data.length === 0 && (
                                    <tr>
                                        <td colSpan="6" className="px-5 py-8 text-center text-sm text-slate-500">No contacts match the current filters.</td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                    {contacts.links?.length > 3 && (
                        <div className="flex flex-wrap gap-2 border-t border-slate-100 px-5 py-3 text-sm">
                            {contacts.links.map((link, i) =>
                                link.url ? (
                                    <Link key={i} href={link.url} className={`rounded-lg px-3 py-1 ${link.active ? 'bg-indigo-100 text-indigo-800' : 'bg-slate-100 text-slate-600'}`} preserveScroll preserveState dangerouslySetInnerHTML={{ __html: link.label }} />
                                ) : (
                                    <span key={i} className="px-3 py-1 text-slate-400" dangerouslySetInnerHTML={{ __html: link.label }} />
                                ),
                            )}
                        </div>
                    )}
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Communication Logs</h3></div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Date</th><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Channel</th><th className="px-5 py-3">Context</th><th className="px-5 py-3">Recipient</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Details</th></tr></thead>
                            <tbody>{recentLogs.map((log) => <tr key={log.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-600">{formatDateTime(log.accepted_at || log.sent_at || log.failed_at || log.queued_at || log.created_at)}</td><td className="px-5 py-3 text-slate-700">{log.customer_name || '-'}</td><td className="px-5 py-3 text-slate-600">{log.channel}</td><td className="px-5 py-3 text-slate-600">{log.context}</td><td className="px-5 py-3 text-slate-600">{log.recipient || '-'}</td><td className="px-5 py-3 text-slate-600">{log.provider_status ? `${log.status} / ${log.provider_status}` : log.status}</td><td className="max-w-xs px-5 py-3 text-xs text-slate-500">{log.error_message ? <span className="font-semibold text-red-600">{log.error_message}</span> : <span>{log.provider_message_id ? `Meta ID: ${log.provider_message_id}` : log.provider || '-'}</span>}{Number(log.attempt_count || 0) > 0 ? <div className="mt-1">Attempts: {log.attempt_count}</div> : null}</td></tr>)}</tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}






