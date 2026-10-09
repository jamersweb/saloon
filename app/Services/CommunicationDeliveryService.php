<?php

namespace App\Services;

use App\Jobs\SendWhatsAppDeliveryJob;
use App\Models\CommunicationLog;
use App\Models\Customer;
use App\Models\CustomerDueService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CommunicationDeliveryService
{
    public function __construct(
        private readonly WhatsAppService $whatsAppService,
    ) {}

    public function deliver(
        Customer $customer,
        string $channel,
        ?string $recipient,
        string $message,
        string $context,
        array $options = [],
    ): CommunicationLog {
        if ($channel === 'whatsapp' && preg_match('/^due_service_reminder(?:_auto)?:(\d+)$/', $context, $matches)) {
            return DB::transaction(function () use ($customer, $channel, $recipient, $message, $context, $options, $matches) {
                CustomerDueService::query()->whereKey($matches[1])->lockForUpdate()->first();
                $existing = CommunicationLog::query()->where('channel', $channel)
                    ->whereIn('context', ['due_service_reminder:'.$matches[1], 'due_service_reminder_auto:'.$matches[1]])
                    ->whereIn('status', ['queued', 'sent'])->latest('id')->first();

                return $existing ?? $this->deliverOnce($customer, $channel, $recipient, $message, $context, $options);
            });
        }

        return $this->deliverOnce($customer, $channel, $recipient, $message, $context, $options);
    }

    private function deliverOnce(Customer $customer, string $channel, ?string $recipient, string $message, string $context, array $options): CommunicationLog
    {
        if ($channel === 'sms' || $channel === 'email') {
            return CommunicationLog::create([
                'customer_id' => $customer->id, 'channel' => $channel, 'context' => $context,
                'recipient' => $recipient, 'message' => $message, 'status' => 'failed',
                'provider' => $this->providerName($channel), 'provider_status' => 'disabled',
                'error_message' => $channel === 'sms' ? 'SMS is disabled. Use WhatsApp.' : 'Email delivery is not configured.',
                'failed_at' => now(),
            ]);
        }
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
                'sent_at' => null,
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
                'sent_at' => null,
            ]);
        }

        try {
            if (($options['message_type'] ?? 'text') === 'template') {
                app(WhatsAppTemplateValidator::class)->validate((string) ($options['template_name'] ?? ''), (string) ($options['language_code'] ?? 'en_US'), $options['components'] ?? []);
            } else {
                $this->whatsAppService->assertReplyWindow($normalizedRecipient);
            }
        } catch (InvalidArgumentException $exception) {
            return CommunicationLog::create([
                'customer_id' => $customer->id, 'channel' => $channel, 'context' => $context,
                'recipient' => $normalizedRecipient, 'message' => $message, 'status' => 'failed',
                'provider' => 'whatsapp', 'provider_status' => 'validation-failed',
                'message_type' => $options['message_type'] ?? 'text',
                'error_message' => $exception->getMessage(), 'failed_at' => now(),
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

            SendWhatsAppDeliveryJob::dispatch($log->id, $payload)->afterCommit();

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
            'status' => $result['successful'] ? 'queued' : 'failed',
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
            'sent_at' => null,
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
