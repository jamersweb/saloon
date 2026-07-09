<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class ExpenseEntry extends Model
{
    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PAID = 'paid';

    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_REJECTED = 'rejected';

    protected $fillable = [
        'category',
        'cost_center',
        'expense_type',
        'expense_subcategory',
        'vendor_name',
        'expense_date',
        'amount_subtotal',
        'vat_amount',
        'total_amount',
        'payment_status',
        'payment_method',
        'approval_status',
        'receipt_number',
        'receipt_image_path',
        'paid_at',
        'purchase_order_id',
        'staff_profile_id',
        'approved_by',
        'approved_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount_subtotal' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected $appends = [
        'receipt_image_url',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function staffProfile(): BelongsTo
    {
        return $this->belongsTo(StaffProfile::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function pettyCashEntry(): HasOne
    {
        return $this->hasOne(PettyCashEntry::class);
    }

    public function getReceiptImageUrlAttribute(): ?string
    {
        if (! is_string($this->receipt_image_path) || $this->receipt_image_path === '') {
            return null;
        }

        return Storage::disk('public')->url($this->receipt_image_path);
    }
}
