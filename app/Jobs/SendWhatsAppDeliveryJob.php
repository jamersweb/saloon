<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CommunicationLog;
use App\Models\CustomerDueService;
use App\Services\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class SendWhatsAppDeliveryJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public int $communicationLogId,
        public array $payload,
    ) {
    }

    public function middleware(): array
    {
        return [
            new RateLimited('whatsapp-outbound'),
        ];
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(WhatsAppService $whatsAppService): void
    {
        $log = CommunicationLog::query()->find($this->communicationLogId);

        if (! $log || $log->channel !== 'whatsapp' || $log->status === 'sent') {
            return;
        }

        $log->forceFill([
            'attempt_count' => (int) $log->attempt_count + 1,
            'provider_status' => 'sending',
        ])->save();

        $result = ($this->payload['message_type'] ?? 'text') === 'template'
            ? $whatsAppService->sendTemplate(
                (string) $this->payload['recipient'],
                (string) $this->payload['template_name'],
                (string) ($this->payload['language_code'] ?? 'en_US'),
                is_array($this->payload['components'] ?? null) ? $this->payload['components'] : [],
            )
            : $whatsAppService->sendText(
                (string) $this->payload['recipient'],
                (string) $this->payload['message'],
            );

        if (! $result['successful']) {
            $log->forceFill([
                'provider' => $result['provider'] ?? $log->provider,
                'provider_message_id' => $result['provider_message_id'] ?? $log->provider_message_id,
                'provider_status' => 'retrying',
                'recipient' => $result['recipient'] ?? $log->recipient,
                'message' => $result['message'] ?? $log->message,
                'error_message' => $result['error_message'] ?? 'WhatsApp send failed.',
                'provider_payload' => $this->providerPayloadSnapshot($result),
                'last_provider_event_at' => now(),
            ])->save();

            throw new RuntimeException((string) ($result['error_message'] ?? 'WhatsApp send failed.'));
        }

        $log->forceFill([
            'status' => 'sent',
            'provider' => $result['provider'] ?? $log->provider,
            'provider_status' => 'accepted',
            'provider_message_id' => $result['provider_message_id'] ?? $log->provider_message_id,
            'recipient' => $result['recipient'] ?? $log->recipient,
            'message' => $result['message'] ?? $log->message,
            'error_message' => null,
            'accepted_at' => now(),
            'provider_payload' => $this->providerPayloadSnapshot($result),
            'last_provider_event_at' => now(),
        ])->save();

        $this->applySuccessEffects($log);
    }

    public function failed(?Throwable $exception): void
    {
        $log = CommunicationLog::query()->find($this->communicationLogId);

        if (! $log) {
            return;
        }

        $log->forceFill([
            'status' => 'failed',
            'provider_status' => 'failed',
            'failed_at' => now(),
            'error_message' => $exception?->getMessage() ?: $log->error_message,
            'last_provider_event_at' => now(),
        ])->save();

        $this->applyFailureEffects($log);
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function providerPayloadSnapshot(array $result): array
    {
        return [
            'provider' => $result['provider'] ?? null,
            'provider_message_id' => $result['provider_message_id'] ?? null,
            'recipient' => $result['recipient'] ?? null,
            'message' => $result['message'] ?? null,
            'error_message' => $result['error_message'] ?? null,
        ];
    }

    private function applySuccessEffects(CommunicationLog $log): void
    {
        if (preg_match('/^campaign:(\d+)$/', (string) $log->context, $matches) === 1) {
            Campaign::query()->whereKey((int) $matches[1])->increment('sent_count');
        }

        if (preg_match('/^due_service_reminder(?:_auto)?:([0-9]+)$/', (string) $log->context, $matches) === 1) {
            CustomerDueService::query()->whereKey((int) $matches[1])->update([
                'reminder_sent_at' => now(),
            ]);
        }
    }

    private function applyFailureEffects(CommunicationLog $log): void
    {
        if (preg_match('/^campaign:(\d+)$/', (string) $log->context, $matches) === 1) {
            Campaign::query()->whereKey((int) $matches[1])->increment('failed_count');
        }
    }
}
