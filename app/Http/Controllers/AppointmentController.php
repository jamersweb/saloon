<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\AppointmentBlock;
use App\Models\AppointmentPhoto;
use App\Models\AppointmentProductUsage;
use App\Models\AppointmentServiceLog;
use App\Models\BookingRule;
use App\Models\Customer;
use App\Models\CustomerPackage;
use App\Models\GiftCard;
use App\Models\InventoryItem;
use App\Models\InvoicePayment;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Models\TaxInvoice;
use App\Services\BookingAvailabilityService;
use App\Services\AppointmentVisitService;
use App\Services\DueServiceManager;
use App\Services\GiftCardService;
use App\Services\LoyaltyService;
use App\Services\PackageBalanceService;
use App\Services\StaffAppointmentNotificationService;
use App\Services\StaffScheduleGeneratorService;
use App\Services\TaxInvoiceDraftFromAppointmentService;
use App\Services\TaxInvoiceFinalizeService;
use App\Services\TaxInvoicePaymentService;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    public function staffAvailability(Request $request, BookingAvailabilityService $availabilityService, StaffScheduleGeneratorService $staffScheduleGenerator): JsonResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff', 'reception');

        $data = $request->validate([
            'scheduled_start' => ['required', 'date'],
            'scheduled_end' => ['nullable', 'date'],
            'ignore_appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
        ]);

        $start = Carbon::parse($data['scheduled_start']);
        $end = ! empty($data['scheduled_end'])
            ? Carbon::parse($data['scheduled_end'])
            : $start->copy()->addMinutes(30);

        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->copy()->addMinutes(30);
        }

        $staffScheduleGenerator->fillGapsForActiveStaff($start, $start);

        $ignoreAppointmentId = isset($data['ignore_appointment_id']) ? (int) $data['ignore_appointment_id'] : null;

        $staff = StaffProfile::query()
            ->with('user:id,name')
            ->where('is_active', true)
            ->orderBy('employee_code')
            ->get()
            ->map(function (StaffProfile $profile) use ($availabilityService, $start, $end, $ignoreAppointmentId) {
                $availabilityError = $availabilityService->validateStaffAvailability($profile->id, $start, $end, $ignoreAppointmentId);

                return [
                    'id' => $profile->id,
                    'name' => $profile->user?->name,
                    'available' => $availabilityError === null,
                    'reason' => $availabilityError,
                ];
            })
            ->values();

        return response()->json([
            'staff' => $staff,
        ]);
    }

    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();
        $today = now();
        $todayStart = $today->copy()->startOfDay();
        $todayEnd = $today->copy()->endOfDay();
        $rules = BookingRule::current();
        $user = $request->user();
        $isStaff = $user?->hasRole('staff') ?? false;
        $staffProfileId = $user?->staffProfile?->id;
        $appointmentStatuses = [
            Appointment::STATUS_PENDING,
            Appointment::STATUS_CONFIRMED,
            Appointment::STATUS_IN_PROGRESS,
            Appointment::STATUS_COMPLETED,
            Appointment::STATUS_CANCELLED,
            Appointment::STATUS_NO_SHOW,
        ];

        $appointmentRows = Appointment::query()
            ->with([
                'service',
                'staffProfile.user',
                'serviceExecution.staffProfile.user',
                'photos',
                'productUsages.item',
                'customerPackage.package',
                'taxInvoices.payments',
            ])
            ->when($isStaff, fn ($query) => $query->where('staff_profile_id', $staffProfileId ?: 0))
            ->when($status === 'today', function ($query) use ($todayStart, $todayEnd): void {
                $query->whereBetween('scheduled_start', [$todayStart, $todayEnd]);
            })
            ->when($status === 'upcoming', function ($query): void {
                $query->where('scheduled_start', '>=', now())
                    ->whereIn('status', [Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_IN_PROGRESS]);
            })
            ->when($status === 'needs_pay', fn ($query) => $query->where('status', Appointment::STATUS_COMPLETED))
            ->when(in_array($status, $appointmentStatuses, true), fn ($query) => $query->where('status', $status))
            ->when(in_array($status, ['today', 'upcoming'], true), fn ($query) => $query->orderBy('scheduled_start'), fn ($query) => $query->orderByDesc('scheduled_start'))
            ->limit($status === 'needs_pay' ? 500 : 200)
            ->get();

        $customerIds = $appointmentRows->pluck('customer_id')->filter()->unique()->values()->all();
        $appointmentServiceIds = $appointmentRows->pluck('service_id')->filter()->unique()->values()->all();

        $appointments = $appointmentRows
            ->map(fn (Appointment $appointment) => $this->serializeAppointment($appointment, $request))
            ->when($status === 'needs_pay', fn ($rows) => $rows->filter(fn (array $appointment) => $appointment['awaiting_checkout'])->values());

        if ($customerIds !== []) {
            $giftCardService = app(GiftCardService::class);
            foreach ($customerIds as $customerId) {
                $giftCardService->backfillGiftCardsForCustomer((int) $customerId, $request->user()?->id);
            }
        }

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
            'appointmentBlocks' => AppointmentBlock::query()
                ->with('staffProfile.user:id,name')
                ->where('starts_at', '>=', now()->subDays(30))
                ->where('starts_at', '<=', now()->addDays((int) $rules->max_advance_days))
                ->orderBy('starts_at')
                ->limit(500)
                ->get()
                ->map(fn (AppointmentBlock $block) => [
                    'id' => $block->id,
                    'staff_profile_id' => $block->staff_profile_id,
                    'staff_name' => $block->staffProfile?->user?->name,
                    'title' => $block->title,
                    'starts_at' => $block->starts_at,
                    'ends_at' => $block->ends_at,
                    'notes' => $block->notes,
                ]),
            'services' => SalonService::query()
                ->where(function ($query) use ($appointmentServiceIds): void {
                    $query->where('is_active', true)
                        ->when($appointmentServiceIds !== [], fn ($inner) => $inner->orWhereIn('id', $appointmentServiceIds));
                })
                ->orderBy('name')
                ->get(['id', 'name', 'category', 'price', 'duration_minutes', 'buffer_minutes']),
            'customers' => Customer::query()
                ->with([
                    'packages.package.salonServices',
                    'packages.usages',
                    'giftCards',
                ])
                ->where('is_active', true)
                ->orderBy('name')
                ->limit(750)
                ->get()
                ->each(fn (Customer $customer) => app(GiftCardService::class)->backfillGiftCardsForCustomer((int) $customer->id, $request->user()?->id))
                ->load('giftCards')
                ->map(fn (Customer $customer) => $this->serializeCustomerForAppointments($customer)),
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

    public function storeBlockedTime(Request $request, BookingAvailabilityService $availabilityService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'reception');

        $data = $request->validate([
            'staff_profile_id' => ['nullable', 'exists:staff_profiles,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'notes' => ['nullable', 'string'],
        ]);

        $start = Carbon::parse($data['starts_at']);
        $end = Carbon::parse($data['ends_at']);

        if ($salonError = $availabilityService->validateSalonHours($start, $end)) {
            return back()->withErrors(['starts_at' => $salonError])->withInput();
        }

        if (! empty($data['staff_profile_id'])) {
            $staffError = $availabilityService->validateStaffAvailability((int) $data['staff_profile_id'], $start, $end);
            if ($staffError && $staffError !== 'Selected time overlaps blocked time.') {
                return back()->withErrors(['staff_profile_id' => $staffError])->withInput();
            }
        }

        $block = AppointmentBlock::create([
            ...$data,
            'created_by' => $request->user()?->id,
            'starts_at' => $start,
            'ends_at' => $end,
        ]);

        Audit::log($request->user()?->id, 'appointment_block.created', 'AppointmentBlock', $block->id, [
            'starts_at' => $block->starts_at?->toDateTimeString(),
            'ends_at' => $block->ends_at?->toDateTimeString(),
            'staff_profile_id' => $block->staff_profile_id,
        ]);

        return back()->with('status', 'Blocked time added.');
    }

    public function destroyBlockedTime(Request $request, AppointmentBlock $block): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'reception');

        $blockId = $block->id;
        $block->delete();

        Audit::log($request->user()?->id, 'appointment_block.deleted', 'AppointmentBlock', $blockId);

        return back()->with('status', 'Blocked time removed.');
    }

    public function store(Request $request, BookingAvailabilityService $availabilityService, StaffScheduleGeneratorService $staffScheduleGenerator, StaffAppointmentNotificationService $staffAppointmentNotificationService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $this->validatePayload($request);
        $serviceIds = $this->resolveServiceIdsFromPayload($data);
        if ($serviceIds === []) {
            return back()->withErrors(['service_ids' => 'Please select at least one service.'])->withInput();
        }
        $staffAssignments = $this->resolveStaffAssignmentsFromPayload($data, $serviceIds);
        $serviceQuantities = $this->resolveServiceQuantitiesFromPayload($data, $serviceIds);
        $serviceStarts = $this->resolveServiceStartsFromPayload($data, $serviceIds);
        $serviceDurations = $this->resolveServiceIntegerMapFromPayload($data, $serviceIds, 'service_durations', 1);
        $serviceExtraMinutes = $this->resolveServiceIntegerMapFromPayload($data, $serviceIds, 'service_extra_minutes', 0);
        $serviceUnitPrices = $this->resolveServiceMoneyMapFromPayload($data, $serviceIds, 'service_unit_prices');
        $serviceDiscountAmounts = $this->resolveServiceMoneyMapFromPayload($data, $serviceIds, 'service_discount_amounts');

        $start = Carbon::parse($data['scheduled_start']);
        $servicePlans = $this->buildServicePlans($serviceIds, $start, null, $serviceQuantities, $staffAssignments, $serviceStarts, $serviceDurations, $serviceExtraMinutes, $serviceUnitPrices, $serviceDiscountAmounts);

        if ($windowError = $availabilityService->validateAdvanceWindow($start, enforceSlotInterval: false, enforceMinAdvance: false)) {
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

        $this->fillMissingSchedulesForPlans(
            $servicePlans,
            ! empty($data['staff_profile_id']) ? (int) $data['staff_profile_id'] : null,
            $staffAssignments,
            $staffScheduleGenerator,
        );

        if (! empty($data['staff_profile_id']) || count($servicePlans) > 1 || $staffAssignments !== []) {
            $servicePlans = $this->attachStaffAssignments(
                $servicePlans,
                ! empty($data['staff_profile_id']) ? (int) $data['staff_profile_id'] : null,
                $availabilityService,
                null,
                $staffAssignments
            );
        }

        $customer = $this->resolveCustomer(
            $data['customer_name'],
            $data['customer_phone'] ?? '',
            $data['customer_email'] ?? null,
            ! empty($data['customer_id']) ? (int) $data['customer_id'] : null,
        );
        $packageSelection = $this->resolvePackageSelection($data, $serviceIds, $customer?->id);

        $created = [];
        DB::transaction(function () use ($request, $data, $customer, $servicePlans, $packageSelection, &$created): void {
            $visitId = (string) Str::uuid();
            foreach ($servicePlans as $plan) {
                $isPackageCovered = in_array((int) $plan['service']->id, $packageSelection['covered_service_ids'], true);
                $created[] = Appointment::create([
                    ...$data,
                    'service_id' => $plan['service']->id,
                    'service_quantity' => $plan['service_quantity'],
                    'service_unit_price' => $plan['service_unit_price'],
                    'service_discount_amount' => $plan['service_discount_amount'],
                    'service_duration_minutes' => $plan['service_duration_minutes'],
                    'service_extra_minutes' => $plan['service_extra_minutes'],
                    'staff_profile_id' => $plan['staff_profile_id'] ?? null,
                    'customer_id' => $customer?->id,
                    'customer_package_id' => $isPackageCovered ? $packageSelection['customer_package']?->id : null,
                    'visit_id' => $visitId,
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

        $staffAppointmentNotificationService->notifyAssignedStaff($created, 'assigned');

        $count = count($created);
        return back()->with('status', $count > 1 ? "Appointments created ({$count} services)." : 'Appointment created.');
    }

    public function update(Request $request, Appointment $appointment, BookingAvailabilityService $availabilityService, StaffScheduleGeneratorService $staffScheduleGenerator, StaffAppointmentNotificationService $staffAppointmentNotificationService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $this->validatePayload($request, true);
        $serviceIds = $this->resolveServiceIdsFromPayload($data);
        if ($serviceIds === []) {
            return back()->withErrors(['service_ids' => 'Please select at least one service.'])->withInput();
        }
        $serviceQuantities = $this->resolveServiceQuantitiesFromPayload($data, $serviceIds);
        $staffAssignments = $this->resolveStaffAssignmentsFromPayload($data, $serviceIds);
        $serviceStarts = $this->resolveServiceStartsFromPayload($data, $serviceIds);
        $serviceDurations = $this->resolveServiceIntegerMapFromPayload($data, $serviceIds, 'service_durations', 1);
        $serviceExtraMinutes = $this->resolveServiceIntegerMapFromPayload($data, $serviceIds, 'service_extra_minutes', 0);
        $serviceUnitPrices = $this->resolveServiceMoneyMapFromPayload($data, $serviceIds, 'service_unit_prices');
        $serviceDiscountAmounts = $this->resolveServiceMoneyMapFromPayload($data, $serviceIds, 'service_discount_amounts');

        $start = Carbon::parse($data['scheduled_start']);
        $servicePlans = $this->buildServicePlans($serviceIds, $start, null, $serviceQuantities, $staffAssignments, $serviceStarts, $serviceDurations, $serviceExtraMinutes, $serviceUnitPrices, $serviceDiscountAmounts);

        foreach ($servicePlans as $idx => $plan) {
            if ($timeRangeError = $this->validateTimeRange($plan['start'], $plan['end'])) {
                return back()->withErrors(['scheduled_end' => $timeRangeError])->withInput();
            }
            if ($salonError = $availabilityService->validateSalonHours($plan['start'], $plan['end'])) {
                return back()->withErrors(['scheduled_start' => $salonError])->withInput();
            }
        }

        $this->fillMissingSchedulesForPlans(
            $servicePlans,
            ! empty($data['staff_profile_id']) ? (int) $data['staff_profile_id'] : null,
            $staffAssignments,
            $staffScheduleGenerator,
        );

        if (! empty($data['staff_profile_id']) || count($servicePlans) > 1 || $staffAssignments !== []) {
            $servicePlans = $this->attachStaffAssignments(
                $servicePlans,
                ! empty($data['staff_profile_id']) ? (int) $data['staff_profile_id'] : null,
                $availabilityService,
                $appointment->id,
                $staffAssignments
            );
        }

        $customer = $this->resolveCustomer(
            $data['customer_name'],
            $data['customer_phone'] ?? '',
            $data['customer_email'] ?? null,
            ! empty($data['customer_id']) ? (int) $data['customer_id'] : null,
        );
        $packageSelection = $this->resolvePackageSelection($data, $serviceIds, $customer?->id, $appointment->id);

        $notifiableAppointments = [];

        DB::transaction(function () use ($appointment, $data, $customer, $servicePlans, $packageSelection, &$notifiableAppointments): void {
            $visitId = $appointment->visit_id ?: (string) Str::uuid();
            $visitAppointments = $appointment->visit_id
                ? Appointment::query()
                    ->where('visit_id', $appointment->visit_id)
                    ->orderBy('scheduled_start')
                    ->orderBy('id')
                    ->get()
                : collect([$appointment]);
            $visitAppointments = $visitAppointments
                ->sortBy(fn (Appointment $row) => ((int) $row->id === (int) $appointment->id ? '0' : '1')
                    .'-'.($row->scheduled_start?->timestamp ?? 0)
                    .'-'.$row->id)
                ->values();

            if ($visitAppointments->count() > count($servicePlans)) {
                $extraAppointments = $visitAppointments->slice(count($servicePlans))->values();
                $lockedExtra = $extraAppointments->first(fn (Appointment $extra) => $this->appointmentHasLockedServiceWork($extra));
                if ($lockedExtra) {
                    throw ValidationException::withMessages([
                        'service_ids' => 'A removed service has already started, completed, or has billing/activity attached. Update it separately instead.',
                    ]);
                }

                $extraAppointments->each(fn (Appointment $extra) => $this->deleteEditableAppointment($extra));
            }

            foreach ($servicePlans as $index => $plan) {
                $covered = in_array((int) $plan['service']->id, $packageSelection['covered_service_ids'], true);
                $payload = [
                    ...$data,
                    'service_id' => $plan['service']->id,
                    'service_quantity' => $plan['service_quantity'],
                    'service_unit_price' => $plan['service_unit_price'],
                    'service_discount_amount' => $plan['service_discount_amount'],
                    'service_duration_minutes' => $plan['service_duration_minutes'],
                    'service_extra_minutes' => $plan['service_extra_minutes'],
                    'staff_profile_id' => $plan['staff_profile_id'] ?? null,
                    'customer_id' => $customer?->id,
                    'customer_package_id' => $covered ? $packageSelection['customer_package']?->id : null,
                    'visit_id' => $visitId,
                    'scheduled_start' => $plan['start'],
                    'scheduled_end' => $plan['end'],
                ];

                $targetAppointment = $visitAppointments->get($index);
                if ($targetAppointment) {
                    $targetAppointment->update($payload);
                    $notifiableAppointments[] = $targetAppointment->fresh(['service', 'staffProfile.user']);
                    continue;
                }

                $createdAppointment = Appointment::create([
                    ...$payload,
                    'booked_by' => $appointment->booked_by ?? request()->user()?->id,
                    'source' => $appointment->source ?: ($data['source'] ?? 'admin'),
                    'status' => $data['status'] ?? $appointment->status,
                ]);
                $notifiableAppointments[] = $createdAppointment->load(['service', 'staffProfile.user']);
            }
        });

        Audit::log($request->user()?->id, 'appointment.updated', 'Appointment', $appointment->id);
        $staffAppointmentNotificationService->notifyAssignedStaff($notifiableAppointments, 'updated');

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
            $startedAt = now();

            $appointment->update([
                'status' => Appointment::STATUS_IN_PROGRESS,
                'notes' => $data['service_notes'] ?? $appointment->notes,
                ...$this->serviceStartTimingPayload($appointment, $startedAt),
            ]);

            $log = AppointmentServiceLog::query()->firstOrNew([
                'appointment_id' => $appointment->id,
            ]);

            $log->fill([
                'staff_profile_id' => $appointment->staff_profile_id,
                'started_by' => $request->user()?->id,
                'started_at' => $log->started_at ?? $startedAt,
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

    public function completeService(Request $request, Appointment $appointment, LoyaltyService $loyaltyService, DueServiceManager $dueServiceManager, PackageBalanceService $packageBalanceService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        if (! in_array($appointment->status, [Appointment::STATUS_CONFIRMED, Appointment::STATUS_IN_PROGRESS], true)) {
            return back()->withErrors(['service' => 'Only confirmed or in-progress appointments can be completed.']);
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
            'additional_services' => collect($request->input('additional_services', []))
                ->filter(function ($service) {
                    $serviceId = $service['service_id'] ?? null;
                    $staffId = $service['staff_profile_id'] ?? null;
                    $quantityRaw = $service['quantity'] ?? null;
                    $quantity = filled($quantityRaw) ? (int) $quantityRaw : null;

                    return filled($serviceId)
                        || filled($staffId)
                        || ($quantity !== null && $quantity !== 1);
                })
                ->values()
                ->all(),
        ]);

        $data = $request->validate([
            'service_report' => ['nullable', 'string'],
            'completion_notes' => ['nullable', 'string'],
            'materials_used' => ['nullable', 'string'],
            'after_photo' => ['nullable', 'image', 'max:5120'],
            'products' => ['nullable', 'array'],
            'products.*.inventory_item_id' => ['required_with:products', 'exists:inventory_items,id'],
            'products.*.quantity' => ['required_with:products', 'integer', 'min:1'],
            'products.*.notes' => ['nullable', 'string'],
            'additional_services' => ['nullable', 'array'],
            'additional_services.*.service_id' => ['required_with:additional_services', 'exists:salon_services,id'],
            'additional_services.*.quantity' => ['required_with:additional_services', 'integer', 'min:1'],
            'additional_services.*.staff_profile_id' => ['nullable', 'exists:staff_profiles,id'],
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
        $canFinishAndPayRole = $user && $user->hasRole('manager', 'reception');

        if ($finishAndPay && ! $canFinishAndPayRole) {
            return back()->withErrors([
                'finish_and_pay' => 'Finish & pay is only available for manager and reception roles.',
            ])->withInput();
        }

        if ($finishAndPay && ! $canInvoice) {
            return back()->withErrors([
                'finish_and_pay' => 'You do not have permission to issue receipts or record payments.',
            ])->withInput();
        }

        $createdTaxInvoiceId = null;

        DB::transaction(function () use ($request, $appointment, $data, $loyaltyService, $dueServiceManager, $packageBalanceService, &$createdTaxInvoiceId, $canInvoice, $finishAndPay, $user): void {
            $visitId = $appointment->visit_id ?: (string) Str::uuid();
            if (! $appointment->visit_id) {
                $implicitVisit = app(AppointmentVisitService::class)->forAppointment($appointment);
                Appointment::query()
                    ->whereIn('id', $implicitVisit->pluck('id'))
                    ->whereNull('visit_id')
                    ->update(['visit_id' => $visitId]);
                $appointment->refresh();
            }

            $appointment->update([
                'status' => Appointment::STATUS_COMPLETED,
                'visit_id' => $visitId,
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
                'notes' => $data['service_report'] ?? $appointment->notes,
            ]);

            foreach ($data['additional_services'] ?? [] as $additionalService) {
                $service = SalonService::query()->find((int) $additionalService['service_id']);
                if (! $service) {
                    continue;
                }

                $extraStart = $appointment->scheduled_start?->copy() ?? now();
                $durationMinutes = max(1, (int) ($service->duration_minutes ?? 0) + (int) ($service->buffer_minutes ?? 0));
                $extraEnd = $extraStart->copy()->addMinutes($durationMinutes);

                $extraAppointment = Appointment::create([
                    'customer_id' => $appointment->customer_id,
                    'customer_package_id' => null,
                    'visit_id' => $visitId,
                    'service_id' => $service->id,
                    'service_quantity' => max(1, (int) ($additionalService['quantity'] ?? 1)),
                    'staff_profile_id' => filled($additionalService['staff_profile_id'] ?? null) ? (int) $additionalService['staff_profile_id'] : $appointment->staff_profile_id,
                    'booked_by' => $request->user()?->id,
                    'source' => $appointment->source ?: 'admin',
                    'status' => Appointment::STATUS_COMPLETED,
                    'scheduled_start' => $extraStart,
                    'scheduled_end' => $extraEnd,
                    'arrival_time' => $appointment->arrival_time,
                    'service_start_time' => $appointment->service_start_time ?? $extraStart,
                    'customer_name' => $appointment->customer_name,
                    'customer_phone' => $appointment->customer_phone,
                    'customer_email' => $appointment->customer_email,
                    'notes' => $data['service_report'] ?? null,
                    'exclude_loyalty_earn' => (bool) ($data['exclude_loyalty_earn'] ?? false),
                ]);

                AppointmentServiceLog::create([
                    'appointment_id' => $extraAppointment->id,
                    'staff_profile_id' => $extraAppointment->staff_profile_id,
                    'started_by' => $request->user()?->id,
                    'started_at' => $extraAppointment->service_start_time ?? $extraStart,
                    'completed_by' => $request->user()?->id,
                    'completed_at' => now(),
                    'service_notes' => null,
                    'completion_notes' => $data['completion_notes'] ?? null,
                    'materials_used' => $data['materials_used'] ?? null,
                    'intake_notes' => null,
                ]);
            }

            $appointment->refresh();
            $loyaltyService->earnFromCompletedAppointment($appointment, $request->user()?->id);
            $dueServiceManager->syncForAppointment($appointment);

            if ($appointment->customer_package_id && ! $appointment->package_session_applied) {
                $customerPackage = CustomerPackage::query()
                    ->with(['package.salonServices', 'usages'])
                    ->find($appointment->customer_package_id);

                if (! $customerPackage) {
                    throw ValidationException::withMessages([
                        'service' => 'The linked package could not be found for this appointment.',
                    ]);
                }

                $packageService = collect($customerPackage->package?->salonServices ?? [])
                    ->firstWhere('id', $appointment->service_id);

                if (! $packageService) {
                    throw ValidationException::withMessages([
                        'service' => 'This appointment service is not included in the selected package.',
                    ]);
                }

                $includedSessions = (int) ($packageService->pivot?->included_sessions ?? 1);
                $alreadyUsedForService = (int) $customerPackage->usages
                    ->where('salon_service_id', $appointment->service_id)
                    ->count();

                if ($alreadyUsedForService >= $includedSessions) {
                    throw ValidationException::withMessages([
                        'service' => 'No remaining package sessions are available for this service.',
                    ]);
                }

                $packageBalanceService->consume(
                    $customerPackage,
                    1,
                    0,
                    $request->user()?->id,
                    'Applied to appointment #'.$appointment->id,
                    $appointment->id,
                    $appointment->service_id,
                );

                $appointment->update(['package_session_applied' => true]);
                $appointment->refresh();
            }

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
                if ($invoice->status === TaxInvoice::STATUS_FINALIZED && $invoice->balanceDue() <= 0.009) {
                    return;
                }

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

                $paymentService = app(TaxInvoicePaymentService::class);
                if ($method !== InvoicePayment::METHOD_GIFT_CARD) {
                    $paymentService->applyAutoVoucher($invoice, $user);
                    $invoice->refresh();
                }

                $amountToPay = $method === InvoicePayment::METHOD_GIFT_CARD
                    ? (float) $invoice->total
                    : $invoice->balanceDue();

                if ($amountToPay > 0.009) {
                    $paymentService->record($invoice, [
                        'amount' => $amountToPay,
                        'method' => $method,
                        'paid_at' => $paidAt,
                        'reference_note' => 'Finish & pay - appointment #'.$appointment->id,
                        'gift_card_id' => $method === InvoicePayment::METHOD_GIFT_CARD ? (int) $data['checkout_gift_card_id'] : null,
                    ], $user);
                }
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

    public function checkout(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'reception');

        $user = $request->user();
        $canInvoice = $user && ($user->hasPermission('can_manage_finance') || $user->hasPermission('can_collect_payments'));
        if (! $canInvoice) {
            return back()->withErrors([
                'checkout' => 'You do not have permission to create invoices or record payments.',
            ]);
        }

        if ($appointment->status !== Appointment::STATUS_COMPLETED) {
            return back()->withErrors([
                'checkout' => 'Only completed appointments can be checked out.',
            ]);
        }

        $invoice = app(TaxInvoiceDraftFromAppointmentService::class)->create(
            $appointment->fresh(['service', 'customer']),
            $user?->id,
            $user?->name
        );

        return redirect()->route('finance.invoices.show', $invoice)
            ->with('status', 'Checkout opened for appointment #'.$appointment->id.'.');
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
            $payload = [
                ...$payload,
                ...$this->serviceStartTimingPayload($appointment, now()),
            ];
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
        $data = $request->validate([
            'service_id' => ['nullable', 'exists:salon_services,id'],
            'service_ids' => ['nullable', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'exists:salon_services,id'],
            'service_quantities' => ['nullable', 'array'],
            'service_quantities.*' => ['nullable', 'integer', 'min:1', 'max:999'],
            'service_starts' => ['nullable', 'array'],
            'service_starts.*' => ['nullable', 'date'],
            'service_durations' => ['nullable', 'array'],
            'service_durations.*' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'service_extra_minutes' => ['nullable', 'array'],
            'service_extra_minutes.*' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'service_unit_prices' => ['nullable', 'array'],
            'service_unit_prices.*' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'service_discount_amounts' => ['nullable', 'array'],
            'service_discount_amounts.*' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'customer_package_id' => ['nullable', 'exists:customer_packages,id'],
            'package_service_ids' => ['nullable', 'array'],
            'package_service_ids.*' => ['integer', 'exists:salon_services,id'],
            'staff_profile_id' => ['nullable', 'exists:staff_profiles,id'],
            'staff_assignments' => ['nullable', 'array'],
            'staff_assignments.*' => ['nullable', 'exists:staff_profiles,id'],
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
            'customer_id' => ['nullable', 'exists:customers,id'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:30'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
            'cancellation_reason' => ['nullable', 'string'],
        ]);

        $data['customer_phone'] = trim((string) ($data['customer_phone'] ?? ''));

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, int>
     */
    private function resolveServiceQuantitiesFromPayload(array $data, array $serviceIds): array
    {
        $raw = $data['service_quantities'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $map = [];

        foreach ($raw as $key => $quantity) {
            $normalizedKey = $this->normalizeServiceRowMapKey((string) $key, $serviceIds);
            if ($normalizedKey === null) {
                continue;
            }

            $map[$normalizedKey] = max(1, (int) $quantity);
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, Carbon>
     */
    private function resolveServiceStartsFromPayload(array $data, array $serviceIds): array
    {
        $raw = $data['service_starts'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $map = [];

        foreach ($raw as $key => $value) {
            $normalizedKey = $this->normalizeServiceRowMapKey((string) $key, $serviceIds);
            if ($normalizedKey === null || empty($value)) {
                continue;
            }

            $map[$normalizedKey] = Carbon::parse($value);
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, int>
     */
    private function resolveServiceIntegerMapFromPayload(array $data, array $serviceIds, string $key, int $min): array
    {
        $raw = $data[$key] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $map = [];

        foreach ($raw as $key => $value) {
            $normalizedKey = $this->normalizeServiceRowMapKey((string) $key, $serviceIds);
            if ($normalizedKey === null || $value === null || $value === '') {
                continue;
            }

            $map[$normalizedKey] = max($min, (int) $value);
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, float>
     */
    private function resolveServiceMoneyMapFromPayload(array $data, array $serviceIds, string $key): array
    {
        $raw = $data[$key] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $map = [];

        foreach ($raw as $key => $value) {
            $normalizedKey = $this->normalizeServiceRowMapKey((string) $key, $serviceIds);
            if ($normalizedKey === null || $value === null || $value === '') {
                continue;
            }

            $map[$normalizedKey] = round(max(0, (float) $value), 2);
        }

        return $map;
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

        $ids = array_values(array_map('intval', array_filter($raw, fn ($v) => (string) $v !== '')));

        if ($ids === [] && ! empty($data['service_id'])) {
            $ids = [(int) $data['service_id']];
        }

        return $ids;
    }

    private function normalizeServiceRowMapKey(string $key, array $serviceIds): int|string|null
    {
        if (preg_match('/^line_(\d+)$/', $key, $matches)) {
            $index = (int) $matches[1];

            return array_key_exists($index, $serviceIds) ? "line_{$index}" : null;
        }

        $id = (int) $key;

        return in_array($id, $serviceIds, true) ? $id : null;
    }

    private function servicePlanValue(array $map, int $index, int $serviceId, mixed $default = null): mixed
    {
        $lineKey = "line_{$index}";
        if (array_key_exists($lineKey, $map)) {
            return $map[$lineKey];
        }

        if (array_key_exists($index, $map)) {
            return $map[$index];
        }

        if (array_key_exists($serviceId, $map)) {
            return $map[$serviceId];
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, int> $serviceIds
     * @return array{customer_package: CustomerPackage|null, covered_service_ids: list<int>}
     */
    private function resolvePackageSelection(array $data, array $serviceIds, ?int $customerId = null, ?int $ignoreAppointmentId = null): array
    {
        $customerPackageId = isset($data['customer_package_id']) && $data['customer_package_id'] !== ''
            ? (int) $data['customer_package_id']
            : null;

        if (! $customerPackageId) {
            return ['customer_package' => null, 'covered_service_ids' => []];
        }

        $customerPackage = CustomerPackage::query()
            ->with(['package.salonServices', 'usages'])
            ->findOrFail($customerPackageId);

        if ($customerId && (int) $customerPackage->customer_id !== (int) $customerId) {
            throw ValidationException::withMessages([
                'customer_package_id' => 'Selected package does not belong to this customer.',
            ]);
        }

        if ($customerPackage->status !== 'active') {
            throw ValidationException::withMessages([
                'customer_package_id' => 'Selected package is not active.',
            ]);
        }

        if ($customerPackage->expires_at && $customerPackage->expires_at->isPast()) {
            throw ValidationException::withMessages([
                'customer_package_id' => 'Selected package is expired.',
            ]);
        }

        $requestedCovered = collect($data['package_service_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($requestedCovered === []) {
            return ['customer_package' => $customerPackage, 'covered_service_ids' => []];
        }

        $allowedByPackage = collect($customerPackage->package?->salonServices ?? [])
            ->mapWithKeys(fn ($service) => [(int) $service->id => (int) ($service->pivot?->included_sessions ?? 1)]);

        $reservedCounts = Appointment::query()
            ->where('customer_package_id', $customerPackage->id)
            ->when($ignoreAppointmentId, fn ($query) => $query->where('id', '!=', $ignoreAppointmentId))
            ->whereNotIn('status', [Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW])
            ->selectRaw('service_id, count(*) as used_count')
            ->groupBy('service_id')
            ->pluck('used_count', 'service_id');

        $coveredServiceIds = [];
        foreach ($requestedCovered as $serviceId) {
            if (! in_array($serviceId, $serviceIds, true)) {
                continue;
            }

            $included = (int) ($allowedByPackage[$serviceId] ?? 0);
            if ($included < 1) {
                throw ValidationException::withMessages([
                    'package_service_ids' => 'One or more selected services are not included in the chosen package.',
                ]);
            }

            $used = (int) ($reservedCounts[$serviceId] ?? 0);
            if ($used >= $included) {
                throw ValidationException::withMessages([
                    'package_service_ids' => 'The selected package has no remaining sessions for one or more selected services.',
                ]);
            }

            $coveredServiceIds[] = $serviceId;
            $reservedCounts[$serviceId] = $used + 1;
        }

        return [
            'customer_package' => $customerPackage,
            'covered_service_ids' => array_values(array_unique($coveredServiceIds)),
        ];
    }

    /**
     * @param array<int, int> $serviceIds
     * @param array<int, int> $serviceQuantities
     * @return array<int, array{service: SalonService, service_quantity: int, service_unit_price: float|null, service_discount_amount: float, service_duration_minutes: int, service_extra_minutes: int, start: Carbon, end: Carbon}>
     */
    private function buildServicePlans(array $serviceIds, Carbon $start, ?string $requestedEnd, array $serviceQuantities = [], array $staffAssignments = [], array $serviceStarts = [], array $serviceDurations = [], array $serviceExtraMinutes = [], array $serviceUnitPrices = [], array $serviceDiscountAmounts = []): array
    {
        $services = SalonService::query()->whereIn('id', $serviceIds)->get()->keyBy('id');
        $plans = [];
        $cursor = $start->copy();
        $assignedStaffIds = array_values(array_unique(array_filter(array_map(
            fn ($serviceId, $index) => $this->servicePlanValue($staffAssignments, (int) $index, (int) $serviceId),
            $serviceIds,
            array_keys($serviceIds)
        ))));
        $runInParallel = count($serviceIds) > 1 && count($assignedStaffIds) > 1;

        foreach ($serviceIds as $idx => $serviceId) {
            /** @var SalonService|null $service */
            $service = $services->get($serviceId);
            if (! $service) {
                continue;
            }

            $serviceStart = $this->servicePlanValue($serviceStarts, $idx, (int) $serviceId);
            $itemStart = $serviceStart instanceof Carbon
                ? $serviceStart->copy()
                : ($runInParallel ? $start->copy() : $cursor->copy());
            $durationMinutes = max(1, (int) $this->servicePlanValue($serviceDurations, $idx, (int) $serviceId, $service->duration_minutes));
            $extraMinutes = max(0, (int) $this->servicePlanValue($serviceExtraMinutes, $idx, (int) $serviceId, 0));
            $itemEnd = $itemStart->copy()->addMinutes($durationMinutes + $extraMinutes + (int) $service->buffer_minutes);
            $unitPriceValue = $this->servicePlanValue($serviceUnitPrices, $idx, (int) $serviceId);
            $unitPrice = $unitPriceValue !== null ? (float) $unitPriceValue : null;
            $quantity = max(1, (int) $this->servicePlanValue($serviceQuantities, $idx, (int) $serviceId, 1));
            $maxDiscount = max(0, (($unitPrice ?? (float) $service->price) * $quantity));
            $discountAmount = min($maxDiscount, max(0, (float) $this->servicePlanValue($serviceDiscountAmounts, $idx, (int) $serviceId, 0)));

            $plans[] = [
                'line_key' => "line_{$idx}",
                'service' => $service,
                'service_quantity' => $quantity,
                'service_unit_price' => $unitPrice,
                'service_discount_amount' => round($discountAmount, 2),
                'service_duration_minutes' => $durationMinutes,
                'service_extra_minutes' => $extraMinutes,
                'start' => $itemStart,
                'end' => $itemEnd,
            ];

            if (! $runInParallel) {
                $cursor = $itemEnd->copy();
            }
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
     * @param array<int, int> $perServiceStaffMap
     */
    private function fillMissingSchedulesForPlans(array $servicePlans, ?int $selectedStaffId, array $perServiceStaffMap, StaffScheduleGeneratorService $staffScheduleGenerator): void
    {
        $staffIds = array_values(array_unique(array_filter([
            $selectedStaffId,
            ...array_values($perServiceStaffMap),
        ], fn ($id) => $id !== null && $id !== '' && (int) $id > 0)));

        $targetStaffIds = $staffIds !== []
            ? array_map('intval', $staffIds)
            : null;

        foreach ($servicePlans as $plan) {
            $staffScheduleGenerator->fillGapsForActiveStaff(
                $plan['start'],
                $plan['start'],
                $targetStaffIds,
            );
        }
    }

    /**
     * @param array<int, array{service: SalonService, start: Carbon, end: Carbon}> $servicePlans
     * @param array<int, int> $perServiceStaffMap
     * @return array<int, array{service: SalonService, start: Carbon, end: Carbon, staff_profile_id: int|null}>
     */
    private function attachStaffAssignments(array $servicePlans, ?int $selectedStaffId, BookingAvailabilityService $availabilityService, ?int $firstPlanIgnoreAppointmentId = null, array $perServiceStaffMap = []): array
    {
        $plansWithStaff = [];
        $allServicesAssignedIndividually = $servicePlans !== []
            && collect($servicePlans)->every(fn (array $plan, int $index): bool => $this->servicePlanValue($perServiceStaffMap, $index, (int) $plan['service']->id) !== null);

        foreach ($servicePlans as $index => $plan) {
            $ignoreAppointmentId = $index === 0 ? $firstPlanIgnoreAppointmentId : null;
            $serviceSpecificStaffId = $this->servicePlanValue($perServiceStaffMap, $index, (int) $plan['service']->id);
            $fallbackSelectedStaffId = $allServicesAssignedIndividually ? null : $selectedStaffId;

            if ($serviceSpecificStaffId !== null || $fallbackSelectedStaffId !== null) {
                $resolvedStaffId = $serviceSpecificStaffId ?? $fallbackSelectedStaffId;
                $availabilityError = $availabilityService->validateStaffAvailability((int) $resolvedStaffId, $plan['start'], $plan['end'], $ignoreAppointmentId);
                if ($availabilityError) {
                    throw ValidationException::withMessages(['staff_profile_id' => $availabilityError]);
                }

                $plan['staff_profile_id'] = (int) $resolvedStaffId;
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

    /**
     * @param array<string, mixed> $data
     * @param array<int, int> $serviceIds
     * @return array<int, int>
     */
    private function resolveStaffAssignmentsFromPayload(array $data, array $serviceIds): array
    {
        $raw = $data['staff_assignments'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $map = [];

        foreach ($raw as $key => $staffProfileId) {
            $normalizedKey = $this->normalizeServiceRowMapKey((string) $key, $serviceIds);
            if ($normalizedKey === null) {
                continue;
            }

            if ($staffProfileId === null || $staffProfileId === '') {
                continue;
            }

            $map[$normalizedKey] = (int) $staffProfileId;
        }

        return $map;
    }

    private function normalizeStaffSkill(string $value): string
    {
        $normalized = strtolower(trim($value));

        return preg_replace('/[^a-z0-9]+/', '', $normalized) ?? '';
    }

    private function appointmentHasLockedServiceWork(Appointment $appointment): bool
    {
        if (in_array($appointment->status, [Appointment::STATUS_IN_PROGRESS, Appointment::STATUS_COMPLETED], true)) {
            return true;
        }

        return $appointment->serviceExecution()->exists()
            || $appointment->taxInvoices()->exists()
            || $appointment->photos()->exists()
            || $appointment->productUsages()->exists();
    }

    private function deleteEditableAppointment(Appointment $appointment): void
    {
        $paths = $appointment->photos()->pluck('path')->filter()->values()->all();
        foreach ($paths as $path) {
            if (is_string($path) && $path !== '' && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }
        }

        $appointment->delete();
    }

    private function resolveCustomer(string $name, ?string $phone, ?string $email, ?int $customerId = null): ?Customer
    {
        if ($customerId) {
            $customer = Customer::findOrFail($customerId);
            $updates = array_filter([
                'name' => $name !== $customer->name ? $name : null,
                'phone' => $phone !== null && trim((string) $phone) !== '' && trim((string) $phone) !== $customer->phone ? trim((string) $phone) : null,
                'email' => $email && $email !== $customer->email ? $email : null,
            ], fn ($value) => $value !== null);

            if ($updates !== []) {
                $customer->update($updates);
            }

            return $customer->fresh();
        }

        $phone = trim((string) $phone);

        if ($phone === '') {
            return null;
        }

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

    /**
     * @return array<string, mixed>
     */
    private function serializeCustomerForAppointments(Customer $customer): array
    {
        $activePackages = $customer->packages
            ->where('status', 'active')
            ->filter(fn (CustomerPackage $package) => ! $package->expires_at || $package->expires_at->isFuture())
            ->map(function (CustomerPackage $customerPackage) {
                $services = collect($customerPackage->package?->salonServices ?? [])
                    ->map(function ($service) use ($customerPackage) {
                        $included = (int) ($service->pivot?->included_sessions ?? 1);
                        $used = (int) $customerPackage->usages->where('salon_service_id', $service->id)->count();

                        return [
                            'id' => $service->id,
                            'name' => $service->name,
                            'included_sessions' => $included,
                            'used_sessions' => $used,
                            'remaining_sessions' => max(0, $included - $used),
                        ];
                    })
                    ->values();

                return [
                    'id' => $customerPackage->id,
                    'package_name' => $customerPackage->package?->name,
                    'remaining_sessions' => $customerPackage->remaining_sessions,
                    'remaining_value' => $customerPackage->remaining_value,
                    'expires_at' => $customerPackage->expires_at,
                    'services' => $services,
                ];
            })
            ->values();

        $activeGiftCards = $customer->giftCards
            ->where('status', 'active')
            ->filter(fn (GiftCard $card) => (float) $card->remaining_value > 0)
            ->values();

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => (string) ($customer->phone ?? ''),
            'email' => (string) ($customer->email ?? ''),
            'active_packages' => $activePackages,
            'gift_card_balance' => round((float) $activeGiftCards->sum('remaining_value'), 2),
            'active_gift_cards' => $activeGiftCards->map(fn (GiftCard $card) => [
                'id' => $card->id,
                'code' => $card->code,
                'remaining_value' => (float) $card->remaining_value,
            ])->all(),
        ];
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
            'customer_package_id' => $appointment->customer_package_id,
            'visit_id' => $appointment->visit_id,
            'package_session_applied' => (bool) $appointment->package_session_applied,
            'service_id' => $appointment->service_id,
            'service_quantity' => (int) ($appointment->service_quantity ?? 1),
            'service_unit_price' => $appointment->service_unit_price !== null ? (float) $appointment->service_unit_price : null,
            'service_discount_amount' => (float) ($appointment->service_discount_amount ?? 0),
            'service_duration_minutes' => $appointment->service_duration_minutes !== null ? (int) $appointment->service_duration_minutes : null,
            'service_extra_minutes' => (int) ($appointment->service_extra_minutes ?? 0),
            'staff_profile_id' => $appointment->staff_profile_id,
            'scheduled_start' => $appointment->scheduled_start,
            'scheduled_end' => $appointment->scheduled_end,
            'customer_name' => $appointment->customer_name,
            'customer_phone' => $appointment->customer_phone,
            'customer_email' => $appointment->customer_email,
            'notes' => $appointment->notes,
            'service_name' => $appointment->service?->name,
            'package_name' => $appointment->customerPackage?->package?->name,
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

    /**
     * When a future appointment is started early, treat the actual service start
     * as the visit time so the queue, reports, and checkout use the same date.
     *
     * @return array<string, Carbon>
     */
    private function serviceStartTimingPayload(Appointment $appointment, Carbon $startedAt): array
    {
        $payload = [
            'arrival_time' => $appointment->arrival_time ?? $startedAt,
            'service_start_time' => $startedAt,
        ];

        if ($appointment->scheduled_start?->greaterThan($startedAt)) {
            $payload['scheduled_start'] = $startedAt;

            if ($appointment->scheduled_end?->greaterThan($appointment->scheduled_start)) {
                $payload['scheduled_end'] = $startedAt
                    ->copy()
                    ->addSeconds($appointment->scheduled_start->diffInSeconds($appointment->scheduled_end));
            }
        }

        return $payload;
    }
}
