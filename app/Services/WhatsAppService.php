<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WhatsAppService
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {
    }

    public function sendText(string $recipient, string $message): array
    {
        $driver = config('services.whatsapp.driver', 'log');

        return match ($driver) {
            'meta' => $this->sendViaMeta($recipient, $message),
            'log' => $this->sendViaLog($recipient, $message),
            default => throw new InvalidArgumentException("Unsupported WhatsApp driver [{$driver}]."),
        };
    }

    private function sendViaLog(string $recipient, string $message): array
    {
        return [
            'successful' => true,
            'provider' => 'whatsapp-log',
            'provider_message_id' => 'log-' . Str::uuid()->toString(),
            'recipient' => $this->normalizeRecipient($recipient),
            'message' => $message,
            'error_message' => null,
        ];
    }

    private function sendViaMeta(string $recipient, string $message): array
    {
        $token = (string) config('services.whatsapp.token');
        $phoneNumberId = (string) config('services.whatsapp.phone_number_id');
        $version = (string) config('services.whatsapp.version', 'v23.0');
        $baseUrl = rtrim((string) config('services.whatsapp.base_url', 'https://graph.facebook.com'), '/');

        if ($token === '' || $phoneNumberId === '') {
            return [
                'successful' => false,
                'provider' => 'whatsapp-meta',
                'provider_message_id' => null,
                'recipient' => $this->normalizeRecipient($recipient),
                'message' => $message,
                'error_message' => 'WhatsApp Meta configuration is incomplete.',
            ];
        }

        $normalizedRecipient = $this->normalizeRecipient($recipient);
        $endpoint = "{$baseUrl}/{$version}/{$phoneNumberId}/messages";

        try {
            $response = $this->http
                ->asJson()
                ->withToken($token)
                ->post($endpoint, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $normalizedRecipient,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $message,
                    ],
                ])
                ->throw();
        } catch (RequestException $exception) {
            $error = Arr::get($exception->response?->json(), 'error.message')
                ?? $exception->getMessage();

            return [
                'successful' => false,
                'provider' => 'whatsapp-meta',
                'provider_message_id' => null,
                'recipient' => $normalizedRecipient,
                'message' => $message,
                'error_message' => $error,
            ];
        }

        return [
            'successful' => true,
            'provider' => 'whatsapp-meta',
            'provider_message_id' => Arr::get($response->json(), 'messages.0.id'),
            'recipient' => $normalizedRecipient,
            'message' => $message,
            'error_message' => null,
        ];
    }

    private function normalizeRecipient(string $recipient): string
    {
        $normalized = preg_replace('/\D+/', '', $recipient) ?? '';

        if (strlen($normalized) < 8) {
            throw new InvalidArgumentException('WhatsApp recipient must contain a valid phone number.');
        }

        return $normalized;
    }
}
