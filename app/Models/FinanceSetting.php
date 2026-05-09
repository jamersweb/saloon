<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceSetting extends Model
{
    protected $fillable = [
        'business_name',
        'address_line',
        'phone',
        'email',
        'tax_registration_number',
        'vat_rate_percent',
        'invoice_prefix',
        'next_invoice_number',
        'currency_code',
        'whatsapp_driver',
        'whatsapp_base_url',
        'whatsapp_api_version',
        'whatsapp_phone_number_id',
        'whatsapp_business_account_id',
        'whatsapp_access_token',
        'whatsapp_webhook_verify_token',
        'whatsapp_default_language_code',
        'whatsapp_due_service_template_name',
        'whatsapp_public_booking_template_name',
        'whatsapp_rate_limit_per_minute',
    ];

    protected function casts(): array
    {
        return [
            'vat_rate_percent' => 'float',
            'next_invoice_number' => 'integer',
            'whatsapp_rate_limit_per_minute' => 'integer',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(
            ['id' => 1],
            [
                'business_name' => 'Vina Luxury Beauty Salon',
                'vat_rate_percent' => 5,
                'invoice_prefix' => 'RCT',
                'next_invoice_number' => 1,
                'currency_code' => 'AED',
                'whatsapp_driver' => 'meta',
                'whatsapp_base_url' => 'https://graph.facebook.com',
                'whatsapp_api_version' => 'v25.0',
                'whatsapp_default_language_code' => 'en_US',
                'whatsapp_rate_limit_per_minute' => 60,
            ]
        );
    }
}
