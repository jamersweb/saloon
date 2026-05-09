<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunicationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'channel',
        'context',
        'recipient',
        'message',
        'status',
        'provider',
        'provider_status',
        'message_type',
        'attempt_count',
        'provider_message_id',
        'error_message',
        'provider_payload',
        'queued_at',
        'accepted_at',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'last_provider_event_at',
    ];

    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'provider_payload' => 'array',
            'queued_at' => 'datetime',
            'accepted_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'last_provider_event_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
