<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentPhoto;
use App\Models\AppointmentProductUsage;
use App\Models\AppointmentServiceLog;
use App\Models\BookingRule;
use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\InventoryItem;
use App\Models\InvoicePayment;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\TaxInvoice;
use App\Services\BookingAvailabilityService;
use App\Services\DueServiceManager;
use App\Services\LoyaltyService;
use App\Services\TaxInvoiceDraftFromAppointmentService;
use App\Services\TaxInvoiceFinalizeService;
use App\Services\TaxInvoicePaymentService;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();
        $rules = BookingRule::current();

        $appointmentRows = Appointment::query()
            ->with([
                'service',
                'staffProfile.user',
                'serviceExecution.staffProfile.user',
                'photos',
                'productUsages.item',
                'taxInvoices.payments',
            ])
            ->when($status === 'upcoming', function ($query): void {
                $query->where('scheduled_start', '>=', now())
                    ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_IN_PROGRESS]);
            })
            ->when($status && $status !== 'upcoming', fn ($query) => $query->where('status', $status))
            ->orderByDesc('scheduled_start')
            ->limit(200)
            ->get();

        $customerIds = $appointmentRows->pluck('customer_id')->filter()->unique()->values()->all();

        $appointments = $appointmentRows->map(fn (Appointment $appointment) => $this->serializeAppointment($appointment, $request));

        $giftCardsForCheckout = $customerIds === []
            ? []
            : GiftCard::query()
                ->where('status', 'active')
                ->where('remaining_value', '>', 0)
                ->where(function ($query) use ($customerIds): void {
                    $query->whereIn('assigned_customer_id', $customerIds)
                        ->orWhereNull('assigned_customer_id');
                })
                ->orderBy('code')
                ->limit(200)
                ->get(['id', 'code', 'remaining_value', 'assigned_customer_id'])
                ->map(fn (GiftCard $card) => [
                    'id' => $card->id,
                    'code' => $card->code,
                    'remaining_value' => (float) $card->remaining_value,
                    'assigned_customer_id' => $card->assigned_customer_id,
                ])
                ->values()
                ->all();

        return Inertia::render('Appointments/Index', [
            'appointments' => $appointments,
            'services' => SalonService::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'category', 'price', 'duration_minutes', 'buffer_minutes']),
            'customers' => Customer::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(750)
                ->get(['id', 'name', 'phone', 'email'])
                ->map(fn (Customer $customer) => [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'phone' => (string) ($customer->phone ?? ''),
                    'email' => (string) ($customer->email ?? ''),
                ]),
            'staffProfiles' => StaffProfile::query()->with('user:id,name')->where('is_active', true)->orderBy('employee_code')->get()->map(fn (StaffProfile $staff) => [
                'id' => $staff->id,
                'name' => $staff->user?->name,
            ]),
            'inventoryItems' => InventoryItem::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'unit', 'selling_price']),
            'statusFilter' => $status,
            'bookingRules' => $rules,
            'defaultStart' => $rules->nextDefaultAppointmentStart(),
            'gift_cards_for_checkout' => $giftCardsForCheckout,
        ]);
    }

    public function store(Request $request, BookingAvailabilityService $availabilityService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $this->validatePayload($request);
        $serviceIds = $this->resolveServiceIdsFromPayload($data);
        if ($serviceIds === []) {
            return back()->withErrors(['service_ids' => 'Please select at least one service.'])->withInput();
        }

        $start = Carbon::parse($data['scheduled_start']);
        $servicePlans = $this->buildServicePlans($serviceIds, $start, $data['scheduled_end'] ?? null);

        if ($windowError = $availabilityService->validateAdvanceWindow($start, enforceSlotInterval: false)) {
            return back()->withErrors(['scheduled_start' => $windowError])->withInput();
        }

        foreach ($servicePlans as $plan) {
            if ($timeRangeError = $this->validateTimeRange($plan['start'], $plan['end'])) {
                return back()->withErrors(['scheduled_end' => $timeRangeError])->withInput();
            }
            if ($salonError = $availabilityService->validateSalonHours($plan['start'], $plan['end'])) {
                return back()->withErrors(['scheduled_start' => $salonError])->withInput();
            }
        }

        if (! empty($data['staff_profile_id']) || count($servicePlans) > 1) {
            $servicePlans = $this->attachStaffAssignments(
                $servicePlans,
                ! empty($data['staff_profile_id']) ? (int) $data['staff_profile_id'] : null,
                $availabilityService
            );
        }

        $customer = $this->resolveCustomer(
            $data['customer_name'],
            $data['customer_phone'],
            $data['customer_email'] ?? null,
        );

        $created = [];
        DB::transaction(function () use ($request, $data, $customer, $servicePlans, &$created): void {
            foreach ($servicePlans as $plan) {
                $created[] = Appointment::create([
                    ...$data,
                    'service_id' => $plan['service']->id,
                    'staff_profile_id' => $plan['staff_profile_id'] ?? null,
                    'customer_id' => $customer->id,
                    'booked_by' => $request->user()?->id,
                    'scheduled_start' => $plan['start'],
                    'scheduled_end' => $plan['end'],
                    'source' => $data['source'] ?? 'admin',
                    'status' => $data['status'] ?? Appointment::STATUS_CONFIRMED,
                ]);
            }
        });

        foreach ($created as $appointment) {
            Audit::log($request->user()?->id, 'appointment.created', 'Appointment', $appointment->id, [
                'scheduled_start' => $appointment->scheduled_start?->toDateTimeString(),
                'scheduled_end' => $appointment->scheduled_end?->toDateTimeString(),
                'service_id' => $appointment->service_id,
            ]);
        }

        $count = count($created);
        return back()->with('status', $count > 1 ? "Appointments created ({$count} services)." : 'Appointment created.');
    }

    public function update(Request $request, Appointment $appointment, BookingAvailabilityService $availabilityService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $this->validatePayload($request, true);
        $serviceIds = $this->resolveServiceIdsFromPayload($data);
        if ($serviceIds === []) {
            return back()->withErrors(['service_ids' => 'Please select at least one service.'])->withInput();
        }

        $start = Carbon::parse($data['scheduled_start']);
        $servicePlans = $this->buildServicePlans($serviceIds, $start, $data['scheduled_end'] ?? null);

        foreach ($servicePlans as $idx => $plan) {
            if ($timeRangeError = $this->validateTimeRange($plan['start'], $plan['end'])) {
                return back()->withErrors(['scheduled_end' => $timeRangeError])->withInput();
            }
            if ($salonError = $availabilityService->validateSalonHours($plan['start'], $plan['end'])) {
                return back()->withErrors(['scheduled_start' => $salonError])->withInput();
            }
        }

        if (! empty($data['staff_profile_id']) || count($servicePlans) > 1) {
            $servicePlans = $this->attachStaffAssignments(
                $servicePlans,
                ! empty($data['staff_profile_id']) ? (int) $data['staff_profile_id'] : null,
                $availabilityService,
                $appointment->id
            );
        }

        $customer = $this->resolveCustomer(
            $data['customer_name'],
            $data['customer_phone'],
            $data['customer_email'] ?? null,
        );

        DB::transaction(function () use ($appointment, $data, $customer, $servicePlans): void {
            $first = $servicePlans[0];
            $appointment->update([
                ...$data,
                'service_id' => $first['service']->id,
                'staff_profile_id' => $first['staff_profile_id'] ?? null,
                'customer_id' => $customer->id,
                'scheduled_start' => $first['start'],
                'scheduled_end' => $first['end'],
            ]);

            if (count($servicePlans) > 1) {
                for ($i = 1; $i < count($servicePlans); $i++) {
                    $plan = $servicePlans[$i];
                    Appointment::create([
                        ...$data,
                        'service_id' => $plan['service']->id,
                        'staff_profile_id' => $plan['staff_profile_id'] ?? null,
                        'customer_id' => $customer->id,
                        'booked_by' => $appointment->booked_by ?? request()->user()?->id,
                        'scheduled_start' => $plan['start'],
                        'scheduled_end' => $plan['end'],
                        'source' => $appointment->source ?: ($data['source'] ?? 'admin'),
                        'status' => $data['status'] ?? $appointment->status,
                    ]);
                }
            }
        });

        Audit::log($request->user()?->id, 'appointment.updated', 'Appointment', $appointment->id);

        return back()->with('status', count($servicePlans) > 1 ? 'Appointment updated and additional service appointments added.' : 'Appointment updated.');
    }

    public function startService(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        if (! $appointment->canTransitionTo(Appointment::STATUS_IN_PROGRESS)) {
            return back()->withErrors(['service' => 'Only confirmed appointments can be started.']);
        }

        $data = $request->validate([
            'intake_notes' => ['nullable', 'string'],
            'service_notes' => ['nullable', 'string'],
            'before_photo' => ['nullable', 'image', 'max:5120'],
        ]);

        DB::transaction(function () use ($request, $appointment, $data): void {
            $appointment->update([
                'status' => Appointment::STATUS_IN_PROGRESS,
                'arrival_time' => $appointment->arrival_time ?? now(),
                'service_start_time' => now(),
                'notes' => $data['service_notes'] ?? $appointment->notes,
            ]);

            $log = AppointmentServiceLog::query()->firstOrNew([
                'appointment_id' => $appointment->id,
            ]);

            $log->fill([
                'staff_profile_id' => $appointment->staff_profile_id,
                'started_by' => $request->user()?->id,
                'started_at' => $log->started_at ?? now(),
                'intake_notes' => $data['intake_notes'] ?? $log->intake_notes,
                'service_notes' => $data['service_notes'] ?? $log->service_notes,
            ]);
            $log->save();

            if ($request->hasFile('before_photo')) {
                $path = $request->file('before_photo')->store('appointment-photos', 'public');

                AppointmentPhoto::create([
                    'appointment_id' => $appointment->id,
                    'type' => 'before',
                    'path' => $path,
                    'uploaded_by' => $request->user()?->id,
                ]);
            }
        });

        Audit::log($request->user()?->id, 'appointment.service_started', 'Appointment', $appointment->id);

        return back()->with('status', 'Service started.');
    }

    public function completeService(Request $request, Appointment $appointment, LoyaltyService $loyaltyService, DueServiceManager $dueServiceManager): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        if ($appointment->status !== Appointment::STATUS_IN_PROGRESS || ! $appointment->canTransitionTo(Appointment::STATUS_COMPLETED)) {
            return back()->withErrors(['service' => 'Only in-progress appointments can be completed.']);
        }

        $request->merge([
            'products' => collect($request->input('products', []))
                ->filter(function ($product) {
                    $inventoryId = $product['inventory_item_id'] ?? null;
                    $notes = $product['notes'] ?? null;
                    $quantityRaw = $product['quantity'] ?? null;
                    $quantity = filled($quantityRaw) ? (int) $quantityRaw : null;

                    // Keep rows only when they have meaningful product input.
                    // A default blank row with quantity=1 should remain optional.
                    return filled($inventoryId)
                        || filled($notes)
                        || ($quantity !== null && $quantity !== 1);
                })
                ->values()
                ->all(),
        ]);

        $data = $request->validate([
            'service_report' => ['required', 'string'],
            'completion_notes' => ['nullable', 'string'],
            'materials_used' => ['nullable', 'string'],
            'after_photo' => ['nullable', 'image', 'max:5120'],
            'products' => ['nullable', 'array'],
            'products.*.inventory_item_id' => ['required_with:products', 'exists:inventory_items,id'],
            'products.*.quantity' => ['required_with:products', 'integer', 'min:1'],
            'products.*.notes' => ['nullable', 'string'],
            'exclude_loyalty_earn' => ['nullable', 'boolean'],
            'create_tax_invoice_draft' => ['nullable', 'boolean'],
            'finish_and_pay' => ['nullable', 'boolean'],
            'checkout_payment_method' => ['nullable', 'required_if:finish_and_pay,true', Rule::in(array_keys(InvoicePayment::methodLabels()))],
            'checkout_gift_card_id' => [
                'nullable',
                Rule::requiredIf(fn () => $request->boolean('finish_and_pay') && $request->string('checkout_payment_method')->toString() === InvoicePayment::METHOD_GIFT_CARD),
                'exists:gift_cards,id',
            ],
            'checkout_paid_at' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        $canInvoice = $user && ($user->hasPermission('can_manage_finance') || $user->hasPermission('can_collect_payments'));
        $finishAndPay = $request->boolean('finish_and_pay');

        if ($finishAndPay && ! $canInvoice) {
            return back()->withErrors([
                'finish_and_pay' => 'You do not have permission to issue receipts or record payments.',
            ])->withInput();
        }

        $createdTaxInvoiceId = null;

        DB::transaction(function () use ($request, $appointment, $data, $loyaltyService, $dueServiceManager, &$createdTaxInvoiceId, $canInvoice, $finishAndPay, $user): void {
            $appointment->update([
                'status' => Appointment::STATUS_COMPLETED,
                'exclude_loyalty_earn' => (bool) ($data['exclude_loyalty_earn'] ?? false),
            ]);

            $log = AppointmentServiceLog::query()->firstOrNew([
                'appointment_id' => $appointment->id,
            ]);

            $log->fill([
                'staff_profile_id' => $appointment->staff_profile_id,
                'started_by' => $log->started_by ?? $request->user()?->id,
                'started_at' => $log->started_at ?? $appointment->service_start_time ?? now(),
                'completed_by' => $request->user()?->id,
                'completed_at' => now(),
                'service_notes' => $appointment->notes,
                'completion_notes' => $data['completion_notes'] ?? null,
                'materials_used' => $data['materials_used'] ?? null,
                'intake_notes' => $log->intake_notes,
            ]);
            $log->save();

            if ($request->hasFile('after_photo')) {
                $path = $request->file('after_photo')->store('appointment-photos', 'public');

                AppointmentPhoto::create([
                    'appointment_id' => $appointment->id,
                    'type' => 'after',
                    'path' => $path,
                    'uploaded_by' => $request->user()?->id,
                ]);
            }

            AppointmentProductUsage::query()
                ->where('appointment_id', $appointment->id)
                ->delete();

            foreach ($data['products'] ?? [] as $product) {
                AppointmentProductUsage::create([
                    'appointment_id' => $appointment->id,
                    'inventory_item_id' => (int) $product['inventory_item_id'],
                    'quantity' => (int) $product['quantity'],
                    'notes' => $product['notes'] ?? null,
                ]);
            }

            $appointment->update([
                'notes' => $data['service_report'],
            ]);

            $appointment->refresh();
            $loyaltyService->earnFromCompletedAppointment($appointment, $request->user()?->id);
            $dueServiceManager->syncForAppointment($appointment);

            $createDraft = $canInvoice && (
                $finishAndPay
                || $request->boolean('create_tax_invoice_draft')
            );

            if ($createDraft && $user) {
                try {
                    $draft = app(TaxInvoiceDraftFromAppointmentService::class)->create(
                        $appointment->fresh(['service', 'customer']),
                        $user->id,
                        $user->name
                    );
                    $createdTaxInvoiceId = $draft->id;
                } catch (\Throwable $e) {
                    report($e);
                    if ($finishAndPay) {
                        throw ValidationException::withMessages([
                            'finish_and_pay' => 'Could not create a tax invoice for this visit. '.$e->getMessage(),
                        ]);
                    }
                }
            }

            if ($finishAndPay && $canInvoice) {
                if (! $createdTaxInvoiceId) {
                    throw ValidationException::withMessages([
                        'finish_and_pay' => 'Tax invoice was not created; checkout could not continue.',
                    ]);
                }

                $invoice = TaxInvoice::query()->findOrFail($createdTaxInvoiceId);
                $invoice = app(TaxInvoiceFinalizeService::class)->finalize($invoice);
                $invoice->refresh();

                $method = (string) $data['checkout_payment_method'];
                $paidAt = filled($data['checkout_paid_at'] ?? null)
                    ? \Carbon\Carbon::parse((string) $data['checkout_paid_at'])
                    : now();

                if ($method === InvoicePayment::METHOD_GIFT_CARD) {
                    $card = GiftCard::query()->findOrFail((int) $data['checkout_gift_card_id']);
                    if ((float) $card->remaining_value + 0.009 < (float) $invoice->total) {
                        throw ValidationException::withMessages([
                            'checkout_gift_card_id' => 'Gift card balance is less than the invoice total.',
                        ]);
                    }
                    if ($invoice->customer_id !== null
                        && $card->assigned_customer_id !== null
                        && (int) $card->assigned_customer_id !== (int) $invoice->customer_id) {
                        throw ValidationException::withMessages([
                            'checkout_gift_card_id' => 'This gift card is assigned to a different customer than this visit.',
                        ]);
                    }
                }

                app(TaxInvoicePaymentService::class)->record($invoice, [
                    'amount' => (float) $invoice->total,
                    'method' => $method,
                    'paid_at' => $paidAt,
                    'reference_note' => 'Finish & pay · appointment #'.$appointment->id,
                    'gift_card_id' => $method === InvoicePayment::METHOD_GIFT_CARD ? (int) $data['checkout_gift_card_id'] : null,
                ], $user);
            }
        });

        Audit::log($request->user()?->id, 'appointment.service_completed', 'Appointment', $appointment->id, [
            'product_count' => count($data['products'] ?? []),
            'tax_invoice_draft_id' => $createdTaxInvoiceId,
        ]);

        $status = 'Service completed.';
        if ($finishAndPay && $createdTaxInvoiceId) {
            $status = 'Service completed, tax receipt issued, and payment recorded.';
        } elseif ($createdTaxInvoiceId) {
            $status .= ' Tax invoice draft created.';
        }

        return back()
            ->with('status', $status)
            ->with('created_tax_invoice_id', $createdTaxInvoiceId);
    }

    public function transition(Request $request, Appointment $appointment, LoyaltyService $loyaltyService, DueServiceManager $dueServiceManager): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $request->validate([
            'status' => ['required', Rule::in([
                Appointment::STATUS_PENDING,
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_IN_PROGRESS,
                Appointment::STATUS_COMPLETED,
                Appointment::STATUS_CANCELLED,
                Appointment::STATUS_NO_SHOW,
            ])],
            'cancellation_reason' => ['nullable', 'string'],
        ]);

        $nextStatus = $data['status'];

        if (! $appointment->canTransitionTo($nextStatus)) {
            return back()->withErrors(['status' => 'Invalid status transition requested.']);
        }

        $payload = ['status' => $nextStatus];

        if ($nextStatus === Appointment::STATUS_IN_PROGRESS) {
            $payload['arrival_time'] = $appointment->arrival_time ?? now();
            $payload['service_start_time'] = now();
        }

        if ($nextStatus === Appointment::STATUS_COMPLETED && ! $appointment->service_start_time) {
            $payload['service_start_time'] = now();
        }

        if (in_array($nextStatus, [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW], true)) {
            $payload['cancellation_reason'] = $data['cancellation_reason'] ?? 'Marked by staff';
        }

        $appointment->update($payload);

        if ($nextStatus === Appointment::STATUS_COMPLETED) {
            $loyaltyService->earnFromCompletedAppointment($appointment, $request->user()?->id);
            $appointment->refresh();
            $dueServiceManager->syncForAppointment($appointment);
        }

        Audit::log($request->user()?->id, 'appointment.status_changed', 'Appointment', $appointment->id, [
            'status' => $nextStatus,
        ]);

        return back()->with('status', 'Appointment status updated.');
    }

    public function destroy(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $appointmentId = $appointment->id;

        DB::transaction(function () use ($appointment): void {
            $paths = $appointment->photos()->pluck('path')->filter()->values()->all();
            foreach ($paths as $path) {
                if (is_string($path) && $path !== '' && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }

            $appointment->delete();
        });

        Audit::log($request->user()?->id, 'appointment.deleted', 'Appointment', $appointmentId);

        return back()->with('status', 'Appointment deleted permanently.');
    }

    private function validatePayload(Request $request, bool $isUpdate = false): array
    {
        return $request->validate([
            'service_id' => ['nullable', 'exists:salon_services,id'],
            'service_ids' => ['nullable', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'exists:salon_services,id'],
            'staff_profile_id' => ['nullable', 'exists:staff_profiles,id'],
            'source' => ['nullable', Rule::in(['public', 'admin'])],
            'status' => ['nullable', Rule::in([
                Appointment::STATUS_PENDING,
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_IN_PROGRESS,
                Appointment::STATUS_COMPLETED,
                Appointment::STATUS_CANCELLED,
                Appointment::STATUS_NO_SHOW,
            ])],
            'scheduled_start' => ['required', 'date'],
            'scheduled_end' => ['nullable', 'date'],
            'arrival_time' => ['nullable', 'date'],
            'service_start_time' => ['nullable', 'date'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
            'cancellation_reason' => ['nullable', 'string'],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, int>
     */
    private function resolveServiceIdsFromPayload(array $data): array
    {
        $raw = $data['service_ids'] ?? [];
        if (! is_array($raw)) {
            $raw = [];
        }

        $ids = array_values(array_unique(array_map('intval', array_filter($raw, fn ($v) => (string) $v !== ''))));

        if ($ids === [] && ! empty($data['service_id'])) {
            $ids = [(int) $data['service_id']];
        }

        return $ids;
    }

    /**
     * @param array<int, int> $serviceIds
     * @return array<int, array{service: SalonService, start: Carbon, end: Carbon}>
     */
    private function buildServicePlans(array $serviceIds, Carbon $start, ?string $requestedEnd): array
    {
        $services = SalonService::query()->whereIn('id', $serviceIds)->get()->keyBy('id');
        $plans = [];
        $cursor = $start->copy();

        foreach ($serviceIds as $idx => $serviceId) {
            /** @var SalonService|null $service */
            $service = $services->get($serviceId);
            if (! $service) {
                continue;
            }

            $itemStart = $cursor->copy();
            if ($idx === 0 && count($serviceIds) === 1 && ! empty($requestedEnd)) {
                $itemEnd = Carbon::parse($requestedEnd);
            } else {
                $itemEnd = $itemStart->copy()->addMinutes((int) $service->duration_minutes + (int) $service->buffer_minutes);
            }

            $plans[] = [
                'service' => $service,
                'start' => $itemStart,
                'end' => $itemEnd,
            ];

            $cursor = $itemEnd->copy();
        }

        return $plans;
    }

    private function validateTimeRange(Carbon $start, Carbon $end): ?string
    {
        if ($end->lessThanOrEqualTo($start)) {
            return 'End time must be after start time.';
        }

        return null;
    }

    /**
     * @param array<int, array{service: SalonService, start: Carbon, end: Carbon}> $servicePlans
     * @return array<int, array{service: SalonService, start: Carbon, end: Carbon, staff_profile_id: int|null}>
     */
    private function attachStaffAssignments(array $servicePlans, ?int $selectedStaffId, BookingAvailabilityService $availabilityService, ?int $firstPlanIgnoreAppointmentId = null): array
    {
        $plansWithStaff = [];

        foreach ($servicePlans as $index => $plan) {
            $ignoreAppointmentId = $index === 0 ? $firstPlanIgnoreAppointmentId : null;

            if ($selectedStaffId !== null) {
                $availabilityError = $availabilityService->validateStaffAvailability($selectedStaffId, $plan['start'], $plan['end'], $ignoreAppointmentId);
                if ($availabilityError) {
                    throw ValidationException::withMessages(['staff_profile_id' => $availabilityError]);
                }

                $plan['staff_profile_id'] = $selectedStaffId;
                $plansWithStaff[] = $plan;
                continue;
            }

            $autoAssignedStaffId = $this->findAvailableStaffByServiceCategory(
                $plan['service'],
                $plan['start'],
                $plan['end'],
                $availabilityService,
                $ignoreAppointmentId
            );

            if ($autoAssignedStaffId === null) {
                throw ValidationException::withMessages([
                    'staff_profile_id' => 'No available staff found for '.$plan['service']->name.' at the selected time.',
                ]);
            }

            $plan['staff_profile_id'] = $autoAssignedStaffId;
            $plansWithStaff[] = $plan;
        }

        return $plansWithStaff;
    }

    private function findAvailableStaffByServiceCategory(SalonService $service, Carbon $start, Carbon $end, BookingAvailabilityService $availabilityService, ?int $ignoreAppointmentId = null): ?int
    {
        $staffProfiles = StaffProfile::query()
            ->where('is_active', true)
            ->orderBy('employee_code')
            ->get(['id', 'skills']);

        $normalizedCategory = $this->normalizeStaffSkill((string) ($service->category ?? ''));

        $matchingStaff = $staffProfiles
            ->filter(function (StaffProfile $staff) use ($normalizedCategory): bool {
                if ($normalizedCategory === '') {
                    return false;
                }

                $skills = is_array($staff->skills) ? $staff->skills : [];
                foreach ($skills as $skill) {
                    $normalizedSkill = $this->normalizeStaffSkill((string) $skill);
                    if ($normalizedSkill === '') {
                        continue;
                    }
                    if ($normalizedSkill === $normalizedCategory || str_contains($normalizedSkill, $normalizedCategory) || str_contains($normalizedCategory, $normalizedSkill)) {
                        return true;
                    }
                }

                return false;
            })
            ->values();

        $candidateStaff = $matchingStaff->isNotEmpty() ? $matchingStaff : $staffProfiles;

        foreach ($candidateStaff as $staff) {
            $availabilityError = $availabilityService->validateStaffAvailability((int) $staff->id, $start, $end, $ignoreAppointmentId);
            if (! $availabilityError) {
                return (int) $staff->id;
            }
        }

        return null;
    }

    private function normalizeStaffSkill(string $value): string
    {
        $normalized = strtolower(trim($value));

        return preg_replace('/[^a-z0-9]+/', '', $normalized) ?? '';
    }

    private function resolveCustomer(string $name, string $phone, ?string $email): Customer
    {
        $customer = Customer::firstOrCreate(
            ['phone' => $phone],
            [
                'name' => $name,
                'email' => $email,
                'customer_code' => 'CUST-'.now()->format('Ymd').'-'.random_int(1000, 9999),
            ],
        );

        $updates = array_filter([
            'name' => $name !== $customer->name ? $name : null,
            'email' => $email && $email !== $customer->email ? $email : null,
        ], fn ($value) => $value !== null);

        if ($updates !== []) {
            $customer->update($updates);
        }

        return $customer;
    }

    private function serializeAppointment(Appointment $appointment, Request $request): array
    {
        $checkout = $appointment->checkoutSummary();
        $canCheckoutUi = $request->user() && (
            $request->user()->hasRole('owner', 'manager')
            || $request->user()->hasPermission('can_manage_finance')
            || $request->user()->hasPermission('can_collect_payments')
        );

        return [
            'id' => $appointment->id,
            'customer_id' => $appointment->customer_id,
            'service_id' => $appointment->service_id,
            'staff_profile_id' => $appointment->staff_profile_id,
            'scheduled_start' => $appointment->scheduled_start,
            'scheduled_end' => $appointment->scheduled_end,
            'customer_name' => $appointment->customer_name,
            'customer_phone' => $appointment->customer_phone,
            'customer_email' => $appointment->customer_email,
            'notes' => $appointment->notes,
            'service_name' => $appointment->service?->name,
            'staff_name' => $appointment->staffProfile?->user?->name,
            'status' => $appointment->status,
            'next_statuses' => $appointment->nextStatuses(),
            'service_execution' => [
                'started_at' => $appointment->serviceExecution?->started_at,
                'completed_at' => $appointment->serviceExecution?->completed_at,
                'intake_notes' => $appointment->serviceExecution?->intake_notes,
                'service_notes' => $appointment->serviceExecution?->service_notes,
                'completion_notes' => $appointment->serviceExecution?->completion_notes,
                'materials_used' => $appointment->serviceExecution?->materials_used,
            ],
            'photos' => $appointment->photos->map(fn (AppointmentPhoto $photo) => [
                'id' => $photo->id,
                'type' => $photo->type,
                'url' => Storage::disk('public')->url($photo->path),
                'uploaded_at' => $photo->created_at,
            ])->values()->all(),
            'product_usages' => $appointment->productUsages->map(fn (AppointmentProductUsage $usage) => [
                'id' => $usage->id,
                'item_name' => $usage->item?->name,
                'item_sku' => $usage->item?->sku,
                'quantity' => $usage->quantity,
                'notes' => $usage->notes,
            ])->values()->all(),
            'awaiting_checkout' => $canCheckoutUi ? $checkout['awaiting_checkout'] : false,
            'checkout_invoice_id' => $canCheckoutUi ? $checkout['checkout_invoice_id'] : null,
        ];
    }
}
