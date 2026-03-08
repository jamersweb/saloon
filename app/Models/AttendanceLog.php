<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_profile_id',
        'attendance_date',
        'scheduled_start',
        'clock_in',
        'clock_out',
        'late_minutes',
        'notes',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
