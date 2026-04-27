<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerMembershipCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'membership_card_type_id',
        'card_number',
        'nfc_uid',
        'status',
        'issued_at',
        'activated_at',
        'expires_at',
        'assigned_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'activated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(MembershipCardType::class, 'membership_card_type_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(MembershipRegistration::class);
    }
}
