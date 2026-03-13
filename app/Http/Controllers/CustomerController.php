<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\Customer;
use App\Services\CustomerPortalService;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
{
    private const PROFILE_RELATIONS = [
        'loyaltyAccount.tier',
        'membershipCards.type',
        'packages.package',
        'giftCards',
        'portalTokens',
    ];

    public function index(Request $request): Response
    {
        $query = trim($request->string('q')->toString());
        $selectedId = $request->integer('customer_id');

        $customers = Customer::query()
            ->with(self::PROFILE_RELATIONS)
            ->when($query, function ($builder) use ($query) {
                $builder->where(function ($q) use ($query) {
                    $q->where('name', 'like', "%{$query}%")
                        ->orWhere('phone', 'like', "%{$query}%")
                        ->orWhere('customer_code', 'like', "%{$query}%");
                });
            })
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();

        $selectedCustomer = $selectedId
            ? $customers->firstWhere('id', $selectedId) ?? Customer::query()->with(self::PROFILE_RELATIONS)->find($selectedId)
            : $customers->first();

        $history = collect();

        if ($selectedCustomer) {
            $history = Appointment::query()
                ->with(['service:id,name', 'staffProfile.user:id,name'])
                ->where('customer_id', $selectedCustomer->id)
                ->orderByDesc('scheduled_start')
                ->limit(30)
                ->get();
        }

        return Inertia::render('Customers/Index', [
            'filters' => [
                'q' => $query,
            ],
            'customers' => $customers->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'customer_code' => $customer->customer_code,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'points' => $customer->loyaltyAccount?->current_points ?? 0,
                'current_card' => $customer->membershipCards->firstWhere('status', 'active')?->type?->name,
                'allergies' => $customer->allergies,
                'notes' => $customer->notes,
                'acquisition_source' => $customer->acquisition_source,
                'is_active' => $customer->is_active,
                'birthday' => $customer->birthday?->format('Y-m-d'),
            ]),
            'selectedCustomer' => $selectedCustomer ? $this->serializeCustomerProfile($selectedCustomer) : null,
            'history' => $history->map(fn (Appointment $appointment) => [
                'id' => $appointment->id,
                'scheduled_start' => $appointment->scheduled_start,
                'status' => $appointment->status,
                'service_name' => $appointment->service?->name,
                'staff_name' => $appointment->staffProfile?->user?->name,
                'notes' => $appointment->notes,
            ]),
        ]);
    }

    public function issuePortalToken(Request $request, Customer $customer, CustomerPortalService $customerPortalService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $token = $customerPortalService->issueToken($customer, $request->user()?->id);

        Audit::log($request->user()?->id, 'customer.portal_token_issued', 'CustomerPortalToken', $token->id, [
            'customer_id' => $customer->id,
        ]);

        return back()->with('status', 'Customer portal link generated.');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'birthday' => ['nullable', 'date'],
            'allergies' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'acquisition_source' => ['nullable', 'string', 'max:255'],
        ]);

        $customer = Customer::create([
            ...$data,
            'customer_code' => 'CUST-' . now()->format('Ymd') . '-' . random_int(1000, 9999),
            'is_active' => true,
        ]);

        Audit::log($request->user()?->id, 'customer.created', 'Customer', $customer->id);

        return back()->with('status', 'Customer created.');
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'birthday' => ['nullable', 'date'],
            'allergies' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'acquisition_source' => ['nullable', 'string', 'max:255'],
        ]);

        $customer->update($data);

        Audit::log($request->user()?->id, 'customer.updated', 'Customer', $customer->id);

        return back()->with('status', 'Customer updated.');
    }

    private function serializeCustomerProfile(Customer $customer): array
    {
        $customer->loadMissing(self::PROFILE_RELATIONS);

        $currentCard = $customer->membershipCards->firstWhere('status', 'active') ?? $customer->membershipCards->first();
        $activePortalToken = $customer->portalTokens->first(function ($token) {
            return $token->revoked_at === null && (! $token->expires_at || $token->expires_at->isFuture());
        });

        return [
            'id' => $customer->id,
            'customer_code' => $customer->customer_code,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'allergies' => $customer->allergies,
            'notes' => $customer->notes,
            'acquisition_source' => $customer->acquisition_source,
            'birthday' => $customer->birthday?->format('Y-m-d'),
            'points' => $customer->loyaltyAccount?->current_points ?? 0,
            'tier' => $customer->loyaltyAccount?->tier?->name,
            'current_card' => $currentCard?->type?->name,
            'card_status' => $currentCard?->status,
            'card_expires_at' => $currentCard?->expires_at,
            'active_packages' => $customer->packages->where('status', 'active')->map(fn ($package) => [
                'name' => $package->package?->name,
                'remaining_sessions' => $package->remaining_sessions,
                'remaining_value' => $package->remaining_value,
                'status' => $package->status,
                'expires_at' => $package->expires_at,
            ])->values(),
            'gift_cards' => $customer->giftCards->map(fn ($giftCard) => [
                'code' => $giftCard->code,
                'remaining_value' => $giftCard->remaining_value,
                'status' => $giftCard->status,
                'expires_at' => $giftCard->expires_at,
            ])->values(),
            'portal_url' => $activePortalToken ? route('customer.portal.show', $activePortalToken->token) : null,
            'portal_expires_at' => $activePortalToken?->expires_at,
        ];
    }
}
