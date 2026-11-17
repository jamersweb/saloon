<?php

namespace App\Services;

use App\Models\PublicReservation;
use App\Models\StaffProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class PublicReservationPayments
{
    public function reconcile(PublicReservation $booking): void
    {
        if ($booking->status !== 'awaiting_payment' || ! $booking->checkout_id) {
            return;
        }
        $result = app(NomodBookingCheckout::class)->retrieve($booking);
        DB::transaction(function () use ($booking, $result) {
            StaffProfile::whereKey($booking->staff_profile_id)->lockForUpdate()->firstOrFail();
            $locked = PublicReservation::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'awaiting_payment') {
                return;
            }
            if (($result['id'] ?? '') !== $locked->checkout_id
                || ($result['reference_id'] ?? '') !== 'public-booking-'.$locked->id
                || ($result['currency'] ?? '') !== 'AED'
                || ! isset($result['amount'])
                || (int) round((float) $result['amount'] * 100) !== $locked->deposit_minor) {
                throw new \RuntimeException('Payment details did not match the reservation.');
            }
            if (($result['status'] ?? '') === 'paid') {
                // A portal appointment may have been entered while checkout was open.
                $available = app(PublicReservationAvailability::class)->available($locked->staff_profile_id, $locked->starts_at, $locked->duration_minutes, $locked->id, false);
                $locked->update(['status' => $available ? 'confirmed' : 'payment_review', 'paid_at' => now()]);
            } elseif (in_array($result['status'] ?? '', ['cancelled', 'expired'], true)) {
                $locked->update(['status' => 'cancelled']);
            }
        }, 3);
        $this->notify($booking->fresh());
    }

    public function notify(PublicReservation $booking): void
    {
        DB::transaction(function () use ($booking) {
            $booking = PublicReservation::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            if (! in_array($booking->status, ['confirmed', 'payment_review'], true) || $booking->notified_at) {
                return;
            }
            // Lock prevents scheduler/return-page duplicate sends. Delivery failures remain retryable.
            Mail::send('mail.public-reservation', ['booking' => $booking], function ($mail) use ($booking) {
                $mail->to($booking->customer_email)->subject($booking->status === 'confirmed' ? 'Your VINA appointment is confirmed' : 'Your VINA payment was received — appointment review');
            });
            $booking->update(['notified_at' => now()]);
        });
    }
}
