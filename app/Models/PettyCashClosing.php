<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashClosing extends Model
{
    protected $fillable = [
        'staff_profile_id',
        'closing_date',
        'opening_balance',
        'issued_total',
        'spent_total',
        'expected_closing_balance',
        'counted_closing_balance',
        'variance_amount',
        'signed_off_name',
        'notes',
        'variance_entry_id',
        'closed_by',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'closing_date' => 'date',
            'opening_balance' => 'decimal:2',
            'issued_total' => 'decimal:2',
            'spent_total' => 'decimal:2',
            'expected_closing_balance' => 'decimal:2',
            'counted_closing_balance' => 'decimal:2',
            'variance_amount' => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    public function varianceEntry(): BelongsTo
    {
        return $this->belongsTo(PettyCashEntry::class, 'variance_entry_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
