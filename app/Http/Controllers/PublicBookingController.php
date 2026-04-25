<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\BookingRule;
use App\Models\Customer;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Services\BookingAvailabilityService;
use App\Services\PublicBookingNotificationService;
use App\Support\Audit;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Inertia\Inertia;
use Inertia\Response;

class PublicBookingController extends Controller
{
    public function create(): Response
    {
        $rules = BookingRule::current();

        return Inertia::render('Public/Booking', [
            'services' => SalonService::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'duration_minutes', 'price']),
            'staffProfiles' => StaffProfile::query()->with('user:id,name')->where('is_active', true)->orderBy('employee_code')->get()->map(fn (StaffProfile $staff) => [
                'id' => $staff->id,
                'name' => $staff->user?->name,
            ]),
            'bookingRules' => $rules,
            'defaultStart' => $rules->nextDefaultAppointmentStart(),
        ]);
    }

    public function embedCreate(): View
    {
        $rules = BookingRule::current();

        return view('public.embed-booking', [
            'services' => SalonService::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'duration_minutes', 'price']),
            'staffProfiles' => StaffProfile::query()->with('user:id,name')->where('is_active', true)->orderBy('employee_code')->get(),
            'bookingRules' => $rules,
            'defaultStart' => $rules->nextDefaultAppointmentStart(),
        ]);
    }

    public function embedThanks(): View
    {
        return view('public.embed-booking-thanks');
    }

    public function store(Request $request, BookingAvailabilityService $availabilityService, PublicBookingNotificationService $notificationService): RedirectResponse
    {
        $result = $this->createPublicAppointment($request, $availabilityService, $notificationService);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return back()->with('status', 'Booking submitted successfully. We will confirm shortly.');
    }

    public function embedStore(Request $request, BookingAvailabilityService $availabilityService, PublicBookingNotificationService $notificationService): RedirectResponse
    {
        $result = $this->createPublicAppointment($request, $availabilityService, $notificationService);

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        return redirect()->route('embed.booking.thanks');
    }

    private function createPublicAppointment(
        Request $request,
        BookingAvailabilityService $availabilityService,
        PublicBookingNotificationService $notificationService,
    ): Appointment|RedirectResponse {
        $data = $request->validate([
            'service_id' => ['nullable', 'exists:salon_services,id'],
            'service_ids' => ['nullable', 'array', 'min:1'],
            'service_ids.*' => ['integer', 'exists:salon_services,id'],
            'staff_profile_id' => ['nullable', 'exists:staff_profiles,id'],
            'scheduled_start' => ['required', 'date'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $serviceIds = $this->resolveServiceIdsFromPayload($data);
        if ($serviceIds === []) {
            return back()->withErrors(['service_ids' => 'Please select at least one service.'])->withInput();
        }

        $start = Carbon::parse($data['scheduled_start']);
        $services = SalonService::query()
            ->whereIn('id', $serviceIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        if ($services->count() !== count($serviceIds)) {
            return back()->withErrors(['service_ids' => 'One or more selected services are unavailable.'])->withInput();
        }

        $plans = $this->buildServicePlans($start, $serviceIds, $services);
        $lastPlan = end($plans);
        $end = $lastPlan['end'];
        $rules = BookingRule::current();

        if ($windowError = $availabilityService->validateAdvanceWindow($start)) {
            return back()->withErrors(['scheduled_start' => $windowError])->withInput();
        }

        foreach ($plans as $plan) {
            if ($salonError = $availabilityService->validateSalonHours($plan['start'], $plan['end'])) {
                return back()->withErrors(['scheduled_start' => $salonError])->withInput();
            }
        }

        $resolvedStaffId = ! empty($data['staff_profile_id']) ? (int) $data['staff_profile_id'] : null;

        if ($resolvedStaffId) {
            foreach ($plans as $plan) {
                $staffAvailabilityError = $availabilityService->validateStaffAvailability($resolvedStaffId, $plan['start'], $plan['end']);
                if ($staffAvailabilityError) {
                    return back()->withErrors(['staff_profile_id' => $staffAvailabilityError])->withInput();
                }
            }
        } else {
            $resolvedStaffId = $this->findAnyFullyAvailableStaffId($plans, $availabilityService);
            if (! $resolvedStaffId) {
                return back()->withErrors(['scheduled_start' => 'No staff available for the selected slot.'])->withInput();
            }
        }

        $customer = Customer::firstOrCreate(
            ['phone' => $data['customer_phone']],
            [
                'name' => $data['customer_name'],
                'email' => $data['customer_email'] ?? null,
                'customer_code' => 'CUST-'.now()->format('Ymd').'-'.random_int(1000, 9999),
            ],
        );

        $updates = array_filter([
            'name' => $data['customer_name'] !== $customer->name ? $data['customer_name'] : null,
            'email' => ! empty($data['customer_email']) && $data['customer_email'] !== $customer->email ? $data['customer_email'] : null,
        ], fn ($value) => $value !== null);

        if ($updates !== []) {
            $customer->update($updates);
        }

        $firstAppointment = null;
        foreach ($plans as $plan) {
            $appointment = Appointment::create([
                'customer_id' => $customer->id,
                'service_id' => $plan['service']->id,
                'staff_profile_id' => $resolvedStaffId,
                'source' => 'public',
                'status' => $rules->public_requires_approval ? Appointment::STATUS_PENDING : Appointment::STATUS_CONFIRMED,
                'scheduled_start' => $plan['start'],
                'scheduled_end' => $plan['end'],
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'customer_email' => $data['customer_email'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $firstAppointment ??= $appointment;
        }

        $notificationService->notifyTeam($customer, $firstAppointment);

        Audit::log(null, 'appointment.public_created', 'Appointment', $firstAppointment->id);

        return $firstAppointment;
    }

    private function resolveServiceIdsFromPayload(array $data): array
    {
        $raw = $data['service_ids'] ?? [];
        if (! is_array($raw)) {
            $raw = [];
        }

        $ids = array_values(array_unique(array_map('intval', array_filter($raw, fn ($id) => $id !== null && $id !== ''))));

        if ($ids === [] && ! empty($data['service_id'])) {
            $ids = [(int) $data['service_id']];
        }

        return $ids;
    }

    private function buildServicePlans(CarbonInterface $start, array $serviceIds, Collection $servicesById): array
    {
        $plans = [];
        $cursor = Carbon::parse($start);

        foreach ($serviceIds as $serviceId) {
            /** @var SalonService $service */
            $service = $servicesById->get((int) $serviceId);
            $segmentStart = $cursor->copy();
            $segmentEnd = $segmentStart->copy()->addMinutes((int) $service->duration_minutes + (int) $service->buffer_minutes);

            $plans[] = [
                'service' => $service,
                'start' => $segmentStart,
                'end' => $segmentEnd,
            ];

            $cursor = $segmentEnd->copy();
        }

        return $plans;
    }

    private function findAnyFullyAvailableStaffId(array $plans, BookingAvailabilityService $availabilityService): ?int
    {
        $staffIds = StaffProfile::query()->where('is_active', true)->pluck('id')->all();

        foreach ($staffIds as $staffId) {
            $allAvailable = true;
            foreach ($plans as $plan) {
                if ($availabilityService->validateStaffAvailability((int) $staffId, $plan['start'], $plan['end'])) {
                    $allAvailable = false;
                    break;
                }
            }
            if ($allAvailable) {
                return (int) $staffId;
            }
        }

        return null;
    }
}
