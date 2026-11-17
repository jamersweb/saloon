<?php

namespace App\Http\Controllers;

use App\Models\BookingRule;
use App\Models\PublicReservation;
use App\Models\SalonService;
use App\Models\StaffProfile;
use App\Services\NomodBookingCheckout;
use App\Services\PublicReservationAvailability;
use App\Services\PublicReservationPayments;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PublicReservationController extends Controller
{
    public function index()
    {
        return response()->view('public.reservation', [
            'services' => SalonService::where('is_active', true)->orderBy('category')->orderBy('name')
                ->get(['id', 'name', 'category', 'price', 'duration_minutes', 'buffer_minutes']),
            'today' => today()->toDateString(),
            'lastDate' => today()->addDays(BookingRule::current()->max_advance_days)->toDateString(),
        ])->header('Cache-Control', 'no-store');
    }

    private function selectionRules(): array
    {
        return [
            'service_ids' => 'required_without_all:guests,token|array|min:1|max:20',
            'service_ids.*' => 'integer|distinct',
            'guests' => 'sometimes|array|list|min:2|max:15',
            'guests.*' => 'required|array|list|min:1|max:20',
            'guests.*.*' => 'required|integer',
        ];
    }

    private function treatments(array $data, PublicReservationAvailability $availability): array
    {
        if (!isset($data['guests'])) {
            return $availability->services($data['service_ids'] ?? []);
        }

        $result = [];
        foreach ($data['guests'] as $index => $ids) {
            if (count(array_unique($ids)) !== count($ids)) {
                throw ValidationException::withMessages(['guests' => 'Select each treatment once per guest.']);
            }
            foreach ($availability->services($ids) as $service) {
                $service['guest'] = $index === 0 ? 'Booking guest' : 'Guest '.($index + 1);
                $result[] = $service;
            }
        }

        return $result;
    }

    public function availability(Request $request, PublicReservationAvailability $availability)
    {
        $data = $request->validate(['date' => 'required|date_format:Y-m-d', ...$this->selectionRules(),  'token' => 'nullable|string|size:64']);
        $booking = isset($data['token']) ? PublicReservation::where('token', $data['token'])->where('status', 'confirmed')->firstOrFail() : null;
        $minutes = $booking?->duration_minutes ?? $availability->duration($this->treatments($data, $availability));

        return response()->json(['slots' => $availability->slots($data['date'], $minutes, $booking?->id)])
            ->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, PublicReservationAvailability $availability, NomodBookingCheckout $nomod)
    {
        $data = $request->validate([
            ...$this->selectionRules(),
            'token' => 'prohibited',
            'start' => 'required|date_format:Y-m-d\TH:i', 'staff_id' => 'required|integer',
            'customer_name' => 'required|string|max:150', 'customer_phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{7,30}$/'],
            'customer_email' => 'required|email|max:255',
        ]);
        if (! config('public_booking.nomod_key')) {
            return response()->json(['message' => 'Online deposits are not available yet. Please contact the salon to book.'], 503);
        }
        $services = $this->treatments($data, $availability);
        $duration = $availability->duration($services);
        $start = Carbon::createFromFormat('Y-m-d\TH:i', $data['start'])->second(0);
        $booking = DB::transaction(function () use ($data, $services, $duration, $start, $availability) {
            StaffProfile::whereKey($data['staff_id'])->lockForUpdate()->firstOrFail();
            if (! $availability->available($data['staff_id'], $start, $duration)) {
                throw ValidationException::withMessages(['start' => 'This time is no longer available. Please choose another.']);
            }
            $total = array_sum(array_column($services, 'price_minor'));

            return PublicReservation::create([
                'token' => Str::random(64), 'staff_profile_id' => $data['staff_id'], 'services' => $services,
                'total_minor' => $total, 'deposit_minor' => (int) round($total * 15 / 100), 'duration_minutes' => $duration,
                'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes($duration), 'original_starts_at' => $start,
                'customer_name' => $data['customer_name'], 'customer_phone' => $data['customer_phone'], 'customer_email' => $data['customer_email'],
            ]);
        }, 3);
        try {
            $checkout = $nomod->create($booking);
            if (empty($checkout['id']) || empty($checkout['url']) || parse_url($checkout['url'], PHP_URL_SCHEME) !== 'https') {
                throw new \RuntimeException('Invalid checkout response.');
            }
            $booking->update(['checkout_id' => $checkout['id'], 'checkout_url' => $checkout['url']]);
        } catch (\Throwable $error) {
            // No checkout URL has been exposed to the customer, so this local hold is safe to release.
            $booking->update(['status' => 'cancelled']);
            report($error);

            return response()->json(['message' => 'We could not open secure payment. No booking was confirmed. Please try again.'], 502);
        }

        return response()->json(['checkout_url' => $booking->checkout_url, 'booking_url' => route('public.reservation.show', $booking->token)]);
    }

    public function show(string $token, PublicReservationPayments $payments)
    {
        $booking = PublicReservation::where('token', $token)->firstOrFail();
        try {
            $payments->reconcile($booking);
        } catch (\Throwable $error) {
            report($error);
        }

        return response()->view('public.reservation-manage', ['booking' => $booking->fresh()])
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function reschedule(Request $request, string $token, PublicReservationAvailability $availability)
    {
        $data = $request->validate(['start' => 'required|date_format:Y-m-d\TH:i', 'staff_id' => 'required|integer']);
        $booking = PublicReservation::where('token', $token)->where('status', 'confirmed')->firstOrFail();
        $start = Carbon::createFromFormat('Y-m-d\TH:i', $data['start'])->second(0);
        $approval = false;
        DB::transaction(function () use ($booking, $data, $start, $availability, &$approval) {
            StaffProfile::whereIn('id', [$booking->staff_profile_id, $data['staff_id']])->orderBy('id')->lockForUpdate()->get();
            $locked = PublicReservation::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'confirmed' || $locked->starts_at->isPast()) {
                abort(409, 'This appointment cannot be rescheduled online.');
            }
            if (abs($locked->original_starts_at->diffInDays($start, false)) > config('public_booking.reschedule_days')) {
                throw ValidationException::withMessages(['start' => 'Choose a date within 14 days of your original appointment.']);
            }
            if (! $availability->available($data['staff_id'], $start, $locked->duration_minutes, $locked->id)) {
                throw ValidationException::withMessages(['start' => 'This time is no longer available. Please choose another.']);
            }
            $approval = $locked->starts_at->lt(now()->addHours(config('public_booking.approval_cutoff_hours')));
            if ($approval) {
                $locked->update(['requested_start' => $start, 'requested_staff_id' => $data['staff_id']]);
            } else {
                $locked->update(['starts_at' => $start, 'ends_at' => $start->copy()->addMinutes($locked->duration_minutes),
                    'staff_profile_id' => $data['staff_id'], 'requested_start' => null, 'requested_staff_id' => null, 'notified_at' => null]);
            }
        }, 3);
        if (! $approval) {
            try {
                app(PublicReservationPayments::class)->notify($booking->fresh());
            } catch (\Throwable $error) {
                report($error);
            }
        }

        return response()->json(['message' => $approval ? 'Your request is awaiting management approval. Your original appointment remains reserved.' : 'Your appointment has been rescheduled. Your original deposit has been transferred.']);
    }
}
