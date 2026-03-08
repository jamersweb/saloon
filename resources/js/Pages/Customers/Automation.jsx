import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function Automation({ tags, customers, dueServices, recentLogs, segmentRules, campaignTemplates, campaigns }) {
    const { flash, auth } = usePage().props;
    const canManage = Boolean(auth?.permissions?.can_manage_crm_automation);
    const [editingRuleId, setEditingRuleId] = useState(null);

    const tagForm = useForm({ name: '', color: '#4f46e5', is_active: true });
    const assignForm = useForm({ customer_id: '', customer_tag_id: '' });
    const ruleForm = useForm({ name: '', customer_tag_id: '', criteria: 'inactivity_days', threshold_value: '', lookback_days: '', is_active: true });
    const editRuleForm = useForm({ name: '', customer_tag_id: '', criteria: 'inactivity_days', threshold_value: '', lookback_days: '', is_active: true });
    const templateForm = useForm({ name: '', channel: 'sms', content: 'Hi {name}, we have a special offer for you.', is_active: true });
    const campaignForm = useForm({ name: '', campaign_template_id: '', audience_type: 'all', customer_tag_id: '', inactivity_days: '', scheduled_at: '' });

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
                        <select className="ta-input" value={assignForm.data.customer_id} onChange={(e) => assignForm.setData('customer_id', e.target.value)} required><option value="">Select customer</option>{customers.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}</select>
                        <select className="ta-input" value={assignForm.data.customer_tag_id} onChange={(e) => assignForm.setData('customer_tag_id', e.target.value)} required><option value="">Select tag</option>{tags.filter((t) => t.is_active).map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}</select>
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
                        <select className="ta-input" value={ruleForm.data.customer_tag_id} onChange={(e) => ruleForm.setData('customer_tag_id', e.target.value)} required><option value="">Target tag</option>{tags.map((tag) => <option key={tag.id} value={tag.id}>{tag.name}</option>)}</select>
                        <select className="ta-input" value={ruleForm.data.criteria} onChange={(e) => ruleForm.setData('criteria', e.target.value)}><option value="inactivity_days">Inactivity Days</option><option value="min_spend">Min Spend</option><option value="min_visits">Min Visits</option></select>
                        <input className="ta-input" type="number" min="1" placeholder="Threshold" value={ruleForm.data.threshold_value} onChange={(e) => ruleForm.setData('threshold_value', e.target.value)} required />
                        <input className="ta-input" type="number" min="1" placeholder="Lookback days (opt)" value={ruleForm.data.lookback_days} onChange={(e) => ruleForm.setData('lookback_days', e.target.value)} />
                        <button className="ta-btn-primary" disabled={ruleForm.processing || !canManage}>Create Rule</button>
                    </form>

                    {editingRuleId && (
                        <form className="mb-4 grid gap-3 rounded-xl border border-slate-200 p-3 md:grid-cols-6" onSubmit={(e) => { e.preventDefault(); editRuleForm.put(route('customers.automation.segment-rules.update', editingRuleId), { onSuccess: () => setEditingRuleId(null) }); }}>
                            <input className="ta-input" value={editRuleForm.data.name} onChange={(e) => editRuleForm.setData('name', e.target.value)} required />
                            <select className="ta-input" value={editRuleForm.data.customer_tag_id} onChange={(e) => editRuleForm.setData('customer_tag_id', e.target.value)} required><option value="">Target tag</option>{tags.map((tag) => <option key={tag.id} value={tag.id}>{tag.name}</option>)}</select>
                            <select className="ta-input" value={editRuleForm.data.criteria} onChange={(e) => editRuleForm.setData('criteria', e.target.value)}><option value="inactivity_days">Inactivity Days</option><option value="min_spend">Min Spend</option><option value="min_visits">Min Visits</option></select>
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
                            <tbody>{customers.map((customer) => <tr key={customer.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-700">{customer.name}</td><td className="px-5 py-3"><div className="flex flex-wrap gap-2">{customer.tags.map((tag) => <button key={tag.id} className="rounded-full px-2.5 py-1 text-xs font-semibold text-white" style={{ backgroundColor: tag.color }} onClick={() => removeTag(customer.id, tag.id)}>{tag.name} ×</button>)}{customer.tags.length === 0 && <span className="text-xs text-slate-400">No tags</span>}</div></td></tr>)}</tbody>
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
                                        <td className="px-4 py-2"><div className="flex gap-2"><button className="rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-1 text-xs text-indigo-700" onClick={() => router.post(route('customers.automation.due-services.remind', row.id), { channel: 'sms' })}>Remind SMS</button><button className="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs text-amber-700" onClick={() => router.post(route('customers.automation.due-services.remind', row.id), { channel: 'sms', policy: 'fallback_email' })}>SMS?Email</button><button className="rounded-lg border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs text-emerald-700" onClick={() => router.patch(route('customers.automation.due-services.status', row.id), { status: 'booked' })}>Booked</button><button className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs text-red-700" onClick={() => router.patch(route('customers.automation.due-services.status', row.id), { status: 'dismissed' })}>Dismiss</button></div></td>
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

                    <form className="mb-4 grid gap-3 md:grid-cols-4" onSubmit={(e) => { e.preventDefault(); templateForm.post(route('customers.automation.campaign-templates.store'), { onSuccess: () => templateForm.reset('name', 'content') }); }}>
                        <input className="ta-input" placeholder="Template name" value={templateForm.data.name} onChange={(e) => templateForm.setData('name', e.target.value)} required />
                        <select className="ta-input" value={templateForm.data.channel} onChange={(e) => templateForm.setData('channel', e.target.value)}><option value="sms">SMS</option><option value="email">Email</option><option value="whatsapp">WhatsApp</option></select>
                        <input className="ta-input md:col-span-2" placeholder="Message (use {name})" value={templateForm.data.content} onChange={(e) => templateForm.setData('content', e.target.value)} required />
                        <button className="ta-btn-primary md:col-span-4" disabled={templateForm.processing || !canManage}>Create Template</button>
                    </form>

                    <form className="mb-4 grid gap-3 md:grid-cols-6" onSubmit={(e) => { e.preventDefault(); campaignForm.post(route('customers.automation.campaigns.store'), { onSuccess: () => campaignForm.reset('name', 'scheduled_at') }); }}>
                        <input className="ta-input" placeholder="Campaign name" value={campaignForm.data.name} onChange={(e) => campaignForm.setData('name', e.target.value)} required />
                        <select className="ta-input" value={campaignForm.data.campaign_template_id} onChange={(e) => campaignForm.setData('campaign_template_id', e.target.value)} required><option value="">Template</option>{campaignTemplates.filter((t) => t.is_active).map((t) => <option key={t.id} value={t.id}>{t.name} ({t.channel})</option>)}</select>
                        <select className="ta-input" value={campaignForm.data.audience_type} onChange={(e) => campaignForm.setData('audience_type', e.target.value)}><option value="all">All active customers</option><option value="tag">By tag</option><option value="due_service">Due services</option><option value="inactivity_days">Inactivity days</option></select>
                        <select className="ta-input" value={campaignForm.data.customer_tag_id} onChange={(e) => campaignForm.setData('customer_tag_id', e.target.value)} disabled={campaignForm.data.audience_type !== 'tag'}><option value="">Tag (if tag audience)</option>{tags.map((tag) => <option key={tag.id} value={tag.id}>{tag.name}</option>)}</select>
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

                <section className="ta-card overflow-hidden">
                    <div className="border-b border-slate-200 px-5 py-4"><h3 className="text-sm font-semibold text-slate-700">Communication Logs</h3></div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full text-sm">
                            <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Date</th><th className="px-5 py-3">Customer</th><th className="px-5 py-3">Channel</th><th className="px-5 py-3">Context</th><th className="px-5 py-3">Recipient</th><th className="px-5 py-3">Status</th></tr></thead>
                            <tbody>{recentLogs.map((log) => <tr key={log.id} className="border-t border-slate-100"><td className="px-5 py-3 text-slate-600">{new Date(log.sent_at || log.created_at).toLocaleString()}</td><td className="px-5 py-3 text-slate-700">{log.customer_name || '-'}</td><td className="px-5 py-3 text-slate-600">{log.channel}</td><td className="px-5 py-3 text-slate-600">{log.context}</td><td className="px-5 py-3 text-slate-600">{log.recipient || '-'}</td><td className="px-5 py-3 text-slate-600">{log.status}</td></tr>)}</tbody>
                        </table>
                    </div>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
