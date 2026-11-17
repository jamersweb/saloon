<?php

namespace App\Services;

use App\Models\PublicReservation;
use Illuminate\Support\Facades\Http;

class NomodBookingCheckout
{
    private function client()
    {
        if (! config('public_booking.nomod_key')) {
            throw new \RuntimeException('Nomod checkout is not configured.');
        }

        return Http::baseUrl(config('public_booking.nomod_url'))->withHeaders(['X-API-KEY' => config('public_booking.nomod_key')])
            ->acceptJson()->asJson()->connectTimeout(5)->timeout(20);
    }

    public function create(PublicReservation $booking): array
    {
        $amount = number_format($booking->deposit_minor / 100, 2, '.', '');
        $return = route('public.reservation.show', $booking->token);

        return $this->client()->post('/checkout', [
            'reference_id' => 'public-booking-'.$booking->id,
            'amount' => $amount, 'currency' => 'AED', 'discount' => '0.00',
            'items' => [['item_id' => (string) $booking->id, 'name' => '15% salon appointment deposit', 'quantity' => 1,
                'unit_amount' => $amount, 'discount_type' => 'flat', 'discount_amount' => '0.00', 'total_amount' => $amount, 'net_amount' => $amount]],
            'customer' => ['first_name' => $booking->customer_name, 'last_name' => '', 'email' => $booking->customer_email, 'phone_number' => $booking->customer_phone],
            'success_url' => $return, 'failure_url' => $return, 'cancelled_url' => $return,
        ])->throw()->json();
    }

    public function retrieve(PublicReservation $booking): array
    {
        return $this->client()->get('/checkout/'.rawurlencode($booking->checkout_id))->throw()->json();
    }
}
