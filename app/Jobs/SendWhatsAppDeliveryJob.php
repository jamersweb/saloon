<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CommunicationLog;
use App\Services\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class SendWhatsAppDeliveryJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $communicationLogId,
        public array $payload,
    ) {}

    public function middleware(): array
    {
        return [
            new RateLimited('whatsapp-outbound'),
            (new WithoutOverlapping('whatsapp-log:'.$this->communicationLogId))->releaseAfter(30)->expireAfter(180),
        ];
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(WhatsAppService $whatsAppService): void
    {
        $log = CommunicationLog::query()->find($this->communicationLogId);

        if (! $log || $log->channel !== 'whatsapp' || in_array($log->status, ['sent', 'failed'], true) || filled($log->provider_message_id)) {
            return;
        }

        $log->forceFill([
            'attempt_count' => (int) $log->attempt_count + 1,
            'provider_status' => 'sending',
        ])->save();

        try {
            if (preg_match('/^(campaign|due_service_reminder(?:_auto)?):/', (string) $log->context) && ($this->payload['message_type'] ?? 'text') !== 'template') {
                throw new InvalidArgumentException('Automated WhatsApp outreach requires an approved template.');
            }
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

        } catch (InvalidArgumentException $exception) {
            $result = ['successful' => false, 'error_message' => $exception->getMessage(), 'http_status' => 400];
        }

        if (! $result['successful']) {
            $errorMessage = (string) ($result['error_message'] ?? 'WhatsApp send failed.');
            $shouldRetry = $this->shouldRetryProviderFailure($result);

            $log->forceFill([
                'status' => $shouldRetry ? $log->status : 'failed',
                'provider' => $result['provider'] ?? $log->provider,
                'provider_message_id' => $result['provider_message_id'] ?? $log->provider_message_id,
                'provider_status' => $shouldRetry ? 'retrying' : 'failed',
                'recipient' => $result['recipient'] ?? $log->recipient,
                'message' => $result['message'] ?? $log->message,
                'error_message' => $errorMessage,
                'provider_payload' => $this->providerPayloadSnapshot($result),
                'failed_at' => $shouldRetry ? null : now(),
                'last_provider_event_at' => now(),
            ])->save();

            if (! $shouldRetry) {
                $this->applyFailureEffects($log);

                return;
            }

            throw new RuntimeException($errorMessage);
        }

        $log->forceFill([
            'status' => 'queued',
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

        // Provider acceptance is not a delivery receipt. Webhooks apply success effects.
    }

    public function failed(?Throwable $exception): void
    {
        $log = CommunicationLog::query()->find($this->communicationLogId);

        if (! $log || $log->status === 'failed' || filled($log->provider_message_id)) {
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
     * @param  array<string, mixed>  $result
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

    private function applyFailureEffects(CommunicationLog $log): void
    {
        if (preg_match('/^campaign:(\d+)$/', (string) $log->context, $matches) === 1) {
            Campaign::query()->whereKey((int) $matches[1])->increment('failed_count');
        }
    }

    private function shouldRetryProviderFailure(array $result): bool
    {
        $code = (string) ($result['error_code'] ?? '');
        $message = (string) ($result['error_message'] ?? '');
        if (preg_match('/\b(131026|131047|131049|132000|132001|132012|132015|132016|133010)\b/', $code.' '.$message)
            || preg_match('/template not found|invalid.*phone|not registered|configuration|unsupported post request/i', $message)) {
            return false;
        }

        return in_array($code, ['2', '130429', '131000', '131056', 'INTERNAL_SERVER_ERROR', 'TOO_MANY_REQUESTS'], true)
            || in_array((int) ($result['http_status'] ?? 0), [429, 500, 502, 503, 504], true);
    }
}
