<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServicePackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'usage_limit',
        'initial_value',
        'validity_days',
        'services_per_visit_limit',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'initial_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function salonServices(): BelongsToMany
    {
        return $this->belongsToMany(SalonService::class, 'service_package_salon_service')
            ->withPivot('included_sessions')
            ->withTimestamps();
    }

    public function customerPackages(): HasMany
    {
        return $this->hasMany(CustomerPackage::class);
    }
}
