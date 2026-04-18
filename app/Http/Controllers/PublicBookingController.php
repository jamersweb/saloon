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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PublicBookingController extends Controller
{
    public function create(): Response
    {
        $rules = BookingRule::current();

        return Inertia::render('Public/Booking', [
            'services' => SalonService::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'duration_minutes']),
            'staffProfiles' => StaffProfile::query()->with('user:id,name')->where('is_active', true)->orderBy('employee_code')->get()->map(fn (StaffProfile $staff) => [
                'id' => $staff->id,
                'name' => $staff->user?->name,
            ]),
            'bookingRules' => $rules,
            'defaultStart' => $rules->nextDefaultAppointmentStart(),
        ]);
    }

    public function store(Request $request, BookingAvailabilityService $availabilityService, PublicBookingNotificationService $notificationService): RedirectResponse
    {
        $data = $request->validate([
            'service_id' => ['required', 'exists:salon_services,id'],
            'staff_profile_id' => ['nullable', 'exists:staff_profiles,id'],
            'scheduled_start' => ['required', 'date'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'string', 'max:30'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $service = SalonService::findOrFail($data['service_id']);
        $start = Carbon::parse($data['scheduled_start']);
        $end = $start->copy()->addMinutes($service->duration_minutes + $service->buffer_minutes);
        $rules = BookingRule::current();

        if ($windowError = $availabilityService->validateAdvanceWindow($start)) {
            return back()->withErrors(['scheduled_start' => $windowError])->withInput();
        }

        if ($salonError = $availabilityService->validateSalonHours($start, $end)) {
            return back()->withErrors(['scheduled_start' => $salonError])->withInput();
        }

        $resolvedStaffId = ! empty($data['staff_profile_id']) ? (int) $data['staff_profile_id'] : null;

        if ($resolvedStaffId) {
            $staffAvailabilityError = $availabilityService->validateStaffAvailability($resolvedStaffId, $start, $end);
            if ($staffAvailabilityError) {
                return back()->withErrors(['staff_profile_id' => $staffAvailabilityError])->withInput();
            }
        } else {
            $resolvedStaffId = $availabilityService->findAnyAvailableStaffId($start, $end);
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

        $appointment = Appointment::create([
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'staff_profile_id' => $resolvedStaffId,
            'source' => 'public',
            'status' => $rules->public_requires_approval ? Appointment::STATUS_PENDING : Appointment::STATUS_CONFIRMED,
            'scheduled_start' => $start,
            'scheduled_end' => $end,
            'customer_name' => $data['customer_name'],
            'customer_phone' => $data['customer_phone'],
            'customer_email' => $data['customer_email'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $notificationService->notifyTeam($customer, $appointment);

        Audit::log(null, 'appointment.public_created', 'Appointment', $appointment->id);

        return back()->with('status', 'Booking submitted successfully. We will confirm shortly.');
    }
}
