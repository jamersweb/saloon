<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\User;
use Throwable;

class PublicBookingNotificationService
{
    public function __construct(
        private readonly CommunicationDeliveryService $communicationDeliveryService,
    ) {}

    public function notifyTeam(Customer $customer, Appointment $appointment): void
    {
        $recipients = collect(config('services.whatsapp.booking_alert_recipients', []))
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => (string) $value);

        $teamPhones = User::query()
            ->whereHas('role', fn ($query) => $query->whereIn('name', ['owner', 'manager']))
            ->with('staffProfile')
            ->get()
            ->pluck('staffProfile.phone')
            ->filter(fn ($phone) => filled($phone))
            ->map(fn ($phone) => (string) $phone);

        $message = sprintf(
            "New booking request\nCustomer: %s\nPhone: %s\nService ID: %s\nDate: %s\nStatus: %s\nAppointment ID: %s",
            $appointment->customer_name,
            $appointment->customer_phone,
            $appointment->service_id,
            $appointment->scheduled_start?->format('Y-m-d H:i'),
            $appointment->status,
            $appointment->id,
        );

        $recipients
            ->merge($teamPhones)
            ->unique()
            ->values()
            ->each(function (string $recipient) use ($customer, $message): void {
                try {
                    $this->communicationDeliveryService->deliver(
                        $customer,
                        'whatsapp',
                        $recipient,
                        $message,
                        'public_booking_team_alert',
                    );
                } catch (Throwable) {
                    // Keep booking submission successful even if one recipient has an invalid phone.
                }
            });
    }
}
