<?php

namespace App\Console\Commands;

use App\Models\PublicReservation;
use App\Services\PublicReservationPayments;
use Illuminate\Console\Command;

class ReconcilePublicBookingPayments extends Command
{
    protected $signature = 'public-booking:reconcile';

    protected $description = 'Verify Nomod deposits and retry public booking confirmation emails';

    public function handle(PublicReservationPayments $payments): int
    {
        PublicReservation::where(function ($q) {
            $q->where(fn ($q) => $q->where('status', 'awaiting_payment')->whereNotNull('checkout_id'))
                ->orWhere(fn ($q) => $q->whereIn('status', ['confirmed', 'payment_review'])->whereNull('notified_at'));
        })->chunkById(50, function ($bookings) use ($payments) {
            foreach ($bookings as $booking) {
                try {
                    $payments->reconcile($booking);
                    $payments->notify($booking->fresh());
                } catch (\Throwable $error) {
                    report($error);
                    $this->warn('Could not reconcile reservation '.$booking->id.'. It will be retried.');
                }
            }
        });

        return self::SUCCESS;
    }
}
