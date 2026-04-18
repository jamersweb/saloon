<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipCardSequence extends Model
{
    protected $fillable = [
        'membership_card_type_id',
        'next_number',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(MembershipCardType::class, 'membership_card_type_id');
    }
}
