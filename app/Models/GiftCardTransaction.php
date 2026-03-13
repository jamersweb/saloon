<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiftCardTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'gift_card_id',
        'appointment_id',
        'amount_change',
        'balance_after',
        'reason',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_change' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function giftCard(): BelongsTo
    {
        return $this->belongsTo(GiftCard::class);
    }
}
