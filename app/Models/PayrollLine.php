<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollLine extends Model
{
    public const PAY_BASIS_HOURLY = 'hourly';

    public const PAY_BASIS_FIXED = 'fixed_salary';

    protected $fillable = [
        'payroll_period_id',
        'staff_profile_id',
        'pay_basis',
        'hours_worked',
        'hourly_rate',
        'basic_salary',
        'gross_amount',
        'bonus_amount',
        'deduction_amount',
        'net_amount',
        'payment_method',
        'paid_at',
        'finance_expense_entry_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'hours_worked' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'basic_salary' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'bonus_amount' => 'decimal:2',
            'deduction_amount' => 'decimal:2',
            'net_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function payrollPeriod(): BelongsTo
    {
        return $this->belongsTo(PayrollPeriod::class);
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    public function financeExpenseEntry(): BelongsTo
    {
        return $this->belongsTo(ExpenseEntry::class, 'finance_expense_entry_id');
    }
}
