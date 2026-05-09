import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

export default function Automation({ tags, customers, contacts, dueServices, recentLogs, segmentRules, campaignTemplates, metaTemplates, campaigns }) {
    const { flash, auth } = usePage().props;
    const canManage = Boolean(auth?.permissions?.can_manage_crm_automation);
    const [editingRuleId, setEditingRuleId] = useState(null);
    const [contactFilter, setContactFilter] = useState('');
    const [editingMetaTemplateId, setEditingMetaTemplateId] = useState(null);

    const tagForm = useForm({ name: '', color: '#4f46e5', is_active: true });
    const assignForm = useForm({ customer_id: '', customer_tag_id: '' });
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

    const filteredContacts = useMemo(() => {
        const needle = contactFilter.trim().toLowerCase();
        if (!needle) {
            return contacts;
        }

        return contacts.filter((contact) =>
            [contact.name, contact.phone, contact.email]
                .filter(Boolean)
                .some((value) => value.toLowerCase().includes(needle))
        );
    }, [contacts, contactFilter]);

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

    return (
        <AuthenticatedLayout header="CRM Automation">
            <Head title="CRM Automation" />

            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Segments (Tags)</h3>
                    <form className="mb-4 grid gap-3 md:grid-cols-4" onSubmit={(e) => { e.preventDefault(); tagForm.post(route('customers.automation.tags.store'), { onSuccess: () => tagForm.reset('name') }); }}>
                        <input className="ta-input" placeholder="Tag name" value={tagForm.data.name} onChange={(e) => tagForm.setData('name', e.target.value)} required />
                        <input className="ta-input" placeholder="#4f46e5" value={tagForm.data.color} onChange={(e) => tagForm.setData('color', e.target.value)} />
                        <label className="flex items-center text-sm text-slate-600"><input type="checkbox" className="mr-2" checked={tagForm.data.is_active} onChange={(e) => tagForm.setData('is_active', e.target.checked)} />Active</label>
                        <button className="ta-btn-primary" disabled={tagForm.processing || !canManage}>Create Tag</button>
                    </form>

                    <div className="mb-4 flex flex-wrap gap-2">{tags.map((tag) => <span key={tag.id} className="rounded-full px-2.5 py-1 text-xs font-semibold text-white" style={{ backgroundColor: tag.color }}>{tag.name}</span>)}</div>

                    <form className="grid gap-3 md:grid-cols-4" onSubmit={(e) => { e.preventDefault(); assignForm.post(route('customers.automation.tags.assign')); }}>
                        <label className="ta-field-label">Customer</label><select className="ta-input" value={assignForm.data.customer_id} onChange={(e) => assignForm.setData('customer_id', e.target.value)} required><option value="">Select customer</option>{customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>
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
                            <tbody>{segmentRules.map((rule) => <tr key={rule.id} className="border-t border-slate-100"><td className="px-4 py-2 text-slate-700">{rule.name}</td><td className="px-4 py-2"><span className="rounded-full px-2 py-0.5 text-xs font-semibold text-white" style={{ backgroundColor: rule.tag_color }}>{rule.tag_name}</span></td><td className="px-4 py-2 text-slate-600">{rule.criteria}</td><td className="px-4 py-2 text-slate-600">{rule.threshold_value}</td><td className="px-4 py-2 text-slate-600">{rule.lookback_days || '-'}</td><td className="px-4 py-2 font-semibold text-slate-700">{rule.preview_count}</td><td className="px-4 py-2 text-slate-600">{rule.last_run_at ? new Date(rule.last_run_at).toLocaleString() : 'Never'}</td><td className="px-4 py-2"><div className="flex flex-wrap gap-2"><button className="rounded-lg border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs text-indigo-700" onClick={() => startEditRule(rule)}>Edit</button><button className="rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-700" onClick={() => router.post(route('customers.automation.segment-rules.preview', rule.id))}>Preview</button><button className="rounded-lg border border-emerald-200 bg-emerald-50 px-2 py-1 text-xs text-emerald-700" onClick={() => router.post(route('customers.automation.segment-rules.run'), { rule_id: rule.id })}>Run</button><button className="rounded-lg border border-red-200 bg-red-50 px-2 py-1 text-xs text-red-700" onClick={() => router.patch(route('customers.automation.segment-rules.deactivate', rule.id))} disabled={!rule.is_active}>Deactivate</button></div></td></tr>)}</tbody>
                        </table>
                    </div>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Customers & Segments</h3></div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Tags</th></tr></thead>
                            <tbody>{customers.map((customer) => <tr key={customer.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-700">{customer.name}</td><td className="px-5 py-3"><div className="flex flex-wrap gap-2">{customer.tags.map((tag) => <button key={tag.id} className="rounded-full px-2.5 py-1 text-xs font-semibold text-white" style={{ backgroundColor: tag.color }} onClick={() => removeTag(customer.id, tag.id)}>{tag.name} Ã—</button>)}{customer.tags.length === 0 && <span className="text-xs text-slate-400">No tags</span>}</div></td></tr>)}</tbody>
                        </table>
                    </div>
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
                                        <td className="px-4 py-2 text-slate-600">{row.reminder_sent_at ? new Date(row.reminder_sent_at).toLocaleString() : '-'}</td>
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
                        <input className="ta-input" type="datetime-local" value={campaignForm.data.scheduled_at} onChange={(e) => campaignForm.setData('scheduled_at', e.target.value)} />
                        <button className="ta-btn-primary md:col-span-6" disabled={campaignForm.processing || !canManage}>Create Campaign</button>
                    </form>

                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-2">Campaign</th><th className="px-4 py-2">Template</th><th className="px-4 py-2">Audience</th><th className="px-4 py-2">Schedule</th><th className="px-4 py-2">Status</th><th className="px-4 py-2">Sent</th><th className="px-4 py-2">Failed</th><th className="px-4 py-2">Actions</th></tr></thead>
                            <tbody>{campaigns.map((c) => <tr key={c.id} className="border-t border-slate-100"><td className="px-4 py-2 text-slate-700">{c.name}</td><td className="px-4 py-2 text-slate-600">{c.template_name}</td><td className="px-4 py-2 text-slate-600">{c.audience_type}{c.tag_name ? ` (${c.tag_name})` : ''}{c.inactivity_days ? ` (${c.inactivity_days}d)` : ''}</td><td className="px-4 py-2 text-slate-600">{c.scheduled_at ? new Date(c.scheduled_at).toLocaleString() : 'Now/manual'}</td><td className="px-4 py-2 text-slate-600">{c.status}</td><td className="px-4 py-2 text-emerald-700">{c.sent_count}</td><td className="px-4 py-2 text-red-700">{c.failed_count}</td><td className="px-4 py-2"><button className="rounded-lg border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs text-indigo-700 disabled:opacity-50" disabled={!canManage} onClick={() => router.post(route('customers.automation.campaigns.dispatch', c.id))}>Dispatch</button></td></tr>)}</tbody>
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
                            <tbody>{metaTemplates.map((template) => <tr key={template.id} className="border-t border-slate-100"><td className="px-4 py-2 text-slate-700">{template.name}</td><td className="px-4 py-2 text-slate-600">{template.language}</td><td className="px-4 py-2 text-slate-600">{template.category || '-'}</td><td className="px-4 py-2 text-slate-600">{template.status || '-'}</td><td className="px-4 py-2 text-slate-600">{template.quality_score || '-'}</td><td className="px-4 py-2 text-slate-600">{template.last_synced_at ? new Date(template.last_synced_at).toLocaleString() : '-'}</td><td className="px-4 py-2"><div className="flex gap-2"><button type="button" className="rounded-lg border border-indigo-200 bg-indigo-50 px-2 py-1 text-xs text-indigo-700" onClick={() => loadMetaTemplateIntoForm(template)}>Edit</button><button type="button" className="rounded-lg border border-red-200 bg-red-50 px-2 py-1 text-xs text-red-700" onClick={() => router.delete(route('customers.automation.whatsapp-templates.destroy', template.id))}>Delete</button></div></td></tr>)}</tbody>
                        </table>
                    </div>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4">
                        <div className="flex items-center justify-between gap-4">
                            <h3 className="text-sm font-semibold text-slate-700">Contact List</h3>
                            <input className="ta-input max-w-sm" placeholder="Search contacts" value={contactFilter} onChange={(e) => setContactFilter(e.target.value)} />
                        </div>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Name</th><th className="px-5 py-3">Phone</th><th className="px-5 py-3">Email</th><th className="px-5 py-3">Tags</th><th className="px-5 py-3">WhatsApp</th><th className="px-5 py-3">Last WA Status</th></tr></thead>
                            <tbody>{filteredContacts.map((contact) => <tr key={contact.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-700">{contact.name}</td><td className="px-5 py-3 text-slate-600">{contact.phone || '-'}</td><td className="px-5 py-3 text-slate-600">{contact.email || '-'}</td><td className="px-5 py-3"><div className="flex flex-wrap gap-2">{contact.tags.map((tag) => <span key={tag.id} className="rounded-full px-2 py-0.5 text-xs font-semibold text-white" style={{ backgroundColor: tag.color }}>{tag.name}</span>)}{contact.tags.length === 0 && <span className="text-xs text-slate-400">No tags</span>}</div></td><td className="px-5 py-3 text-slate-600">{contact.whatsapp_ready ? 'Ready' : 'Invalid / missing'}</td><td className="px-5 py-3 text-slate-600">{contact.last_whatsapp_status || '-'}</td></tr>)}</tbody>
                        </table>
                    </div>
                </section>

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Communication Logs</h3></div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Date</th><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Channel</th><th className="px-5 py-3">Context</th><th className="px-5 py-3">Recipient</th><th className="px-5 py-3">Status</th></tr></thead>
                            <tbody>{recentLogs.map((log) => <tr key={log.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-600">{new Date(log.sent_at || log.created_at).toLocaleString()}</td><td className="px-5 py-3 text-slate-700">{log.customer_name || '-'}</td><td className="px-5 py-3 text-slate-600">{log.channel}</td><td className="px-5 py-3 text-slate-600">{log.context}</td><td className="px-5 py-3 text-slate-600">{log.recipient || '-'}</td><td className="px-5 py-3 text-slate-600">{log.provider_status ? `${log.status} / ${log.provider_status}` : log.status}</td></tr>)}</tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}






