<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollLine extends Model
{
    protected $fillable = [
        'payroll_period_id',
        'staff_profile_id',
        'hours_worked',
        'hourly_rate',
        'gross_amount',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'hours_worked' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'gross_amount' => 'decimal:2',
        ];
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }
}
