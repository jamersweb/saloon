<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GiftCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'assigned_customer_id',
        'initial_value',
        'remaining_value',
        'expires_at',
        'status',
        'issued_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'initial_value' => 'decimal:2',
            'remaining_value' => 'decimal:2',
            'expires_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'assigned_customer_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(GiftCardTransaction::class);
    }
}
