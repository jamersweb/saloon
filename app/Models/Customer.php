<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_code',
        'name',
        'phone',
        'email',
        'birthday',
        'allergies',
        'notes',
        'acquisition_source',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function loyaltyAccount(): HasOne
    {
        return $this->hasOne(CustomerLoyaltyAccount::class);
    }

    public function loyaltyLedgers(): HasMany
    {
        return $this->hasMany(CustomerLoyaltyLedger::class);
    }

    public function membershipCards(): HasMany
    {
        return $this->hasMany(CustomerMembershipCard::class)->latest();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(CustomerTag::class, 'customer_tag_assignments')
            ->withTimestamps();
    }

    public function dueServices(): HasMany
    {
        return $this->hasMany(CustomerDueService::class);
    }

    public function communicationLogs(): HasMany
    {
        return $this->hasMany(CommunicationLog::class);
    }

    public function packages(): HasMany
    {
        return $this->hasMany(CustomerPackage::class);
    }

    public function giftCards(): HasMany
    {
        return $this->hasMany(GiftCard::class, 'assigned_customer_id');
    }

    public function portalTokens(): HasMany
    {
        return $this->hasMany(CustomerPortalToken::class);
    }

    public function taxInvoices(): HasMany
    {
        return $this->hasMany(TaxInvoice::class);
    }

    public function membershipRegistrations(): HasMany
    {
        return $this->hasMany(MembershipRegistration::class)->latest();
    }
}
