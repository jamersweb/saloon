<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerDueService extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'salon_service_id',
        'last_appointment_id',
        'due_date',
        'status',
        'reminder_sent_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'reminder_sent_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(SalonService::class, 'salon_service_id');
    }

    public function lastAppointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'last_appointment_id');
    }
}
