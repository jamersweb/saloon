<?php

namespace App\Services;

use App\Jobs\SendWhatsAppDeliveryJob;
use App\Models\CommunicationLog;
use App\Models\Customer;
use InvalidArgumentException;

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
        array $options = [],
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
                'provider_status' => 'invalid-recipient',
                'message_type' => $options['message_type'] ?? 'text',
                'error_message' => 'Recipient is missing for the selected channel.',
                'failed_at' => now(),
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
                'provider_status' => 'accepted',
                'message_type' => $options['message_type'] ?? 'text',
                'accepted_at' => now(),
                'sent_at' => now(),
            ]);
        }

        try {
            $normalizedRecipient = $this->whatsAppService->normalizeRecipientForTransport($recipient);
        } catch (InvalidArgumentException $exception) {
            return CommunicationLog::create([
                'customer_id' => $customer->id,
                'channel' => $channel,
                'context' => $context,
                'recipient' => $recipient,
                'message' => $message,
                'status' => 'failed',
                'provider' => $this->providerName($channel),
                'provider_status' => 'invalid-recipient',
                'message_type' => $options['message_type'] ?? 'text',
                'error_message' => $exception->getMessage(),
                'failed_at' => now(),
                'sent_at' => now(),
            ]);
        }

        if (($options['async'] ?? false) === true) {
            $payload = [
                'message_type' => $options['message_type'] ?? 'text',
                'recipient' => $normalizedRecipient,
                'message' => $message,
                'template_name' => $options['template_name'] ?? null,
                'language_code' => $options['language_code'] ?? null,
                'components' => $options['components'] ?? [],
            ];

            $log = CommunicationLog::create([
                'customer_id' => $customer->id,
                'channel' => $channel,
                'context' => $context,
                'recipient' => $normalizedRecipient,
                'message' => $message,
                'status' => 'queued',
                'provider' => $this->providerName($channel),
                'provider_status' => 'queued',
                'message_type' => $payload['message_type'],
                'queued_at' => now(),
                'provider_payload' => $payload,
            ]);

            SendWhatsAppDeliveryJob::dispatch($log->id, $payload);

            return $log;
        }

        $result = ($options['message_type'] ?? 'text') === 'template'
            ? $this->whatsAppService->sendTemplate(
                $normalizedRecipient,
                (string) ($options['template_name'] ?? ''),
                (string) ($options['language_code'] ?? 'en_US'),
                is_array($options['components'] ?? null) ? $options['components'] : [],
            )
            : $this->whatsAppService->sendText($normalizedRecipient, $message);

        return CommunicationLog::create([
            'customer_id' => $customer->id,
            'channel' => $channel,
            'context' => $context,
            'recipient' => $result['recipient'],
            'message' => $result['message'],
            'status' => $result['successful'] ? 'sent' : 'failed',
            'provider' => $result['provider'],
            'provider_status' => $result['successful'] ? 'accepted' : 'failed',
            'message_type' => $options['message_type'] ?? 'text',
            'provider_message_id' => $result['provider_message_id'],
            'error_message' => $result['error_message'],
            'provider_payload' => [
                'provider' => $result['provider'] ?? null,
                'provider_message_id' => $result['provider_message_id'] ?? null,
            ],
            'accepted_at' => $result['successful'] ? now() : null,
            'sent_at' => now(),
            'failed_at' => $result['successful'] ? null : now(),
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
