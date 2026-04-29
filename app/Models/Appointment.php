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
        'customer_package_id',
        'visit_id',
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
        'exclude_loyalty_earn',
        'package_session_applied',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'arrival_time' => 'datetime',
            'service_start_time' => 'datetime',
            'exclude_loyalty_earn' => 'boolean',
            'package_session_applied' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function customerPackage(): BelongsTo
    {
        return $this->belongsTo(CustomerPackage::class);
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

    public function giftCardTransactions(): HasMany
    {
        return $this->hasMany(GiftCardTransaction::class);
    }

    public function taxInvoices(): HasMany
    {
        return $this->hasMany(TaxInvoice::class);
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

    /**
     * @return array{awaiting_checkout: bool, checkout_invoice_id: int|null}
     */
    public function checkoutSummary(): array
    {
        if ($this->status !== self::STATUS_COMPLETED) {
            return ['awaiting_checkout' => false, 'checkout_invoice_id' => null];
        }

        $this->loadMissing('taxInvoices.payments');

        $active = $this->taxInvoices->where('status', '!=', TaxInvoice::STATUS_VOID);

        if ($active->isEmpty()) {
            return ['awaiting_checkout' => true, 'checkout_invoice_id' => null];
        }

        foreach ($active as $inv) {
            if ($inv->status === TaxInvoice::STATUS_DRAFT) {
                return [
                    'awaiting_checkout' => true,
                    'checkout_invoice_id' => $inv->id,
                ];
            }
        }

        foreach ($active as $inv) {
            if ($inv->status === TaxInvoice::STATUS_FINALIZED && $inv->balanceDue() > 0.009) {
                return [
                    'awaiting_checkout' => true,
                    'checkout_invoice_id' => $inv->id,
                ];
            }
        }

        return ['awaiting_checkout' => false, 'checkout_invoice_id' => null];
    }
}
