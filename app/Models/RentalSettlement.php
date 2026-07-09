<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalSettlement extends Model
{
    protected $fillable = [
        'rental_agreement_id',
        'settlement_date',
        'gross_sales_amount',
        'fixed_rent_amount',
        'commission_amount',
        'total_amount',
        'tax_invoice_id',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'settlement_date' => 'date',
            'gross_sales_amount' => 'decimal:2',
            'fixed_rent_amount' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(RentalAgreement::class, 'rental_agreement_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(TaxInvoice::class, 'tax_invoice_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
