<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'campaign_template_id',
        'channel',
        'audience_type',
        'customer_tag_id',
        'inactivity_days',
        'scheduled_at',
        'status',
        'last_run_at',
        'sent_count',
        'failed_count',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(CampaignTemplate::class, 'campaign_template_id');
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(CustomerTag::class, 'customer_tag_id');
    }
}

