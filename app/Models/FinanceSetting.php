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
    ];

    protected function casts(): array
    {
        return [
            'vat_rate_percent' => 'float',
            'next_invoice_number' => 'integer',
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
            ]
        );
    }
}
