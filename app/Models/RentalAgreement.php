<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RentalAgreement extends Model
{
    public const TYPE_CHAIR = 'chair';

    public const TYPE_LINE = 'line';

    public const MODEL_FIXED = 'fixed';

    public const MODEL_COMMISSION = 'commission';

    public const MODEL_HYBRID = 'hybrid';

    protected $fillable = [
        'customer_id',
        'partner_name',
        'agreement_type',
        'cost_center',
        'rental_model',
        'fixed_rent_amount',
        'commission_percent',
        'start_date',
        'end_date',
        'is_active',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'fixed_rent_amount' => 'decimal:2',
            'commission_percent' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(RentalSettlement::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
