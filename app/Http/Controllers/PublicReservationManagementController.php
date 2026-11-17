<?php

namespace App\Http\Controllers;

use App\Models\PublicReservation;
use App\Models\StaffProfile;
use App\Services\PublicReservationAvailability;
use App\Services\PublicReservationPayments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublicReservationManagementController extends Controller
{
    public function index()
    {
        return response()->view('public.reservation-management', ['bookings' => PublicReservation::orderByDesc('starts_at')->paginate(30)])
            ->header('Cache-Control', 'no-store, private');
    }

    public function decision(Request $request, PublicReservation $booking, PublicReservationAvailability $availability)
    {
        $data = $request->validate(['decision' => 'required|in:approve,decline']);
        DB::transaction(function () use ($booking, $data, $availability) {
            StaffProfile::whereIn('id', array_filter([$booking->staff_profile_id, $booking->requested_staff_id]))->orderBy('id')->lockForUpdate()->get();
            $locked = PublicReservation::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'confirmed' && $locked->requested_start, 409);
            if ($data['decision'] === 'approve') {
                if (! $availability->available($locked->requested_staff_id, $locked->requested_start, $locked->duration_minutes, $locked->id)) {
                    throw ValidationException::withMessages(['start' => 'The requested time is no longer available. The original booking has been retained.']);
                }
                $locked->fill(['staff_profile_id' => $locked->requested_staff_id, 'starts_at' => $locked->requested_start,
                    'ends_at' => $locked->requested_start->copy()->addMinutes($locked->duration_minutes), 'notified_at' => null]);
            }
            $locked->fill(['requested_start' => null, 'requested_staff_id' => null])->save();
        }, 3);
        try {
            app(PublicReservationPayments::class)->notify($booking->fresh());
        } catch (\Throwable $error) {
            report($error);
        }

        return back()->with('status', 'Rescheduling request '.$data['decision'].'d.');
    }
}
