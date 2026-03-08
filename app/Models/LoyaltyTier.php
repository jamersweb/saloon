<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'min_points',
        'discount_percent',
        'earn_multiplier',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_percent' => 'decimal:2',
            'earn_multiplier' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(CustomerLoyaltyAccount::class);
    }
}
