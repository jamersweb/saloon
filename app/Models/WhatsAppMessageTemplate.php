<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppMessageTemplate extends Model
{
    protected $table = 'whatsapp_message_templates';

    protected $fillable = [
        'template_uid',
        'name',
        'language',
        'category',
        'status',
        'sub_category',
        'quality_score',
        'rejection_reason',
        'components',
        'raw_payload',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'components' => 'array',
            'raw_payload' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
