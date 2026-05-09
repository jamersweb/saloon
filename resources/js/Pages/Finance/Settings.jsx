import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';

export default function FinanceSettings({ settings }) {
    const { flash } = usePage().props;
    const form = useForm({ ...settings });

    return (
        <AuthenticatedLayout header="Finance settings">
            <Head title="Finance settings" />

            <div className="space-y-6">
                {flash?.status && <div className="ta-card border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">{flash.status}</div>}

                <Link href={route('finance.index')} className="text-sm text-indigo-600 hover:underline">
                    ← Finance overview
                </Link>

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">Business & tax</h3>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.transform((d) => ({
                                ...d,
                                vat_rate_percent: Number(d.vat_rate_percent),
                                next_invoice_number: parseInt(d.next_invoice_number, 10),
                                whatsapp_rate_limit_per_minute: d.whatsapp_rate_limit_per_minute === '' ? null : parseInt(d.whatsapp_rate_limit_per_minute, 10),
                            }));
                            form.patch(route('finance.settings.update'));
                        }}
                        className="grid gap-4 md:grid-cols-2"
                    >
                        <div className="md:col-span-2">
                            <label className="ta-field-label">Business name (on receipt)</label>
                            <input className="ta-input" value={form.data.business_name} onChange={(e) => form.setData('business_name', e.target.value)} required />
                            <p className="mt-1 text-xs text-slate-500">
                                Thermal receipts use <code className="rounded bg-slate-100 px-1">public/images/vina-logo.png</code> (scaled when GD is available, then embedded after Arabic shaping). If the file is missing, an inline SVG badge is used. Line items: item, quantity, and amount (line total including VAT).
                            </p>
                        </div>
                        <div className="md:col-span-2">
                            <label className="ta-field-label">Address line</label>
                            <input className="ta-input" value={form.data.address_line || ''} onChange={(e) => form.setData('address_line', e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Phone</label>
                            <input className="ta-input" value={form.data.phone || ''} onChange={(e) => form.setData('phone', e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Email</label>
                            <input className="ta-input" value={form.data.email || ''} onChange={(e) => form.setData('email', e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Tax registration number (TRN)</label>
                            <input className="ta-input" value={form.data.tax_registration_number || ''} onChange={(e) => form.setData('tax_registration_number', e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">VAT rate %</label>
                            <input type="number" step="0.01" min="0" max="100" className="ta-input" value={form.data.vat_rate_percent} onChange={(e) => form.setData('vat_rate_percent', e.target.value)} required />
                        </div>
                        <div>
                            <label className="ta-field-label">Invoice prefix</label>
                            <input className="ta-input" value={form.data.invoice_prefix} onChange={(e) => form.setData('invoice_prefix', e.target.value)} required />
                        </div>
                        <div>
                            <label className="ta-field-label">Next invoice sequence #</label>
                            <input type="number" min="1" className="ta-input" value={form.data.next_invoice_number} onChange={(e) => form.setData('next_invoice_number', e.target.value)} required />
                        </div>
                        <div>
                            <label className="ta-field-label">Currency code</label>
                            <input className="ta-input" maxLength={3} value={form.data.currency_code} onChange={(e) => form.setData('currency_code', e.target.value.toUpperCase())} required />
                        </div>
                        <div className="md:col-span-2">
                            <button type="submit" className="ta-btn-primary" disabled={form.processing}>
                                Save settings
                            </button>
                        </div>
                    </form>
                </section>

                <section className="ta-card p-5">
                    <h3 className="mb-4 text-sm font-semibold text-slate-700">WhatsApp</h3>
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.transform((d) => ({
                                ...d,
                                vat_rate_percent: Number(d.vat_rate_percent),
                                next_invoice_number: parseInt(d.next_invoice_number, 10),
                                whatsapp_rate_limit_per_minute: d.whatsapp_rate_limit_per_minute === '' ? null : parseInt(d.whatsapp_rate_limit_per_minute, 10),
                            }));
                            form.patch(route('finance.settings.update'));
                        }}
                        className="grid gap-4 md:grid-cols-2"
                    >
                        <div>
                            <label className="ta-field-label">Driver</label>
                            <select className="ta-input" value={form.data.whatsapp_driver || 'log'} onChange={(e) => form.setData('whatsapp_driver', e.target.value)}>
                                <option value="log">log</option>
                                <option value="meta">meta</option>
                            </select>
                        </div>
                        <div>
                            <label className="ta-field-label">API version</label>
                            <input className="ta-input" value={form.data.whatsapp_api_version || ''} onChange={(e) => form.setData('whatsapp_api_version', e.target.value)} />
                        </div>
                        <div className="md:col-span-2">
                            <label className="ta-field-label">Base URL</label>
                            <input className="ta-input" value={form.data.whatsapp_base_url || ''} onChange={(e) => form.setData('whatsapp_base_url', e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Phone number ID</label>
                            <input className="ta-input" value={form.data.whatsapp_phone_number_id || ''} onChange={(e) => form.setData('whatsapp_phone_number_id', e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Business account ID</label>
                            <input className="ta-input" value={form.data.whatsapp_business_account_id || ''} onChange={(e) => form.setData('whatsapp_business_account_id', e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Webhook verify token</label>
                            <input className="ta-input" value={form.data.whatsapp_webhook_verify_token || ''} onChange={(e) => form.setData('whatsapp_webhook_verify_token', e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Default language code</label>
                            <input className="ta-input" value={form.data.whatsapp_default_language_code || ''} onChange={(e) => form.setData('whatsapp_default_language_code', e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Rate limit / minute</label>
                            <input type="number" min="1" className="ta-input" value={form.data.whatsapp_rate_limit_per_minute || ''} onChange={(e) => form.setData('whatsapp_rate_limit_per_minute', e.target.value)} />
                        </div>
                        <div>
                            <label className="ta-field-label">Due-service template</label>
                            <input className="ta-input" value={form.data.whatsapp_due_service_template_name || ''} onChange={(e) => form.setData('whatsapp_due_service_template_name', e.target.value)} placeholder="e.g. due_service_reminder" />
                        </div>
                        <div>
                            <label className="ta-field-label">Public-booking alert template</label>
                            <input className="ta-input" value={form.data.whatsapp_public_booking_template_name || ''} onChange={(e) => form.setData('whatsapp_public_booking_template_name', e.target.value)} placeholder="e.g. booking_alert" />
                        </div>
                        <div className="md:col-span-2">
                            <label className="ta-field-label">Access token</label>
                            <input
                                type="password"
                                className="ta-input"
                                value={form.data.whatsapp_access_token || ''}
                                onChange={(e) => form.setData('whatsapp_access_token', e.target.value)}
                                placeholder={settings.whatsapp_access_token_configured ? 'Stored. Enter a new token only to rotate it.' : 'Paste a Meta access token'}
                            />
                            <p className="mt-1 text-xs text-slate-500">
                                {settings.whatsapp_access_token_configured ? 'A token is already stored. Leaving this blank keeps the current token.' : 'No token is stored yet.'}
                            </p>
                        </div>
                        <div className="md:col-span-2">
                            <button type="submit" className="ta-btn-primary" disabled={form.processing}>
                                Save WhatsApp settings
                            </button>
                        </div>
                    </form>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
