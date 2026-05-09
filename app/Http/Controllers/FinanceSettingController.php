<?php

namespace App\Http\Controllers;

use App\Models\FinanceSetting;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceSettingController extends Controller
{
    public function edit(Request $request): Response
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $settings = FinanceSetting::current();

        return Inertia::render('Finance/Settings', [
            'settings' => [
                'business_name' => $settings->business_name,
                'address_line' => $settings->address_line,
                'phone' => $settings->phone,
                'email' => $settings->email,
                'tax_registration_number' => $settings->tax_registration_number,
                'vat_rate_percent' => (float) $settings->vat_rate_percent,
                'invoice_prefix' => $settings->invoice_prefix,
                'next_invoice_number' => (int) $settings->next_invoice_number,
                'currency_code' => $settings->currency_code,
                'whatsapp_driver' => $settings->whatsapp_driver ?: config('services.whatsapp.driver', 'log'),
                'whatsapp_base_url' => $settings->whatsapp_base_url ?: config('services.whatsapp.base_url', 'https://graph.facebook.com'),
                'whatsapp_api_version' => $settings->whatsapp_api_version ?: config('services.whatsapp.version', 'v23.0'),
                'whatsapp_phone_number_id' => $settings->whatsapp_phone_number_id ?: config('services.whatsapp.phone_number_id'),
                'whatsapp_business_account_id' => $settings->whatsapp_business_account_id ?: config('services.whatsapp.business_account_id'),
                'whatsapp_access_token' => '',
                'whatsapp_access_token_configured' => filled($settings->whatsapp_access_token ?: config('services.whatsapp.token')),
                'whatsapp_webhook_verify_token' => $settings->whatsapp_webhook_verify_token ?: config('services.whatsapp.webhook_verify_token'),
                'whatsapp_default_language_code' => $settings->whatsapp_default_language_code ?: config('services.whatsapp.default_language_code', 'en_US'),
                'whatsapp_due_service_template_name' => $settings->whatsapp_due_service_template_name ?: config('services.whatsapp.due_service_template_name'),
                'whatsapp_public_booking_template_name' => $settings->whatsapp_public_booking_template_name ?: config('services.whatsapp.public_booking_template_name'),
                'whatsapp_rate_limit_per_minute' => (int) ($settings->whatsapp_rate_limit_per_minute ?: config('services.whatsapp.rate_limit_per_minute', 60)),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'address_line' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'tax_registration_number' => ['nullable', 'string', 'max:100'],
            'vat_rate_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'invoice_prefix' => ['required', 'string', 'max:16'],
            'next_invoice_number' => ['required', 'integer', 'min:1'],
            'currency_code' => ['required', 'string', 'size:3'],
            'whatsapp_driver' => ['nullable', 'in:log,meta'],
            'whatsapp_base_url' => ['nullable', 'url', 'max:255'],
            'whatsapp_api_version' => ['nullable', 'string', 'max:16'],
            'whatsapp_phone_number_id' => ['nullable', 'string', 'max:64'],
            'whatsapp_business_account_id' => ['nullable', 'string', 'max:64'],
            'whatsapp_access_token' => ['nullable', 'string'],
            'whatsapp_webhook_verify_token' => ['nullable', 'string', 'max:255'],
            'whatsapp_default_language_code' => ['nullable', 'string', 'max:16'],
            'whatsapp_due_service_template_name' => ['nullable', 'string', 'max:255'],
            'whatsapp_public_booking_template_name' => ['nullable', 'string', 'max:255'],
            'whatsapp_rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $settings = FinanceSetting::current();
        if (! filled($data['whatsapp_access_token'] ?? null)) {
            unset($data['whatsapp_access_token']);
        }

        $settings->update($data);

        Audit::log($request->user()->id, 'finance.settings.updated', 'FinanceSetting', $settings->id, []);

        return back()->with('status', 'Finance settings saved.');
    }
}
