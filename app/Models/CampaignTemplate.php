<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'channel',
        'content',
        'whatsapp_message_type',
        'whatsapp_template_name',
        'whatsapp_template_language_code',
        'whatsapp_header_type',
        'whatsapp_header_media_url',
        'whatsapp_header_media_filename',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }
}
