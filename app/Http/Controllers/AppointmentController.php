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
            'services' => SalonService::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'duration_minutes', 'buffer_minutes']),
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
                ->get(['id', 'name', 'sku', 'unit']),
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

        $service = SalonService::findOrFail($data['service_id']);
        $start = Carbon::parse($data['scheduled_start']);
        $end = Carbon::parse($data['scheduled_end'] ?? $start->copy()->addMinutes($service->duration_minutes + $service->buffer_minutes));

        if ($timeRangeError = $this->validateTimeRange($start, $end)) {
            return back()->withErrors(['scheduled_end' => $timeRangeError])->withInput();
        }

        if ($windowError = $availabilityService->validateAdvanceWindow($start, enforceSlotInterval: false)) {
            return back()->withErrors(['scheduled_start' => $windowError])->withInput();
        }

        if ($salonError = $availabilityService->validateSalonHours($start, $end)) {
            return back()->withErrors(['scheduled_start' => $salonError])->withInput();
        }

        if (! empty($data['staff_profile_id'])) {
            $staffAvailabilityError = $availabilityService->validateStaffAvailability((int) $data['staff_profile_id'], $start, $end);
            if ($staffAvailabilityError) {
                return back()->withErrors(['staff_profile_id' => $staffAvailabilityError])->withInput();
            }
        }

        $customer = $this->resolveCustomer(
            $data['customer_name'],
            $data['customer_phone'],
            $data['customer_email'] ?? null,
        );

        $appointment = Appointment::create([
            ...$data,
            'customer_id' => $customer->id,
            'booked_by' => $request->user()?->id,
            'scheduled_start' => $start,
            'scheduled_end' => $end,
            'source' => $data['source'] ?? 'admin',
            'status' => $data['status'] ?? Appointment::STATUS_CONFIRMED,
        ]);

        Audit::log($request->user()?->id, 'appointment.created', 'Appointment', $appointment->id, [
            'scheduled_start' => $start->toDateTimeString(),
            'scheduled_end' => $end->toDateTimeString(),
        ]);

        return back()->with('status', 'Appointment created.');
    }

    public function update(Request $request, Appointment $appointment, BookingAvailabilityService $availabilityService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $this->validatePayload($request, true);

        $service = SalonService::findOrFail($data['service_id']);
        $start = Carbon::parse($data['scheduled_start']);
        $end = Carbon::parse($data['scheduled_end'] ?? $start->copy()->addMinutes($service->duration_minutes + $service->buffer_minutes));

        if ($timeRangeError = $this->validateTimeRange($start, $end)) {
            return back()->withErrors(['scheduled_end' => $timeRangeError])->withInput();
        }

        if ($salonError = $availabilityService->validateSalonHours($start, $end)) {
            return back()->withErrors(['scheduled_start' => $salonError])->withInput();
        }

        if (! empty($data['staff_profile_id'])) {
            $staffAvailabilityError = $availabilityService->validateStaffAvailability((int) $data['staff_profile_id'], $start, $end, $appointment->id);
            if ($staffAvailabilityError) {
                return back()->withErrors(['staff_profile_id' => $staffAvailabilityError])->withInput();
            }
        }

        $customer = $this->resolveCustomer(
            $data['customer_name'],
            $data['customer_phone'],
            $data['customer_email'] ?? null,
        );

        $appointment->update([
            ...$data,
            'customer_id' => $customer->id,
            'scheduled_start' => $start,
            'scheduled_end' => $end,
        ]);

        Audit::log($request->user()?->id, 'appointment.updated', 'Appointment', $appointment->id);

        return back()->with('status', 'Appointment updated.');
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
                ->filter(fn ($product) => filled($product['inventory_item_id'] ?? null) || filled($product['quantity'] ?? null) || filled($product['notes'] ?? null))
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
            'service_id' => ['required', 'exists:salon_services,id'],
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

    private function validateTimeRange(Carbon $start, Carbon $end): ?string
    {
        if ($end->lessThanOrEqualTo($start)) {
            return 'End time must be after start time.';
        }

        return null;
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
