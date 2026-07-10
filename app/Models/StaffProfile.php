<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class StaffProfile extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'employee_code',
        'phone',
        'skills',
        'hourly_rate',
        'monthly_salary',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'skills' => 'array',
            'hourly_rate' => 'decimal:2',
            'monthly_salary' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(StaffSchedule::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function attendanceLogs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function expenseEntries(): HasMany
    {
        return $this->hasMany(ExpenseEntry::class);
    }

    public function pettyCashEntries(): HasMany
    {
        return $this->hasMany(PettyCashEntry::class);
    }

    public function pettyCashClosings(): HasMany
    {
        return $this->hasMany(PettyCashClosing::class);
    }

    public function payrollLines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    public function scopeAssignableToServices(Builder $query): Builder
    {
        return $query->whereHas('user.role', function (Builder $roleQuery): void {
            $roleQuery->whereIn('name', ['owner', 'manager', 'staff']);
        });
    }
}
