<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\FinanceSetting;
use App\Models\User;

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

        $settings = FinanceSetting::current();
        $templateName = $settings->whatsapp_public_booking_template_name;
        $languageCode = $settings->whatsapp_default_language_code ?: config('services.whatsapp.default_language_code', 'en_US');

        $recipients
            ->merge($teamPhones)
            ->unique()
            ->values()
            ->each(function (string $recipient) use ($customer, $message, $templateName, $languageCode, $appointment): void {
                $options = [
                    'async' => true,
                    'message_type' => 'text',
                ];

                if (filled($templateName)) {
                    $options = [
                        'async' => true,
                        'message_type' => 'template',
                        'template_name' => $templateName,
                        'language_code' => $languageCode,
                        'components' => [
                            [
                                'type' => 'body',
                                'parameters' => [
                                    ['type' => 'text', 'text' => (string) $appointment->customer_name],
                                    ['type' => 'text', 'text' => (string) $appointment->customer_phone],
                                    ['type' => 'text', 'text' => (string) $appointment->service_id],
                                    ['type' => 'text', 'text' => (string) $appointment->scheduled_start?->format('Y-m-d H:i')],
                                ],
                            ],
                        ],
                    ];
                }

                $this->communicationDeliveryService->deliver(
                    $customer,
                    'whatsapp',
                    $recipient,
                    $message,
                    'public_booking_team_alert',
                    $options,
                );
            });
    }
}
