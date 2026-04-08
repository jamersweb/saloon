<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxInvoiceItem extends Model
{
    protected $fillable = [
        'tax_invoice_id',
        'salon_service_id',
        'description',
        'quantity',
        'unit_price',
        'line_subtotal',
        'tax_rate_percent',
        'line_tax',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'line_subtotal' => 'decimal:2',
            'tax_rate_percent' => 'float',
            'line_tax' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function taxInvoice(): BelongsTo
    {
        return $this->belongsTo(TaxInvoice::class);
    }

    public function salonService(): BelongsTo
    {
        return $this->belongsTo(SalonService::class);
    }
}
