<?php

namespace App\Models;

use App\Support\FinanceStructure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoicePayment extends Model
{
    public const METHOD_CASH = 'cash';

    public const METHOD_CARD = 'card';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_ONLINE_PAYMENT_LINK = 'online_payment_link';

    public const METHOD_PACKAGE_CREDIT = 'package_credit';

    public const METHOD_GIFT_CARD = 'gift_card';

    public const METHOD_SPLIT_PAYMENT = 'split_payment';

    public const METHOD_OTHER = 'other';

    protected $fillable = [
        'tax_invoice_id',
        'amount',
        'method',
        'paid_at',
        'reference_note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function taxInvoice(): BelongsTo
    {
        return $this->belongsTo(TaxInvoice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return list<string, string>
     */
    public static function methodLabels(): array
    {
        return FinanceStructure::paymentMethods();
    }
}
