<?php

namespace App\Services;

use App\Models\CommunicationLog;
use App\Models\Customer;

class CommunicationDeliveryService
{
    public function __construct(
        private readonly WhatsAppService $whatsAppService,
    ) {
    }

    public function deliver(
        Customer $customer,
        string $channel,
        ?string $recipient,
        string $message,
        string $context,
    ): CommunicationLog {
        if (! $recipient) {
            return CommunicationLog::create([
                'customer_id' => $customer->id,
                'channel' => $channel,
                'context' => $context,
                'recipient' => null,
                'message' => $message,
                'status' => 'failed',
                'provider' => $this->providerName($channel),
                'error_message' => 'Recipient is missing for the selected channel.',
                'sent_at' => now(),
            ]);
        }

        if ($channel !== 'whatsapp') {
            return CommunicationLog::create([
                'customer_id' => $customer->id,
                'channel' => $channel,
                'context' => $context,
                'recipient' => $recipient,
                'message' => $message,
                'status' => 'sent',
                'provider' => $this->providerName($channel),
                'sent_at' => now(),
                'delivered_at' => now(),
            ]);
        }

        $result = $this->whatsAppService->sendText($recipient, $message);

        return CommunicationLog::create([
            'customer_id' => $customer->id,
            'channel' => $channel,
            'context' => $context,
            'recipient' => $result['recipient'],
            'message' => $result['message'],
            'status' => $result['successful'] ? 'sent' : 'failed',
            'provider' => $result['provider'],
            'provider_message_id' => $result['provider_message_id'],
            'error_message' => $result['error_message'],
            'sent_at' => now(),
            'delivered_at' => $result['successful'] ? now() : null,
        ]);
    }

    private function providerName(string $channel): string
    {
        return match ($channel) {
            'email' => 'app-email',
            'sms' => 'app-sms',
            default => 'whatsapp',
        };
    }
}
