<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipRegistration extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'customer_membership_card_id',
        'membership_card_type_id',
        'registered_by',
        'registration_date',
        'staff_name',
        'full_name',
        'phone',
        'email',
        'nationality',
        'date_of_birth',
        'is_first_visit',
        'preferred_language',
        'preferred_language_other',
        'heard_about_us',
        'heard_about_us_other',
        'service_interests',
        'service_interests_other',
        'requires_home_service',
        'home_service_location',
        'preferred_visit_frequency',
        'spending_profile',
        'consent_data_processing',
        'consent_marketing',
        'signature_date',
        'signature_name',
        'terms_version',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'registration_date' => 'date',
            'date_of_birth' => 'date',
            'signature_date' => 'date',
            'is_first_visit' => 'boolean',
            'requires_home_service' => 'boolean',
            'consent_data_processing' => 'boolean',
            'consent_marketing' => 'boolean',
            'service_interests' => 'array',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function membershipCard(): BelongsTo
    {
        return $this->belongsTo(CustomerMembershipCard::class, 'customer_membership_card_id');
    }

    public function membershipCardType(): BelongsTo
    {
        return $this->belongsTo(MembershipCardType::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
