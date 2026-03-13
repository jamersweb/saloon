<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServicePackage extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'usage_limit',
        'initial_value',
        'validity_days',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'initial_value' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function customerPackages(): HasMany
    {
        return $this->hasMany(CustomerPackage::class);
    }
}
