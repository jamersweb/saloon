<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalonService extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'category',
        'duration_minutes',
        'buffer_minutes',
        'repeat_after_days',
        'price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'price' => 'decimal:2',
        ];
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'service_id');
    }

    public function dueServices(): HasMany
    {
        return $this->hasMany(CustomerDueService::class, 'salon_service_id');
    }

    public function loyaltyRewardsAllowingService(): BelongsToMany
    {
        return $this->belongsToMany(LoyaltyReward::class, 'loyalty_reward_salon_service')
            ->withTimestamps();
    }

    public function servicePackages(): BelongsToMany
    {
        return $this->belongsToMany(ServicePackage::class, 'service_package_salon_service')
            ->withPivot('included_sessions')
            ->withTimestamps();
    }
}
