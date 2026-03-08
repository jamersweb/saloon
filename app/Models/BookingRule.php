<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BookingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'slot_interval_minutes',
        'min_advance_minutes',
        'max_advance_days',
        'public_requires_approval',
        'allow_customer_cancellation',
        'cancellation_cutoff_hours',
    ];

    protected function casts(): array
    {
        return [
            'public_requires_approval' => 'boolean',
            'allow_customer_cancellation' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(
            ['id' => 1],
            [
                'slot_interval_minutes' => 15,
                'min_advance_minutes' => 30,
                'max_advance_days' => 60,
                'public_requires_approval' => true,
                'allow_customer_cancellation' => true,
                'cancellation_cutoff_hours' => 12,
            ]
        );
    }
}

