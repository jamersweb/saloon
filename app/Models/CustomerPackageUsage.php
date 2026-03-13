<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPackageUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_package_id',
        'appointment_id',
        'salon_service_id',
        'sessions_used',
        'value_used',
        'remaining_sessions_after',
        'remaining_value_after',
        'notes',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'value_used' => 'decimal:2',
            'remaining_value_after' => 'decimal:2',
        ];
    }

    public function customerPackage(): BelongsTo
    {
        return $this->belongsTo(CustomerPackage::class);
    }
}
