<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashEntry extends Model
{
    public const TYPE_ISSUE = 'issue';

    public const TYPE_EXPENSE = 'expense';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    protected $fillable = [
        'staff_profile_id',
        'expense_entry_id',
        'transaction_type',
        'direction',
        'amount',
        'transaction_date',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transaction_date' => 'date',
        ];
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    public function expenseEntry(): BelongsTo
    {
        return $this->belongsTo(ExpenseEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
