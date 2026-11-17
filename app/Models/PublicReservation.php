<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicReservation extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token', 'customer_email', 'customer_phone'];

    protected function casts(): array
    {
        return [
            'services' => 'array',
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'original_starts_at' => 'datetime',
            'paid_at' => 'datetime', 'notified_at' => 'datetime', 'requested_start' => 'datetime',
        ];
    }
}
