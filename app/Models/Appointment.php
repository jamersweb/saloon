<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Appointment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW = 'no_show';

    /** @var array<string, list<string>> */
    public const ALLOWED_TRANSITIONS = [
        self::STATUS_PENDING => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED, self::STATUS_NO_SHOW],
        self::STATUS_CONFIRMED => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED, self::STATUS_NO_SHOW],
        self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_NO_SHOW => [],
    ];

    protected $fillable = [
        'customer_id',
        'service_id',
        'staff_profile_id',
        'booked_by',
        'source',
        'status',
        'scheduled_start',
        'scheduled_end',
        'arrival_time',
        'service_start_time',
        'customer_name',
        'customer_phone',
        'customer_email',
        'cancellation_reason',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'arrival_time' => 'datetime',
            'service_start_time' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(SalonService::class, 'service_id');
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    public function serviceExecution(): HasOne
    {
        return $this->hasOne(AppointmentServiceLog::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(AppointmentPhoto::class)->latest();
    }

    public function productUsages(): HasMany
    {
        return $this->hasMany(AppointmentProductUsage::class);
    }

    /** @return list<string> */
    public function nextStatuses(): array
    {
        return self::ALLOWED_TRANSITIONS[$this->status] ?? [];
    }

    public function canTransitionTo(string $nextStatus): bool
    {
        return in_array($nextStatus, $this->nextStatuses(), true);
    }
}
