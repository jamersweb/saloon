<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyProgramSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'auto_earn_enabled',
        'points_per_currency',
        'points_per_visit',
        'birthday_bonus_points',
        'referral_bonus_points',
        'review_bonus_points',
        'minimum_spend',
        'rounding_mode',
    ];

    protected function casts(): array
    {
        return [
            'auto_earn_enabled' => 'boolean',
            'points_per_currency' => 'float',
            'points_per_visit' => 'integer',
            'birthday_bonus_points' => 'integer',
            'referral_bonus_points' => 'integer',
            'review_bonus_points' => 'integer',
            'minimum_spend' => 'float',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(
            ['id' => 1],
            [
                'auto_earn_enabled' => true,
                'points_per_currency' => 1,
                'points_per_visit' => 0,
                'birthday_bonus_points' => 0,
                'referral_bonus_points' => 0,
                'review_bonus_points' => 0,
                'minimum_spend' => 0,
                'rounding_mode' => 'floor',
            ]
        );
    }
}
