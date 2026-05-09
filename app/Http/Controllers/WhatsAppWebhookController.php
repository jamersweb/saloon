<?php

namespace App\Http\Controllers;

use App\Models\CommunicationLog;
use App\Models\FinanceSetting;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        foreach ($request->input('entry', []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                foreach (($change['value']['statuses'] ?? []) as $statusPayload) {
                    $this->applyStatusPayload($statusPayload);
                }
            }
        }

        return response()->json(['received' => true]);
    }

    /**
     * @param array<string, mixed> $statusPayload
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
            ->map(fn ($error) => trim(((string) ($error['title'] ?? '')) . ' ' . ((string) ($error['message'] ?? ''))))
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

        $log->forceFill($updates)->save();
    }
}
