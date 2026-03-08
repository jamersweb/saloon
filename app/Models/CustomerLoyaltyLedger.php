<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerLoyaltyLedger extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'loyalty_tier_id',
        'points_change',
        'balance_after',
        'reason',
        'reference',
        'notes',
        'created_by',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class, 'loyalty_tier_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
