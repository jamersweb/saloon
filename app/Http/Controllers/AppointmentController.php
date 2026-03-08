<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\BookingRule;
use App\Models\Customer;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Services\BookingAvailabilityService;
use App\Services\LoyaltyService;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AppointmentController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();

        $appointments = Appointment::query()
            ->with(['service', 'staffProfile.user'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderBy('scheduled_start')
            ->limit(200)
            ->get()
            ->map(fn (Appointment $appointment) => [
                'id' => $appointment->id,
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
            ]);

        return Inertia::render('Appointments/Index', [
            'appointments' => $appointments,
            'services' => SalonService::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'duration_minutes']),
            'staffProfiles' => StaffProfile::query()->with('user:id,name')->where('is_active', true)->orderBy('employee_code')->get()->map(fn (StaffProfile $staff) => [
                'id' => $staff->id,
                'name' => $staff->user?->name,
            ]),
            'statusFilter' => $status,
            'bookingRules' => BookingRule::current(),
        ]);
    }

    public function store(Request $request, BookingAvailabilityService $availabilityService): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        $data = $this->validatePayload($request);

        $service = SalonService::findOrFail($data['service_id']);
        $start = Carbon::parse($data['scheduled_start']);
        $end = Carbon::parse($data['scheduled_end'] ?? $start->copy()->addMinutes($service->duration_minutes + $service->buffer_minutes));

        if ($end->lessThanOrEqualTo($start)) {
            return back()->withErrors(['scheduled_end' => 'End time must be after start time.'])->withInput();
        }

        if ($windowError = $availabilityService->validateAdvanceWindow($start)) {
            return back()->withErrors(['scheduled_start' => $windowError])->withInput();
        }

        if (! empty($data['staff_profile_id'])) {
            $staffAvailabilityError = $availabilityService->validateStaffAvailability((int) $data['staff_profile_id'], $start, $end);
            if ($staffAvailabilityError) {
                return back()->withErrors(['staff_profile_id' => $staffAvailabilityError])->withInput();
            }
        }

        $customer = Customer::firstOrCreate(
            ['phone' => $data['customer_phone']],
            [
                'name' => $data['customer_name'],
                'email' => $data['customer_email'] ?? null,
                'customer_code' => 'CUST-' . now()->format('Ymd') . '-' . random_int(1000, 9999),
            ],
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

        if (! empty($data['staff_profile_id'])) {
            $staffAvailabilityError = $availabilityService->validateStaffAvailability((int) $data['staff_profile_id'], $start, $end, $appointment->id);
            if ($staffAvailabilityError) {
                return back()->withErrors(['staff_profile_id' => $staffAvailabilityError])->withInput();
            }
        }

        $customer = Customer::firstOrCreate(
            ['phone' => $data['customer_phone']],
            [
                'name' => $data['customer_name'],
                'email' => $data['customer_email'] ?? null,
                'customer_code' => 'CUST-' . now()->format('Ymd') . '-' . random_int(1000, 9999),
            ],
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

    public function transition(Request $request, Appointment $appointment, LoyaltyService $loyaltyService): RedirectResponse
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
        }

        Audit::log($request->user()?->id, 'appointment.status_changed', 'Appointment', $appointment->id, [
            'status' => $nextStatus,
        ]);

        return back()->with('status', 'Appointment status updated.');
    }

    public function destroy(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorizeRoles($request, 'owner', 'manager', 'staff');

        if ($appointment->canTransitionTo(Appointment::STATUS_CANCELLED)) {
            $appointment->update([
                'status' => Appointment::STATUS_CANCELLED,
                'cancellation_reason' => $request->input('cancellation_reason', 'Cancelled by staff'),
            ]);

            Audit::log($request->user()?->id, 'appointment.cancelled', 'Appointment', $appointment->id);
        }

        return back()->with('status', 'Appointment cancelled.');
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

}
