<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CommunicationLog;
use App\Models\CustomerDueService;
use App\Models\FinanceSetting;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request): Response
    {
        $verifyToken = (string) (FinanceSetting::current()->whatsapp_webhook_verify_token ?: config('services.whatsapp.webhook_verify_token', ''));

        if (
            $request->query('hub_mode') !== 'subscribe'
            && $request->query('hub.mode') !== 'subscribe'
        ) {
            return response('Invalid mode.', 400);
        }

        $incomingToken = (string) ($request->query('hub_verify_token') ?: $request->query('hub.verify_token'));

        if ($verifyToken === '' || ! hash_equals($verifyToken, $incomingToken)) {
            return response('Forbidden', 403);
        }

        return response((string) ($request->query('hub_challenge') ?: $request->query('hub.challenge')), 200);
    }

    public function receive(Request $request): JsonResponse
    {
        if ($request->input('type') === 'whatsapp.inbound_message.received') {
            $inbound = $request->input('whatsappInboundMessage', []);
            $this->recordInbound((string) ($inbound['to'] ?? ''), (string) ($inbound['from'] ?? ''), $inbound['sendTime'] ?? null);
        }
        if ($request->input('type') === 'whatsapp.message.updated' && is_array($request->input('whatsappMessage'))) {
            $this->applyYCloudStatusPayload($request->input('whatsappMessage'), $request->all());
        }

        foreach ($request->input('entry', []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                foreach (($change['value']['messages'] ?? []) as $inbound) {
                    $this->recordInbound((string) data_get($change, 'value.metadata.phone_number_id', ''), (string) ($inbound['from'] ?? ''), isset($inbound['timestamp']) ? Carbon::createFromTimestamp((int) $inbound['timestamp']) : null);
                }
                foreach (($change['value']['statuses'] ?? []) as $statusPayload) {
                    $this->applyStatusPayload($statusPayload);
                }
            }
        }

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $statusPayload
     */
    private function applyStatusPayload(array $statusPayload): void
    {
        $messageId = (string) ($statusPayload['id'] ?? '');
        if ($messageId === '') {
            return;
        }

        $log = CommunicationLog::query()->where('provider_message_id', $messageId)->latest('id')->first();

        if (! $log) {
            return;
        }

        $status = (string) ($statusPayload['status'] ?? '');
        $eventAt = isset($statusPayload['timestamp'])
            ? Carbon::createFromTimestamp((int) $statusPayload['timestamp'])
            : now();

        $errorMessage = collect($statusPayload['errors'] ?? [])
            ->map(function ($error): string {
                if (! is_array($error)) {
                    return '';
                }

                return collect([
                    $error['code'] ?? null,
                    $error['title'] ?? null,
                    $error['message'] ?? null,
                    Arr::get($error, 'error_data.details'),
                ])
                    ->filter(fn ($part) => filled($part))
                    ->map(fn ($part) => (string) $part)
                    ->implode(' ');
            })
            ->filter()
            ->implode('; ');

        $payload = is_array($log->provider_payload) ? $log->provider_payload : [];
        $payload['webhook'] = $statusPayload;

        $updates = [
            'provider_status' => $status !== '' ? $status : $log->provider_status,
            'provider_payload' => $payload,
            'last_provider_event_at' => $eventAt,
        ];

        if ($status === 'sent') {
            $updates['sent_at'] = $eventAt;
            $updates['status'] = 'sent';
        } elseif ($status === 'delivered') {
            $updates['delivered_at'] = $eventAt;
            $updates['status'] = 'sent';
        } elseif ($status === 'read') {
            $updates['read_at'] = $eventAt;
            $updates['status'] = 'sent';
        } elseif ($status === 'failed') {
            $updates['failed_at'] = $eventAt;
            $updates['status'] = 'failed';
            $updates['error_message'] = $errorMessage !== '' ? $errorMessage : ($log->error_message ?: 'WhatsApp delivery failed.');
        }

        $this->applyLifecycle($log, $updates, $status, $eventAt);
    }

    /**
     * @param  array<string, mixed>  $statusPayload
     * @param  array<string, mixed>  $webhookPayload
     */
    private function applyYCloudStatusPayload(array $statusPayload, array $webhookPayload): void
    {
        $messageId = (string) ($statusPayload['id'] ?? '');
        if ($messageId === '') {
            return;
        }

        $log = CommunicationLog::query()->where('provider_message_id', $messageId)->latest('id')->first();

        if (! $log) {
            return;
        }

        $status = (string) ($statusPayload['status'] ?? '');
        $eventAt = $this->ycloudEventTime($status, $statusPayload, $webhookPayload);
        $payload = is_array($log->provider_payload) ? $log->provider_payload : [];
        $payload['webhook'] = $webhookPayload;

        $updates = [
            'provider_status' => $status !== '' ? $status : $log->provider_status,
            'provider_payload' => $payload,
            'last_provider_event_at' => $eventAt,
        ];

        if ($status === 'sent') {
            $updates['sent_at'] = $eventAt;
            $updates['status'] = 'sent';
        } elseif ($status === 'delivered') {
            $updates['delivered_at'] = $eventAt;
            $updates['status'] = 'sent';
        } elseif ($status === 'read') {
            $updates['read_at'] = $eventAt;
            $updates['status'] = 'sent';
        } elseif ($status === 'failed') {
            $updates['failed_at'] = $eventAt;
            $updates['status'] = 'failed';
            $updates['error_message'] = $this->ycloudErrorMessage($statusPayload) ?: ($log->error_message ?: 'WhatsApp delivery failed.');
        }

        $this->applyLifecycle($log, $updates, $status, $eventAt);
    }

    /**
     * @param  array<string, mixed>  $statusPayload
     * @param  array<string, mixed>  $webhookPayload
     */
    private function ycloudEventTime(string $status, array $statusPayload, array $webhookPayload): Carbon
    {
        $field = match ($status) {
            'sent' => 'sendTime',
            'delivered' => 'deliverTime',
            'read' => 'readTime',
            default => null,
        };

        $timestamp = $field ? (string) ($statusPayload[$field] ?? '') : '';
        $timestamp = $timestamp !== '' ? $timestamp : (string) ($webhookPayload['createTime'] ?? $statusPayload['createTime'] ?? '');

        return $timestamp !== '' ? Carbon::parse($timestamp) : now();
    }

    /**
     * @param  array<string, mixed>  $statusPayload
     */
    private function recordInbound(string $sender, string $recipient, mixed $timestamp): void
    {
        $sender = preg_replace('/\D+/', '', $sender);
        $recipient = preg_replace('/\D+/', '', $recipient);
        if ($sender === '' || $recipient === '' || ! $timestamp) {
            return;
        }
        try {
            $at = Carbon::parse($timestamp);
        } catch (\Throwable) {
            return;
        }
        if ($at->isFuture()) {
            return;
        }
        DB::table('whatsapp_reply_windows')->insertOrIgnore([
            'sender' => $sender, 'recipient' => $recipient, 'last_inbound_at' => $at,
        ]);
        DB::table('whatsapp_reply_windows')->where('sender', $sender)->where('recipient', $recipient)
            ->where('last_inbound_at', '<', $at)->update(['last_inbound_at' => $at]);
    }

    private function applyLifecycle(CommunicationLog $log, array $updates, string $status, Carbon $eventAt): void
    {
        if (! in_array($status, ['accepted', 'sent', 'delivered', 'read', 'failed'], true)) {
            return;
        }
        DB::transaction(function () use ($log, $updates, $status, $eventAt) {
            $current = CommunicationLog::query()->whereKey($log->id)->lockForUpdate()->first();
            if (! $current || $current->provider_status === $status) {
                return;
            }
            $rank = ['queued' => 0, 'sending' => 0, 'accepted' => 1, 'sent' => 2, 'failed' => 3, 'delivered' => 4, 'read' => 5];
            if (($rank[$status] ?? 0) < ($rank[$current->provider_status] ?? 0)) {
                return;
            }
            $wasDelivered = $current->delivered_at || $current->read_at;
            $wasSent = $current->sent_at || $wasDelivered;
            $wasFailed = $current->status === 'failed';
            if (in_array($status, ['sent', 'delivered', 'read'], true)) {
                $updates['sent_at'] = $current->sent_at ?? $eventAt;
                $updates['error_message'] = null;
                $updates['failed_at'] = null;
            }
            if ($status === 'read') {
                $updates['delivered_at'] = $current->delivered_at ?? $eventAt;
            }
            $current->forceFill($updates)->save();
            if (preg_match('/^campaign:(\d+)$/', (string) $current->context, $matches)) {
                if (! $wasSent && in_array($status, ['sent', 'delivered', 'read'], true)) {
                    Campaign::query()->whereKey($matches[1])->increment('sent_count');
                }
                if (! $wasFailed && $status === 'failed') {
                    Campaign::query()->whereKey($matches[1])->increment('failed_count');
                } elseif ($wasFailed && in_array($status, ['delivered', 'read'], true)) {
                    Campaign::query()->whereKey($matches[1])->where('failed_count', '>', 0)->decrement('failed_count');
                }
            }
            if (! $wasDelivered && in_array($status, ['delivered', 'read'], true)
                && preg_match('/^due_service_reminder(?:_auto)?:(\d+)$/', (string) $current->context, $matches)) {
                CustomerDueService::query()->whereKey($matches[1])->update(['reminder_sent_at' => $eventAt]);
            }
        });
    }

    private function ycloudErrorMessage(array $statusPayload): ?string
    {
        $parts = [
            $statusPayload['errorCode'] ?? null,
            $statusPayload['errorMessage'] ?? null,
            Arr::get($statusPayload, 'whatsappApiError.message'),
        ];

        $message = collect($parts)
            ->filter(fn ($part) => filled($part))
            ->implode(' ');

        return $message !== '' ? $message : null;
    }
}
