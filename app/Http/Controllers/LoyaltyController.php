<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\CustomerLoyaltyAccount;
use App\Models\CustomerLoyaltyLedger;
use App\Models\CustomerMembershipCard;
use App\Models\CustomerPackage;
use App\Models\GiftCard;
use App\Models\GiftCardTransaction;
use App\Models\LoyaltyProgramSetting;
use App\Models\LoyaltyRedemption;
use App\Models\LoyaltyReward;
use App\Models\LoyaltyTier;
use App\Models\MembershipCardType;
use App\Models\MembershipRegistration;
use App\Models\SalonService;
use App\Models\ServicePackage;
use App\Services\GiftCardService;
use App\Services\LoyaltyRedemptionRulesService;
use App\Services\LoyaltyService;
use App\Services\MembershipCardService;
use App\Services\PackageBalanceService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoyaltyController extends Controller
{
    public function program(Request $request): Response
    {
        return $this->index($request, 'program');
    }

    public function membershipCards(Request $request): Response
    {
        return $this->index($request, 'membership-cards');
    }

    public function packages(Request $request): Response
    {
        return $this->index($request, 'packages');
    }

    public function giftCards(Request $request): Response
    {
        return $this->index($request, 'gift-cards');
    }

    public function rewards(Request $request): Response
    {
        return $this->index($request, 'rewards');
    }

    public function points(Request $request): Response
    {
        return $this->index($request, 'points');
    }

    public function index(Request $request, string $section): Response
    {
        $allowedSections = ['program', 'membership-cards', 'packages', 'gift-cards', 'rewards', 'points'];
        if (! in_array($section, $allowedSections, true)) {
            abort(404);
        }

        $cardTypes = MembershipCardType::query()
            ->orderBy('min_points')
            ->orderBy('name')
            ->get();

        return Inertia::render('Loyalty/Index', [
            'section' => $section,
            'tiers' => LoyaltyTier::query()->orderBy('min_points')->get(),
            'cardTypes' => $cardTypes,
            'packages' => ServicePackage::query()
                ->with('salonServices:id,name,category,duration_minutes,price')
                ->withCount('customerPackages')
                ->orderBy('name')
                ->get()
                ->map(fn (ServicePackage $package) => [
                    'id' => $package->id,
                    'name' => $package->name,
                    'description' => $package->description,
                    'price' => $package->price,
                    'usage_limit' => $package->usage_limit,
                    'initial_value' => $package->initial_value,
                    'validity_days' => $package->validity_days,
                    'services_per_visit_limit' => $package->services_per_visit_limit,
                    'is_active' => $package->is_active,
                    'customer_packages_count' => $package->customer_packages_count,
                    'salon_service_ids' => $package->salonServices->pluck('id')->all(),
                    'service_quantities' => $package->salonServices->mapWithKeys(fn (SalonService $service) => [
                        (string) $service->id => (int) ($service->pivot?->included_sessions ?? 1),
                    ]),
                    'salon_services' => $package->salonServices->map(fn (SalonService $service) => [
                        'id' => $service->id,
                        'name' => $service->name,
                        'category' => $service->category,
                        'duration_minutes' => $service->duration_minutes,
                        'price' => $service->price,
                        'included_sessions' => (int) ($service->pivot?->included_sessions ?? 1),
                    ])->values(),
                ]),
            'membershipCards' => CustomerMembershipCard::query()
                ->with(['customer:id,name,phone,email', 'type:id,name'])
                ->latest()
                ->limit(200)
                ->get()
                ->map(fn (CustomerMembershipCard $card) => [
                    'id' => $card->id,
                    'customer_id' => $card->customer_id,
                    'customer_name' => $card->customer?->name,
                    'customer_phone' => $card->customer?->phone,
                    'customer_email' => $card->customer?->email,
                    'membership_card_type_id' => $card->membership_card_type_id,
                    'card_type_name' => $card->type?->name,
                    'card_number' => $card->card_number,
                    'nfc_uid' => $card->nfc_uid,
                    'status' => $card->status,
                    'issued_at' => $card->issued_at,
                    'activated_at' => $card->activated_at,
                    'expires_at' => $card->expires_at,
                    'notes' => $card->notes,
                ]),
            'membershipRegistrations' => MembershipRegistration::query()
                ->with([
                    'customer:id,name,phone,email',
                    'membershipCard:id,card_number,status',
                    'membershipCardType:id,name',
                    'registeredBy:id,name',
                ])
                ->latest()
                ->limit(120)
                ->get()
                ->map(fn (MembershipRegistration $registration) => [
                    'id' => $registration->id,
                    'customer_id' => $registration->customer_id,
                    'customer_name' => $registration->customer?->name ?? $registration->full_name,
                    'phone' => $registration->phone,
                    'email' => $registration->email,
                    'registration_date' => optional($registration->registration_date)?->toDateString(),
                    'membership_type_name' => $registration->membershipCardType?->name,
                    'membership_card_number' => $registration->membershipCard?->card_number,
                    'preferred_language' => $registration->preferred_language,
                    'preferred_visit_frequency' => $registration->preferred_visit_frequency,
                    'is_first_visit' => $registration->is_first_visit,
                    'consent_marketing' => $registration->consent_marketing,
                    'registered_by_name' => $registration->registeredBy?->name ?? $registration->staff_name,
                ]),
            'nfcLookupResult' => $request->session()->get('nfc_lookup'),
            'giftNfcLookupResult' => $request->session()->get('gift_nfc_lookup'),
            'customers' => Customer::query()
                ->with(['loyaltyAccount.tier', 'membershipCards.type', 'packages.package', 'giftCards'])
                ->orderBy('name')
                ->limit(300)
                ->get()
                ->map(function (Customer $customer) use ($cardTypes) {
                    $points = (int) ($customer->loyaltyAccount?->current_points ?? 0);
                    $currentCard = $customer->membershipCards->firstWhere('status', 'active') ?? $customer->membershipCards->first();
                    $eligibleCard = $cardTypes
                        ->where('is_active', true)
                        ->where('kind', '!=', 'gift')
                        ->where('min_points', '<=', $points)
                        ->sortByDesc('min_points')
                        ->first();

                    return [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'phone' => $customer->phone,
                        'email' => $customer->email,
                        'points' => $points,
                        'tier' => $customer->loyaltyAccount?->tier?->name,
                        'current_card' => $currentCard?->type?->name,
                        'current_card_kind' => $currentCard?->type?->kind,
                        'current_card_status' => $currentCard?->status,
                        'eligible_card' => $eligibleCard?->name,
                        'eligible_card_id' => $eligibleCard?->id,
                        'card_expires_at' => $currentCard?->expires_at,
                        'active_packages' => $customer->packages->where('status', 'active')->map(fn ($package) => [
                            'id' => $package->id,
                            'name' => $package->package?->name,
                            'remaining_sessions' => $package->remaining_sessions,
                            'remaining_value' => $package->remaining_value,
                        ])->values(),
                        'gift_balance' => $customer->giftCards->where('status', 'active')->sum('remaining_value'),
                    ];
                }),
            'recentLedgers' => CustomerLoyaltyLedger::query()
                ->with(['customer:id,name', 'createdBy:id,name'])
                ->latest()
                ->limit(100)
                ->get()
                ->map(fn (CustomerLoyaltyLedger $entry) => [
                    'id' => $entry->id,
                    'customer_id' => $entry->customer_id,
                    'customer_name' => $entry->customer?->name,
                    'points_change' => $entry->points_change,
                    'balance_after' => $entry->balance_after,
                    'reason' => $entry->reason,
                    'reference' => $entry->reference,
                    'created_by' => $entry->createdBy?->name,
                    'created_at' => $entry->created_at,
                ]),
            'rewards' => LoyaltyReward::query()
                ->with('allowedSalonServices:id,name')
                ->orderByDesc('is_active')
                ->orderBy('points_cost')
                ->get(),
            'salonServices' => SalonService::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'category', 'duration_minutes', 'price']),
            'settings' => LoyaltyProgramSetting::current(),
            'recentRedemptions' => LoyaltyRedemption::query()
                ->with(['customer:id,name', 'reward:id,name', 'redeemedBy:id,name', 'appointment.service:id,name'])
                ->latest()
                ->limit(80)
                ->get()
                ->map(fn (LoyaltyRedemption $redemption) => [
                    'id' => $redemption->id,
                    'customer_id' => $redemption->customer_id,
                    'customer_name' => $redemption->customer?->name,
                    'reward_name' => $redemption->reward?->name,
                    'points_spent' => $redemption->points_spent,
                    'quantity' => $redemption->quantity,
                    'status' => $redemption->status,
                    'redeemed_by' => $redemption->redeemedBy?->name,
                    'visit_label' => $redemption->appointment
                        ? $redemption->appointment->scheduled_start?->timezone(config('app.timezone'))->format('M j, Y g:i a')
                            .($redemption->appointment->service?->name ? ' — '.$redemption->appointment->service->name : '')
                        : null,
                    'created_at' => $redemption->created_at,
                ]),
            'appointmentsForRedeem' => Appointment::query()
                ->with('service:id,name')
                ->whereNotNull('customer_id')
                ->whereIn('status', [
                    Appointment::STATUS_PENDING,
                    Appointment::STATUS_CONFIRMED,
                    Appointment::STATUS_IN_PROGRESS,
                    Appointment::STATUS_COMPLETED,
                ])
                ->where('scheduled_start', '>=', now()->subDays(120))
                ->orderByDesc('scheduled_start')
                ->limit(500)
                ->get()
                ->map(fn (Appointment $appointment) => [
                    'id' => $appointment->id,
                    'customer_id' => $appointment->customer_id,
                    'service_id' => $appointment->service_id,
                    'label' => $appointment->scheduled_start->timezone(config('app.timezone'))->format('M j, Y g:i a')
                        .($appointment->service?->name ? ' — '.$appointment->service->name : '')
                        .' ('.$appointment->status.')',
                ]),
            'customerPackages' => CustomerPackage::query()
                ->with(['customer:id,name', 'package:id,name'])
                ->latest()
                ->limit(80)
                ->get()
                ->map(fn (CustomerPackage $package) => [
                    'id' => $package->id,
                    'customer_name' => $package->customer?->name,
                    'package_name' => $package->package?->name,
                    'remaining_sessions' => $package->remaining_sessions,
                    'remaining_value' => $package->remaining_value,
                    'status' => $package->status,
                    'expires_at' => $package->expires_at,
                ]),
            'giftCards' => GiftCard::query()
                ->with('customer:id,name')
                ->latest()
                ->limit(200)
                ->get()
                ->map(fn (GiftCard $giftCard) => [
                    'id' => $giftCard->id,
                    'code' => $giftCard->code,
                    'nfc_uid' => $giftCard->nfc_uid,
                    'assigned_customer_id' => $giftCard->assigned_customer_id,
                    'customer_name' => $giftCard->customer?->name,
                    'initial_value' => $giftCard->initial_value,
                    'remaining_value' => $giftCard->remaining_value,
                    'status' => $giftCard->status,
                    'expires_at' => $giftCard->expires_at,
                ]),
            'recentGiftTransactions' => GiftCardTransaction::query()
                ->with('giftCard:id,code')
                ->latest()
                ->limit(80)
                ->get()
                ->map(fn (GiftCardTransaction $transaction) => [
                    'id' => $transaction->id,
                    'gift_code' => $transaction->giftCard?->code,
                    'amount_change' => $transaction->amount_change,
                    'balance_after' => $transaction->balance_after,
                    'reason' => $transaction->reason,
                    'created_at' => $transaction->created_at,
                ]),
        ]);
    }

    public function storePackage(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        [$data, $salonServiceIds, $serviceQuantities] = $this->validatePackagePayload($request);

        $package = ServicePackage::create([
            ...$data,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
        $package->salonServices()->sync($this->buildPackageServiceSyncPayload($salonServiceIds, $serviceQuantities));

        Audit::log($request->user()?->id, 'package.created', 'ServicePackage', $package->id);

        return back()->with('status', 'Service package created.');
    }

    public function updatePackage(Request $request, ServicePackage $package): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        [$data, $salonServiceIds, $serviceQuantities] = $this->validatePackagePayload($request);

        $package->update([
            ...$data,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);
        $package->salonServices()->sync($this->buildPackageServiceSyncPayload($salonServiceIds, $serviceQuantities));

        Audit::log($request->user()?->id, 'package.updated', 'ServicePackage', $package->id);

        return back()->with('status', 'Service package updated.');
    }

    public function destroyPackage(Request $request, ServicePackage $package): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        if ($package->customerPackages()->exists()) {
            return back()->withErrors([
                'packages' => 'This package already has assigned customer records. Deactivate or edit it instead of deleting it.',
            ]);
        }

        $package->salonServices()->detach();
        $package->delete();

        Audit::log($request->user()?->id, 'package.deleted', 'ServicePackage', $package->id);

        return back()->with('status', 'Service package deleted.');
    }

    public function assignPackage(Request $request, PackageBalanceService $packageBalanceService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'service_package_id' => ['required', 'exists:service_packages,id'],
            'notes' => ['nullable', 'string'],
        ]);

        $customer = Customer::findOrFail((int) $data['customer_id']);
        $package = ServicePackage::findOrFail((int) $data['service_package_id']);

        $customerPackage = $packageBalanceService->assignPackage($customer, $package, $request->user()?->id, $data['notes'] ?? null);

        Audit::log($request->user()?->id, 'package.assigned', 'CustomerPackage', $customerPackage->id);

        return back()->with('status', 'Package assigned.');
    }

    public function consumePackage(Request $request, CustomerPackage $customerPackage, PackageBalanceService $packageBalanceService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $request->validate([
            'sessions_used' => ['nullable', 'integer', 'min:0'],
            'value_used' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $usage = $packageBalanceService->consume(
            $customerPackage,
            (int) ($data['sessions_used'] ?? 0),
            (float) ($data['value_used'] ?? 0),
            $request->user()?->id,
            $data['notes'] ?? null,
        );

        Audit::log($request->user()?->id, 'package.consumed', 'CustomerPackageUsage', $usage->id);

        return back()->with('status', 'Package usage recorded.');
    }

    public function issueGiftCard(Request $request, GiftCardService $giftCardService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $this->prepareNfcUidField($request, 'nfc_uid');

        $data = $request->validate([
            'assigned_customer_id' => ['nullable', 'exists:customers,id'],
            'initial_value' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string'],
            'nfc_uid' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('gift_cards', 'nfc_uid'),
                Rule::unique('customer_membership_cards', 'nfc_uid'),
            ],
        ]);

        $customer = ! empty($data['assigned_customer_id']) ? Customer::find((int) $data['assigned_customer_id']) : null;

        $giftCard = $giftCardService->issue(
            $customer,
            (float) $data['initial_value'],
            $request->user()?->id,
            $data['notes'] ?? null,
            $data['nfc_uid'] ?? null,
        );

        Audit::log($request->user()?->id, 'gift_card.issued', 'GiftCard', $giftCard->id);

        return back()->with('status', 'Gift card issued.');
    }

    public function assignGiftCard(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $request->validate([
            'gift_card_id' => ['required', 'exists:gift_cards,id'],
            'assigned_customer_id' => ['required', 'exists:customers,id'],
        ]);

        $giftCard = GiftCard::findOrFail((int) $data['gift_card_id']);
        $giftCard->assigned_customer_id = (int) $data['assigned_customer_id'];
        $giftCard->save();

        Audit::log($request->user()?->id, 'gift_card.assigned', 'GiftCard', $giftCard->id, [
            'assigned_customer_id' => $giftCard->assigned_customer_id,
        ]);

        return back()->with('status', 'Gift card assigned to customer.');
    }

    public function consumeGiftCard(Request $request, GiftCard $giftCard, GiftCardService $giftCardService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        if (! $request->filled('appointment_id')) {
            $request->merge(['appointment_id' => null]);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
        ]);

        $appointmentId = isset($data['appointment_id']) ? (int) $data['appointment_id'] : null;

        $transaction = $giftCardService->consume(
            $giftCard,
            (float) $data['amount'],
            $data['reason'],
            $request->user()?->id,
            $data['notes'] ?? null,
            $appointmentId,
        );

        Audit::log($request->user()?->id, 'gift_card.consumed', 'GiftCardTransaction', $transaction->id);

        return back()->with('status', 'Gift card usage recorded.');
    }

    public function storeCardType(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('membership_card_types', 'name')],
            'kind' => ['required', Rule::in(['physical', 'virtual', 'gift'])],
            'min_points' => ['required', 'integer', 'min:0'],
            'direct_purchase_price' => ['nullable', 'numeric', 'min:0'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'is_active' => ['nullable', 'boolean'],
            'is_transferable' => ['nullable', 'boolean'],
        ]);

        $cardType = MembershipCardType::create([
            ...$data,
            'slug' => $this->generateUniqueCardTypeSlug($data['name']),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'is_transferable' => (bool) ($data['is_transferable'] ?? false),
        ]);

        Audit::log($request->user()?->id, 'loyalty.card_type_created', 'MembershipCardType', $cardType->id);

        return back()->with('status', 'Membership card type created.');
    }

    public function updateCardType(Request $request, MembershipCardType $cardType): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('membership_card_types', 'name')->ignore($cardType->id)],
            'kind' => ['required', Rule::in(['physical', 'virtual', 'gift'])],
            'min_points' => ['required', 'integer', 'min:0'],
            'direct_purchase_price' => ['nullable', 'numeric', 'min:0'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'is_active' => ['nullable', 'boolean'],
            'is_transferable' => ['nullable', 'boolean'],
        ]);

        $cardType->update([
            ...$data,
            'slug' => $this->generateUniqueCardTypeSlug($data['name'], $cardType->id),
            'is_active' => (bool) ($data['is_active'] ?? false),
            'is_transferable' => (bool) ($data['is_transferable'] ?? false),
        ]);

        Audit::log($request->user()?->id, 'loyalty.card_type_updated', 'MembershipCardType', $cardType->id);

        return back()->with('status', 'Membership card type updated.');
    }

    public function assignCard(Request $request, MembershipCardService $membershipCardService, GiftCardService $giftCardService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $this->prepareNfcUidField($request, 'nfc_uid');

        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'membership_card_type_id' => ['required', 'exists:membership_card_types,id'],
            ...$this->rulesOptionalDigitsCardNumber(),
            'nfc_uid' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('customer_membership_cards', 'nfc_uid'),
                Rule::unique('gift_cards', 'nfc_uid'),
            ],
            'status' => ['nullable', Rule::in(['pending', 'active', 'inactive', 'expired'])],
            'notes' => ['nullable', 'string'],
        ]);

        $customer = Customer::findOrFail((int) $data['customer_id']);
        $cardType = MembershipCardType::findOrFail((int) $data['membership_card_type_id']);

        $card = $membershipCardService->assignCard(
            customer: $customer,
            type: $cardType,
            assignedBy: $request->user()?->id,
            attributes: [
                'card_number' => $data['card_number'] ?? null,
                'nfc_uid' => $data['nfc_uid'] ?? null,
                'status' => $data['status'] ?? 'active',
                'notes' => $data['notes'] ?? null,
            ],
        );

        $giftCardService->ensureGiftCardFromMembershipCard($card, $request->user()?->id);

        Audit::log($request->user()?->id, 'loyalty.card_assigned', 'CustomerMembershipCard', $card->id, [
            'customer_id' => $customer->id,
            'card_type_id' => $cardType->id,
        ]);

        return back()->with('status', 'Membership card assigned.');
    }

    public function registerMember(Request $request, MembershipCardService $membershipCardService, GiftCardService $giftCardService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff', 'reception');

        $this->prepareNfcUidField($request, 'nfc_uid');
        $this->mergeNullableMembershipRegistrationFields($request);

        $data = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'registration_date' => ['required', 'date'],
            'staff_name' => ['nullable', 'string', 'max:255'],
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date'],
            'is_first_visit' => ['nullable', 'boolean'],
            'preferred_language' => ['nullable', 'string', 'max:100'],
            'preferred_language_other' => ['nullable', 'string', 'max:255'],
            'heard_about_us' => ['nullable', 'string', 'max:100'],
            'heard_about_us_other' => ['nullable', 'string', 'max:255'],
            'service_interests' => ['nullable', 'array'],
            'service_interests.*' => ['string', 'max:100'],
            'service_interests_other' => ['nullable', 'string', 'max:255'],
            'requires_home_service' => ['nullable', 'boolean'],
            'home_service_location' => ['nullable', 'string'],
            'preferred_visit_frequency' => ['nullable', 'string', 'max:100'],
            'spending_profile' => ['nullable', 'string', 'max:100'],
            'membership_card_type_id' => ['required', 'exists:membership_card_types,id'],
            ...$this->rulesOptionalDigitsCardNumber(),
            'nfc_uid' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('customer_membership_cards', 'nfc_uid'),
                Rule::unique('gift_cards', 'nfc_uid'),
            ],
            'card_status' => ['nullable', Rule::in(['pending', 'active', 'inactive', 'expired'])],
            'card_notes' => ['nullable', 'string'],
            'consent_data_processing' => ['accepted'],
            'consent_marketing' => ['nullable', 'boolean'],
            'signature_name' => ['required', 'string', 'max:255'],
            'signature_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $customer = null;
        $card = null;

        DB::transaction(function () use ($request, $data, $membershipCardService, &$customer, &$card): void {
            $customer = ! empty($data['customer_id'])
                ? Customer::findOrFail((int) $data['customer_id'])
                : new Customer();

            $customer->fill([
                'name' => $data['full_name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'birthday' => $data['date_of_birth'] ?? null,
                'acquisition_source' => $data['heard_about_us_other'] ?: ($data['heard_about_us'] ?? null),
                'notes' => $this->buildCustomerMembershipNotes($data),
                'is_active' => true,
            ]);
            $customer->save();

            $cardType = MembershipCardType::findOrFail((int) $data['membership_card_type_id']);
            $card = $membershipCardService->assignCard(
                customer: $customer,
                type: $cardType,
                assignedBy: $request->user()?->id,
                attributes: [
                    'card_number' => $data['card_number'] ?? null,
                    'nfc_uid' => $data['nfc_uid'] ?? null,
                    'status' => $data['card_status'] ?? 'active',
                    'notes' => $data['card_notes'] ?? null,
                ],
            );

            $giftCardService->ensureGiftCardFromMembershipCard($card, $request->user()?->id);

            MembershipRegistration::create([
                'customer_id' => $customer->id,
                'customer_membership_card_id' => $card->id,
                'membership_card_type_id' => $cardType->id,
                'registered_by' => $request->user()?->id,
                'registration_date' => $data['registration_date'],
                'staff_name' => $data['staff_name'] ?? $request->user()?->name,
                'full_name' => $data['full_name'],
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'nationality' => $data['nationality'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'is_first_visit' => $data['is_first_visit'] ?? null,
                'preferred_language' => $data['preferred_language'] ?? null,
                'preferred_language_other' => $data['preferred_language_other'] ?? null,
                'heard_about_us' => $data['heard_about_us'] ?? null,
                'heard_about_us_other' => $data['heard_about_us_other'] ?? null,
                'service_interests' => array_values($data['service_interests'] ?? []),
                'service_interests_other' => $data['service_interests_other'] ?? null,
                'requires_home_service' => $data['requires_home_service'] ?? null,
                'home_service_location' => $data['home_service_location'] ?? null,
                'preferred_visit_frequency' => $data['preferred_visit_frequency'] ?? null,
                'spending_profile' => $data['spending_profile'] ?? null,
                'consent_data_processing' => true,
                'consent_marketing' => (bool) ($data['consent_marketing'] ?? false),
                'signature_name' => $data['signature_name'],
                'signature_date' => $data['signature_date'],
                'notes' => $data['notes'] ?? null,
            ]);
        });

        Audit::log($request->user()?->id, 'loyalty.member_registered', 'Customer', $customer->id, [
            'membership_card_id' => $card?->id,
            'membership_card_type_id' => $data['membership_card_type_id'],
        ]);

        return back()->with('status', 'Membership registration saved and card assigned.');
    }

    public function issueInventoryCard(Request $request, MembershipCardService $membershipCardService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $this->prepareNfcUidField($request, 'nfc_uid');

        $data = $request->validate([
            'membership_card_type_id' => ['required', 'exists:membership_card_types,id'],
            ...$this->rulesOptionalDigitsCardNumber(),
            'nfc_uid' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('customer_membership_cards', 'nfc_uid'),
                Rule::unique('gift_cards', 'nfc_uid'),
            ],
            'status' => ['nullable', Rule::in(['pending', 'active', 'inactive', 'expired'])],
            'notes' => ['nullable', 'string'],
        ]);

        $cardType = MembershipCardType::findOrFail((int) $data['membership_card_type_id']);

        $card = $membershipCardService->issueInventoryCard(
            type: $cardType,
            assignedBy: $request->user()?->id,
            attributes: [
                'card_number' => $data['card_number'] ?? null,
                'nfc_uid' => $data['nfc_uid'] ?? null,
                'status' => $data['status'] ?? 'pending',
                'notes' => $data['notes'] ?? null,
            ],
        );

        Audit::log($request->user()?->id, 'loyalty.card_inventory_issued', 'CustomerMembershipCard', $card->id, [
            'card_type_id' => $cardType->id,
            'card_number' => $card->card_number,
        ]);

        return back()->with('status', 'Membership card pre-issued (not assigned to a customer yet). Card # '.$card->card_number);
    }

    public function linkInventoryCardToCustomer(Request $request, MembershipCardService $membershipCardService, GiftCardService $giftCardService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'customer_membership_card_id' => ['required', 'exists:customer_membership_cards,id'],
            'status' => ['nullable', Rule::in(['pending', 'active', 'inactive', 'expired'])],
            'notes' => ['nullable', 'string'],
        ]);

        $customer = Customer::findOrFail((int) $data['customer_id']);
        $card = CustomerMembershipCard::query()->findOrFail((int) $data['customer_membership_card_id']);

        $linkAttributes = [
            'status' => $data['status'] ?? 'active',
        ];
        if (($data['notes'] ?? '') !== '') {
            $linkAttributes['notes'] = $data['notes'];
        }

        $card = $membershipCardService->assignInventoryToCustomer(
            customer: $customer,
            card: $card,
            assignedBy: $request->user()?->id,
            attributes: $linkAttributes,
        );

        $giftCardService->ensureGiftCardFromMembershipCard($card, $request->user()?->id);

        Audit::log($request->user()?->id, 'loyalty.card_inventory_linked', 'CustomerMembershipCard', $card->id, [
            'customer_id' => $customer->id,
        ]);

        return back()->with('status', 'Pre-issued card linked to customer.');
    }

    public function lookupCardByNfc(Request $request, MembershipCardService $membershipCardService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $this->prepareNfcUidField($request, 'nfc_uid');

        $data = $request->validate([
            'nfc_uid' => ['required', 'string', 'max:255'],
        ]);

        $card = $membershipCardService->findByNfcUid($data['nfc_uid']);

        if (! $card) {
            return back()
                ->withErrors(['nfc_uid' => 'No membership card was found for this NFC UID.'])
                ->with('nfc_lookup', null);
        }

        return back()
            ->with('nfc_lookup', [
                'customer_name' => $card->customer?->name,
                'customer_phone' => $card->customer?->phone,
                'customer_email' => $card->customer?->email,
                'is_unassigned' => $card->customer_id === null,
                'card_number' => $card->card_number,
                'card_type_name' => $card->type?->name,
                'card_status' => $card->status,
                'nfc_uid' => $card->nfc_uid,
            ])
            ->with('status', 'NFC card located.');
    }

    public function bindCardNfc(Request $request, MembershipCardService $membershipCardService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $this->prepareNfcUidField($request, 'nfc_uid');

        $data = $request->validate([
            'customer_membership_card_id' => ['required', 'exists:customer_membership_cards,id'],
            'nfc_uid' => ['required', 'string', 'max:255'],
            'replace_existing' => ['nullable', 'boolean'],
        ]);

        $card = CustomerMembershipCard::findOrFail((int) $data['customer_membership_card_id']);
        $card = $membershipCardService->bindNfcUid(
            $card,
            $data['nfc_uid'],
            $request->user()?->id,
            (bool) ($data['replace_existing'] ?? false),
        );

        Audit::log($request->user()?->id, 'loyalty.card_nfc_bound', 'CustomerMembershipCard', $card->id, [
            'customer_id' => $card->customer_id,
            'nfc_uid' => $card->nfc_uid,
            'replace_existing' => (bool) ($data['replace_existing'] ?? false),
        ]);

        return back()->with('status', 'NFC UID linked to membership card.');
    }

    public function updateMembershipCard(Request $request, CustomerMembershipCard $card, MembershipCardService $membershipCardService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $this->prepareNfcUidField($request, 'nfc_uid');

        $data = $request->validate([
            'membership_card_type_id' => ['required', 'exists:membership_card_types,id'],
            ...$this->rulesOptionalDigitsCardNumber(),
            'nfc_uid' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('customer_membership_cards', 'nfc_uid')->ignore($card->id),
                Rule::unique('gift_cards', 'nfc_uid'),
            ],
            'status' => ['required', Rule::in(['pending', 'active', 'inactive', 'expired'])],
            'notes' => ['nullable', 'string'],
        ]);

        $cardType = MembershipCardType::findOrFail((int) $data['membership_card_type_id']);

        $card = $membershipCardService->updateCard($card, $cardType, [
            'card_number' => $data['card_number'] ?? null,
            'nfc_uid' => $data['nfc_uid'] ?? null,
            'status' => $data['status'],
            'notes' => $data['notes'] ?? null,
        ], $request->user()?->id);

        Audit::log($request->user()?->id, 'loyalty.card_updated', 'CustomerMembershipCard', $card->id, [
            'customer_id' => $card->customer_id,
            'card_type_id' => $card->membership_card_type_id,
        ]);

        return back()->with('status', 'Membership card updated.');
    }

    public function destroyMembershipCard(Request $request, CustomerMembershipCard $card): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        if ($card->registrations()->exists()) {
            return back()->withErrors([
                'membership_cards' => 'This membership card is linked to a registration record. Update it instead of deleting it.',
            ]);
        }

        $cardId = $card->id;
        $card->delete();

        Audit::log($request->user()?->id, 'loyalty.card_deleted', 'CustomerMembershipCard', $cardId);

        return back()->with('status', 'Membership card deleted.');
    }

    public function lookupGiftCardByNfc(Request $request, GiftCardService $giftCardService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $this->prepareNfcUidField($request, 'gift_nfc_uid');

        $data = $request->validate([
            'gift_nfc_uid' => ['required', 'string', 'max:255'],
        ]);

        $giftCard = $giftCardService->findByNfcUid($data['gift_nfc_uid']);

        if (! $giftCard) {
            return back()
                ->withErrors(['gift_nfc_uid' => 'No gift card was found for this NFC UID.'])
                ->with('gift_nfc_lookup', null);
        }

        return back()
            ->with('gift_nfc_lookup', [
                'code' => $giftCard->code,
                'customer_name' => $giftCard->customer?->name,
                'customer_phone' => $giftCard->customer?->phone,
                'customer_email' => $giftCard->customer?->email,
                'remaining_value' => $giftCard->remaining_value,
                'status' => $giftCard->status,
                'nfc_uid' => $giftCard->nfc_uid,
            ])
            ->with('status', 'Gift card NFC located.');
    }

    public function bindGiftCardNfc(Request $request, GiftCardService $giftCardService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $this->prepareNfcUidField($request, 'nfc_uid');

        $data = $request->validate([
            'gift_card_id' => ['required', 'exists:gift_cards,id'],
            'nfc_uid' => [
                'required',
                'string',
                'max:255',
                Rule::unique('customer_membership_cards', 'nfc_uid'),
                Rule::unique('gift_cards', 'nfc_uid')->ignore((int) $request->input('gift_card_id')),
            ],
            'replace_existing' => ['nullable', 'boolean'],
        ]);

        $giftCard = GiftCard::findOrFail((int) $data['gift_card_id']);
        $giftCard = $giftCardService->bindNfcUid(
            $giftCard,
            $data['nfc_uid'],
            $request->user()?->id,
            (bool) ($data['replace_existing'] ?? false),
        );

        Audit::log($request->user()?->id, 'gift_card.nfc_bound', 'GiftCard', $giftCard->id, [
            'nfc_uid' => $giftCard->nfc_uid,
            'replace_existing' => (bool) ($data['replace_existing'] ?? false),
        ]);

        return back()->with('status', 'NFC UID linked to gift card.');
    }

    public function storeTier(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('loyalty_tiers', 'name')],
            'min_points' => ['required', 'integer', 'min:0'],
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'earn_multiplier' => ['required', 'numeric', 'min:0.1', 'max:5'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $tier = LoyaltyTier::create([
            ...$data,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        Audit::log($request->user()?->id, 'loyalty.tier_created', 'LoyaltyTier', $tier->id);

        return back()->with('status', 'Loyalty tier created.');
    }

    public function updateTier(Request $request, LoyaltyTier $tier): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('loyalty_tiers', 'name')->ignore($tier->id)],
            'min_points' => ['required', 'integer', 'min:0'],
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'earn_multiplier' => ['required', 'numeric', 'min:0.1', 'max:5'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $tier->update([
            ...$data,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        Audit::log($request->user()?->id, 'loyalty.tier_updated', 'LoyaltyTier', $tier->id);

        return back()->with('status', 'Loyalty tier updated.');
    }

    public function storeLedger(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'points_change' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        if ((int) $data['points_change'] === 0) {
            return back()->withErrors(['points_change' => 'Points change cannot be zero.']);
        }

        $ledger = app(LoyaltyService::class)->applyPoints(
            customerId: (int) $data['customer_id'],
            pointsChange: (int) $data['points_change'],
            reason: $data['reason'],
            reference: $data['reference'] ?? null,
            createdBy: $request->user()?->id,
            notes: $data['notes'] ?? null
        );

        Audit::log($request->user()?->id, 'loyalty.points_changed', 'Customer', (int) $data['customer_id'], [
            'points_change' => (int) $data['points_change'],
            'balance_after' => $ledger->balance_after,
        ]);

        return back()->with('status', 'Loyalty points updated.');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $data = $request->validate([
            'auto_earn_enabled' => ['required', 'boolean'],
            'points_per_currency' => ['required', 'numeric', 'min:0', 'max:100'],
            'points_per_visit' => ['required', 'integer', 'min:0', 'max:1000'],
            'birthday_bonus_points' => ['required', 'integer', 'min:0', 'max:10000'],
            'referral_bonus_points' => ['required', 'integer', 'min:0', 'max:10000'],
            'review_bonus_points' => ['required', 'integer', 'min:0', 'max:10000'],
            'minimum_spend' => ['required', 'numeric', 'min:0', 'max:100000'],
            'rounding_mode' => ['required', Rule::in(['floor', 'round', 'ceil'])],
        ]);

        $settings = LoyaltyProgramSetting::current();
        $settings->update($data);

        Audit::log($request->user()?->id, 'loyalty.settings_updated', 'LoyaltyProgramSetting', $settings->id, $data);

        return back()->with('status', 'Loyalty auto-earn settings updated.');
    }

    public function awardBonus(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'bonus_type' => ['required', Rule::in(['referral', 'review', 'birthday'])],
        ]);

        $awarded = app(LoyaltyService::class)->awardConfiguredBonus(
            customerId: (int) $data['customer_id'],
            bonusType: $data['bonus_type'],
            createdBy: $request->user()?->id
        );

        if (! $awarded) {
            return back()->withErrors(['bonus_type' => 'Configured points for this bonus type is zero.']);
        }

        Audit::log($request->user()?->id, 'loyalty.bonus_awarded', 'Customer', (int) $data['customer_id'], ['bonus_type' => $data['bonus_type']]);

        return back()->with('status', 'Bonus points awarded.');
    }

    public function storeReward(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $this->mergeNullableRewardRuleFields($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'points_cost' => ['required', 'integer', 'min:1'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'max_units_per_redemption' => ['nullable', 'integer', 'min:1', 'max:'.LoyaltyRedemptionRulesService::GLOBAL_MAX_UNITS_PER_REQUEST],
            'max_redemptions_per_calendar_month' => ['nullable', 'integer', 'min:1', 'max:366'],
            'min_days_between_redemptions' => ['nullable', 'integer', 'min:1', 'max:366'],
            'requires_appointment_id' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'salon_service_ids' => ['nullable', 'array'],
            'salon_service_ids.*' => ['integer', 'exists:salon_services,id'],
        ]);

        $salonIds = array_values(array_unique(array_map('intval', $data['salon_service_ids'] ?? [])));
        unset($data['salon_service_ids']);

        $reward = LoyaltyReward::create([
            ...$data,
            'is_active' => (bool) ($data['is_active'] ?? true),
            'requires_appointment_id' => (bool) ($data['requires_appointment_id'] ?? false),
        ]);

        $reward->allowedSalonServices()->sync($salonIds);

        Audit::log($request->user()?->id, 'loyalty.reward_created', 'LoyaltyReward', $reward->id);

        return back()->with('status', 'Loyalty reward created.');
    }

    public function updateReward(Request $request, LoyaltyReward $reward): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager');

        $this->mergeNullableRewardRuleFields($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'points_cost' => ['required', 'integer', 'min:1'],
            'stock_quantity' => ['nullable', 'integer', 'min:0'],
            'max_units_per_redemption' => ['nullable', 'integer', 'min:1', 'max:'.LoyaltyRedemptionRulesService::GLOBAL_MAX_UNITS_PER_REQUEST],
            'max_redemptions_per_calendar_month' => ['nullable', 'integer', 'min:1', 'max:366'],
            'min_days_between_redemptions' => ['nullable', 'integer', 'min:1', 'max:366'],
            'requires_appointment_id' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'salon_service_ids' => ['nullable', 'array'],
            'salon_service_ids.*' => ['integer', 'exists:salon_services,id'],
        ]);

        $salonIds = array_values(array_unique(array_map('intval', $data['salon_service_ids'] ?? [])));
        unset($data['salon_service_ids']);

        $reward->update([
            ...$data,
            'is_active' => (bool) ($data['is_active'] ?? false),
            'requires_appointment_id' => (bool) ($data['requires_appointment_id'] ?? false),
        ]);

        $reward->allowedSalonServices()->sync($salonIds);

        Audit::log($request->user()?->id, 'loyalty.reward_updated', 'LoyaltyReward', $reward->id);

        return back()->with('status', 'Loyalty reward updated.');
    }

    public function redeem(Request $request, LoyaltyRedemptionRulesService $redemptionRules): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        if (! $request->filled('appointment_id')) {
            $request->merge(['appointment_id' => null]);
        }

        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'loyalty_reward_id' => ['required', 'exists:loyalty_rewards,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.LoyaltyRedemptionRulesService::GLOBAL_MAX_UNITS_PER_REQUEST],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
        ]);

        $reward = LoyaltyReward::query()
            ->with('allowedSalonServices:id,name')
            ->findOrFail($data['loyalty_reward_id']);
        if (! $reward->is_active) {
            throw ValidationException::withMessages(['loyalty_reward_id' => 'Reward is inactive.']);
        }

        $quantity = (int) $data['quantity'];
        $appointmentId = isset($data['appointment_id']) ? (int) $data['appointment_id'] : null;

        $redemptionRules->assertCanRedeem(
            (int) $data['customer_id'],
            $reward,
            $quantity,
            $appointmentId,
        );

        $totalCost = $reward->points_cost * $quantity;

        $account = CustomerLoyaltyAccount::query()->firstOrCreate(
            ['customer_id' => $data['customer_id']],
            ['current_points' => 0]
        );

        if ($account->current_points < $totalCost) {
            throw ValidationException::withMessages(['customer_id' => 'Insufficient loyalty points for redemption.']);
        }

        if ($reward->stock_quantity !== null && $reward->stock_quantity < $quantity) {
            throw ValidationException::withMessages(['quantity' => 'Not enough reward stock available.']);
        }

        DB::transaction(function () use ($request, $data, $reward, $quantity, $totalCost, $account, $appointmentId): void {
            $nextBalance = $account->current_points - $totalCost;

            $tier = LoyaltyTier::query()
                ->where('is_active', true)
                ->where('min_points', '<=', $nextBalance)
                ->orderByDesc('min_points')
                ->first();

            $account->update([
                'current_points' => $nextBalance,
                'loyalty_tier_id' => $tier?->id,
                'last_activity_at' => now(),
            ]);

            LoyaltyRedemption::create([
                'customer_id' => (int) $data['customer_id'],
                'loyalty_reward_id' => $reward->id,
                'appointment_id' => $appointmentId,
                'points_spent' => $totalCost,
                'quantity' => $quantity,
                'status' => 'redeemed',
                'redeemed_by' => $request->user()?->id,
            ]);

            CustomerLoyaltyLedger::create([
                'customer_id' => (int) $data['customer_id'],
                'loyalty_tier_id' => $tier?->id,
                'points_change' => -$totalCost,
                'balance_after' => $nextBalance,
                'reason' => 'Reward redemption: '.$reward->name,
                'reference' => 'REWARD-'.$reward->id,
                'created_by' => $request->user()?->id,
            ]);

            if ($reward->stock_quantity !== null) {
                $reward->decrement('stock_quantity', $quantity);
            }

            Audit::log($request->user()?->id, 'loyalty.redeemed', 'Customer', (int) $data['customer_id'], [
                'reward_id' => $reward->id,
                'points_spent' => $totalCost,
            ]);
        });

        return back()->with('status', 'Reward redeemed successfully.');
    }

    private function mergeNullableRewardRuleFields(Request $request): void
    {
        foreach (['max_units_per_redemption', 'max_redemptions_per_calendar_month', 'min_days_between_redemptions'] as $field) {
            if (! $request->filled($field)) {
                $request->merge([$field => null]);
            }
        }
    }

    private function mergeNullableMembershipRegistrationFields(Request $request): void
    {
        foreach ([
            'customer_id',
            'email',
            'nationality',
            'date_of_birth',
            'preferred_language',
            'preferred_language_other',
            'heard_about_us',
            'heard_about_us_other',
            'service_interests_other',
            'home_service_location',
            'preferred_visit_frequency',
            'spending_profile',
            'card_number',
            'nfc_uid',
            'card_notes',
            'notes',
        ] as $field) {
            if (! $request->filled($field)) {
                $request->merge([$field => null]);
            }
        }

        foreach (['is_first_visit', 'requires_home_service', 'consent_marketing'] as $field) {
            $request->merge([$field => $request->boolean($field)]);
        }
    }

    private function buildCustomerMembershipNotes(array $data): ?string
    {
        $parts = array_filter([
            ! empty($data['nationality']) ? 'Nationality: '.$data['nationality'] : null,
            ! empty($data['preferred_language']) ? 'Language: '.$data['preferred_language'].(! empty($data['preferred_language_other']) ? ' - '.$data['preferred_language_other'] : '') : null,
            ! empty($data['preferred_visit_frequency']) ? 'Visit frequency: '.$data['preferred_visit_frequency'] : null,
            ! empty($data['requires_home_service']) ? 'Home service requested'.(! empty($data['home_service_location']) ? ' - '.$data['home_service_location'] : '') : null,
            ! empty($data['notes']) ? 'Membership notes: '.$data['notes'] : null,
        ]);

        return empty($parts) ? null : implode(PHP_EOL, $parts);
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<int>, 2: array<string, int>}
     */
    private function validatePackagePayload(Request $request): array
    {
        $this->mergeNullablePackageFields($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'initial_value' => ['nullable', 'numeric', 'min:0'],
            'validity_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'services_per_visit_limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
            'salon_service_ids' => ['required', 'array', 'min:1'],
            'salon_service_ids.*' => ['integer', 'exists:salon_services,id'],
            'service_quantities' => ['nullable', 'array'],
        ]);

        $salonServiceIds = array_values(array_unique(array_map('intval', $data['salon_service_ids'] ?? [])));
        $serviceQuantities = collect($request->input('service_quantities', []))
            ->mapWithKeys(fn ($value, $key) => [(string) (int) $key => max(1, (int) $value)])
            ->all();
        unset($data['salon_service_ids']);

        return [$data, $salonServiceIds, $serviceQuantities];
    }

    private function mergeNullablePackageFields(Request $request): void
    {
        foreach (['usage_limit', 'initial_value', 'validity_days', 'services_per_visit_limit'] as $field) {
            if (! $request->filled($field)) {
                $request->merge([$field => null]);
            }
        }
    }

    /**
     * @param list<int> $salonServiceIds
     * @param array<string, int> $serviceQuantities
     * @return array<int, array<string, int>>
     */
    private function buildPackageServiceSyncPayload(array $salonServiceIds, array $serviceQuantities): array
    {
        $payload = [];
        foreach ($salonServiceIds as $serviceId) {
            $payload[$serviceId] = [
                'included_sessions' => max(1, (int) ($serviceQuantities[(string) $serviceId] ?? 1)),
            ];
        }

        return $payload;
    }

    /**
     * NFC UIDs are stored uppercase; normalize before validation so unique rules and SQLite match the DB.
     */
    private function prepareNfcUidField(Request $request, string $field = 'nfc_uid'): void
    {
        if (! array_key_exists($field, $request->all())) {
            return;
        }

        $raw = $request->input($field);
        if ($raw === null || $raw === '') {
            $request->merge([$field => null]);

            return;
        }

        $normalized = strtoupper(trim((string) $raw));
        $request->merge([$field => $normalized === '' ? null : $normalized]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function rulesOptionalDigitsCardNumber(): array
    {
        return [
            'card_number' => [
                'nullable',
                'string',
                'max:32',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    if (! preg_match('/^[0-9]+$/', (string) $value)) {
                        $fail('The card number must contain digits only.');
                    }
                },
            ],
        ];
    }

    private function generateUniqueCardTypeSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = 'card-type';
        }

        $suffix = '';
        $attempt = 0;

        while (true) {
            $candidate = substr($base, 0, 100 - strlen($suffix)).$suffix;

            $exists = MembershipCardType::query()
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->where('slug', $candidate)
                ->exists();

            if (! $exists) {
                return $candidate;
            }

            $attempt++;
            $suffix = '-'.$attempt;
        }
    }
}
