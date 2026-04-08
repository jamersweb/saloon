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
        ]);

        $settings = FinanceSetting::current();
        $settings->update($data);

        Audit::log($request->user()->id, 'finance.settings.updated', 'FinanceSetting', $settings->id, []);

        return back()->with('status', 'Finance settings saved.');
    }
}
