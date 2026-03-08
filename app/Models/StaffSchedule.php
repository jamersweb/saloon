<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_profile_id',
        'schedule_date',
        'start_time',
        'end_time',
        'break_start',
        'break_end',
        'is_day_off',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'schedule_date' => 'date',
            'is_day_off' => 'boolean',
        ];
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }
}
