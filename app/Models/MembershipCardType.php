<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipCardType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'kind',
        'min_points',
        'direct_purchase_price',
        'validity_days',
        'service_package_id',
        'is_active',
        'is_transferable',
    ];

    protected function casts(): array
    {
        return [
            'direct_purchase_price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_transferable' => 'boolean',
        ];
    }

    public function customerCards(): HasMany
    {
        return $this->hasMany(CustomerMembershipCard::class);
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(MembershipRegistration::class);
    }

    public function servicePackage(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class, 'service_package_id');
    }
}
