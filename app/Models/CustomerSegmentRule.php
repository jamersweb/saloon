<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerSegmentRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'customer_tag_id',
        'criteria',
        'threshold_value',
        'lookback_days',
        'is_active',
        'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
            'threshold_value' => 'decimal:2',
        ];
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(CustomerTag::class, 'customer_tag_id');
    }
}
