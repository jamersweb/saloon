<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyReward extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'points_cost',
        'stock_quantity',
        'max_units_per_redemption',
        'max_redemptions_per_calendar_month',
        'min_days_between_redemptions',
        'requires_appointment_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'requires_appointment_id' => 'boolean',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(LoyaltyRedemption::class);
    }

    /** Services this reward may be redeemed against (empty = any service when a visit is not required). */
    public function allowedSalonServices(): BelongsToMany
    {
        return $this->belongsToMany(SalonService::class, 'loyalty_reward_salon_service')
            ->withTimestamps();
    }
}
