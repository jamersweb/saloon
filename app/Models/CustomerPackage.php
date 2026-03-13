<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerPackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'service_package_id',
        'remaining_sessions',
        'remaining_value',
        'expires_at',
        'status',
        'assigned_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'remaining_value' => 'decimal:2',
            'expires_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(ServicePackage::class, 'service_package_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(CustomerPackageUsage::class);
    }
}
