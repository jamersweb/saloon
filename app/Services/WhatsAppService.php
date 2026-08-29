<?php

namespace App\Services;

use App\Models\FinanceSetting;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WhatsAppService
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    public function sendText(string $recipient, string $message): array
    {
        $driver = $this->resolvedConfig('driver', 'log');

        return match ($driver) {
            'meta' => $this->sendViaMeta($recipient, $message),
            'ycloud' => $this->sendViaYCloud($recipient, $message),
            'log' => $this->sendViaLog($recipient, $message),
            default => throw new InvalidArgumentException("Unsupported WhatsApp driver [{$driver}]."),
        };
    }

    public function sendTemplate(
        string $recipient,
        string $templateName,
        string $languageCode = 'en_US',
        array $components = [],
    ): array {
        $driver = $this->resolvedConfig('driver', 'log');

        return match ($driver) {
            'meta' => $this->sendTemplateViaMeta($recipient, $templateName, $languageCode, $components),
            'ycloud' => $this->sendTemplateViaYCloud($recipient, $templateName, $languageCode, $components),
            'log' => $this->sendTemplateViaLog($recipient, $templateName, $languageCode, $components),
            default => throw new InvalidArgumentException("Unsupported WhatsApp driver [{$driver}]."),
        };
    }

    private function sendViaLog(string $recipient, string $message): array
    {
        return [
            'successful' => true,
            'provider' => 'whatsapp-log',
            'provider_message_id' => 'log-'.Str::uuid()->toString(),
            'recipient' => $this->normalizeRecipient($recipient),
            'message' => $message,
            'error_message' => null,
        ];
    }

    private function sendTemplateViaLog(string $recipient, string $templateName, string $languageCode, array $components): array
    {
        return [
            'successful' => true,
            'provider' => 'whatsapp-log',
            'provider_message_id' => 'log-'.Str::uuid()->toString(),
            'recipient' => $this->normalizeRecipient($recipient),
            'message' => json_encode([
                'type' => 'template',
                'template' => [
                    'name' => $templateName,
                    'language' => ['code' => $languageCode],
                    'components' => array_values($components),
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message' => null,
        ];
    }

    private function sendViaMeta(string $recipient, string $message): array
    {
        $normalizedRecipient = $this->normalizeRecipient($recipient);
        [$endpoint, $token, $configurationError] = $this->metaConfiguration($normalizedRecipient, $message);

        if ($configurationError !== null) {
            return $configurationError;
        }

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

    private function sendTemplateViaMeta(string $recipient, string $templateName, string $languageCode, array $components): array
    {
        $normalizedRecipient = $this->normalizeRecipient($recipient);
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $normalizedRecipient,
            'type' => 'template',
            'template' => array_filter([
                'name' => $templateName,
                'language' => ['code' => $languageCode],
                'components' => $components === [] ? null : array_values($components),
            ], fn ($value) => $value !== null),
        ];

        [$endpoint, $token, $configurationError] = $this->metaConfiguration(
            $normalizedRecipient,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $templateName,
        );

        if ($configurationError !== null) {
            return $configurationError;
        }

        try {
            $response = $this->http
                ->asJson()
                ->withToken($token)
                ->post($endpoint, $payload)
                ->throw();
        } catch (RequestException $exception) {
            $error = Arr::get($exception->response?->json(), 'error.message')
                ?? $exception->getMessage();

            return [
                'successful' => false,
                'provider' => 'whatsapp-meta',
                'provider_message_id' => null,
                'recipient' => $normalizedRecipient,
                'message' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'error_message' => $error,
            ];
        }

        return [
            'successful' => true,
            'provider' => 'whatsapp-meta',
            'provider_message_id' => Arr::get($response->json(), 'messages.0.id'),
            'recipient' => $normalizedRecipient,
            'message' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message' => null,
        ];
    }

    private function sendViaYCloud(string $recipient, string $message): array
    {
        $normalizedRecipient = $this->normalizeRecipientForYCloud($recipient);
        [$endpoint, $apiKey, $sender, $configurationError] = $this->ycloudConfiguration($normalizedRecipient, $message);

        if ($configurationError !== null) {
            return $configurationError;
        }

        $payload = [
            'from' => $sender,
            'to' => $normalizedRecipient,
            'type' => 'text',
            'text' => [
                'body' => $message,
                'preview_url' => false,
            ],
        ];

        try {
            $response = $this->http
                ->asJson()
                ->withHeaders(['X-API-Key' => $apiKey])
                ->post($endpoint, $payload)
                ->throw();
        } catch (RequestException $exception) {
            return [
                'successful' => false,
                'provider' => 'whatsapp-ycloud',
                'provider_message_id' => null,
                'recipient' => $normalizedRecipient,
                'message' => $message,
                'error_message' => $this->providerErrorMessage($exception),
            ];
        }

        return [
            'successful' => true,
            'provider' => 'whatsapp-ycloud',
            'provider_message_id' => Arr::get($response->json(), 'id'),
            'recipient' => $normalizedRecipient,
            'message' => $message,
            'error_message' => null,
        ];
    }

    private function sendTemplateViaYCloud(string $recipient, string $templateName, string $languageCode, array $components): array
    {
        $normalizedRecipient = $this->normalizeRecipientForYCloud($recipient);
        $payload = [
            'from' => null,
            'to' => $normalizedRecipient,
            'type' => 'template',
            'template' => array_filter([
                'name' => $templateName,
                'language' => ['code' => $languageCode],
                'components' => $components === [] ? null : array_values($components),
            ], fn ($value) => $value !== null),
        ];

        [$endpoint, $apiKey, $sender, $configurationError] = $this->ycloudConfiguration(
            $normalizedRecipient,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $templateName,
        );

        if ($configurationError !== null) {
            return $configurationError;
        }

        $payload['from'] = $sender;

        try {
            $response = $this->http
                ->asJson()
                ->withHeaders(['X-API-Key' => $apiKey])
                ->post($endpoint, $payload)
                ->throw();
        } catch (RequestException $exception) {
            return [
                'successful' => false,
                'provider' => 'whatsapp-ycloud',
                'provider_message_id' => null,
                'recipient' => $normalizedRecipient,
                'message' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'error_message' => $this->providerErrorMessage($exception),
            ];
        }

        return [
            'successful' => true,
            'provider' => 'whatsapp-ycloud',
            'provider_message_id' => Arr::get($response->json(), 'id'),
            'recipient' => $normalizedRecipient,
            'message' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message' => null,
        ];
    }

    private function metaConfiguration(string $normalizedRecipient, string $message): array
    {
        $token = $this->resolvedConfig('token');
        $phoneNumberId = $this->resolvedConfig('phone_number_id');
        $version = $this->resolvedConfig('version', 'v23.0');
        $baseUrl = rtrim($this->resolvedConfig('base_url', 'https://graph.facebook.com'), '/');

        if ($token === '' || $phoneNumberId === '') {
            return [
                null,
                null,
                [
                    'successful' => false,
                    'provider' => 'whatsapp-meta',
                    'provider_message_id' => null,
                    'recipient' => $normalizedRecipient,
                    'message' => $message,
                    'error_message' => 'WhatsApp Meta configuration is incomplete.',
                ],
            ];
        }

        return ["{$baseUrl}/{$version}/{$phoneNumberId}/messages", $token, null];
    }

    private function ycloudConfiguration(string $normalizedRecipient, string $message): array
    {
        $settings = FinanceSetting::current();
        $apiKey = trim((string) ($settings->whatsapp_access_token ?: config('services.whatsapp.ycloud_api_key') ?: config('services.whatsapp.token')));
        $rawSender = trim((string) ($settings->whatsapp_phone_number_id ?: config('services.whatsapp.ycloud_sender') ?: config('services.whatsapp.phone_number_id')));
        $configuredBaseUrl = (string) ($settings->whatsapp_base_url ?: config('services.whatsapp.base_url'));
        $baseUrl = str_contains($configuredBaseUrl, 'graph.facebook.com')
            ? (string) config('services.whatsapp.ycloud_base_url', 'https://api.ycloud.com')
            : $configuredBaseUrl;
        $baseUrl = rtrim($baseUrl ?: 'https://api.ycloud.com', '/');

        if ($apiKey === '' || $rawSender === '') {
            return [
                null,
                null,
                null,
                [
                    'successful' => false,
                    'provider' => 'whatsapp-ycloud',
                    'provider_message_id' => null,
                    'recipient' => $normalizedRecipient,
                    'message' => $message,
                    'error_message' => 'WhatsApp YCloud configuration is incomplete.',
                ],
            ];
        }

        try {
            $sender = $this->normalizeRecipientForYCloud($rawSender);
        } catch (InvalidArgumentException) {
            return [
                null,
                null,
                null,
                [
                    'successful' => false,
                    'provider' => 'whatsapp-ycloud',
                    'provider_message_id' => null,
                    'recipient' => $normalizedRecipient,
                    'message' => $message,
                    'error_message' => 'WhatsApp YCloud sender phone number is invalid.',
                ],
            ];
        }

        return ["{$baseUrl}/v2/whatsapp/messages", $apiKey, $sender, null];
    }

    private function normalizeRecipient(string $recipient): string
    {
        $normalized = preg_replace('/\D+/', '', $recipient) ?? '';

        if (preg_match('/^0?5\d{8}$/', $normalized) === 1) {
            $normalized = '971'.ltrim($normalized, '0');
        }

        if (
            strlen($normalized) < 8
            || strlen($normalized) > 15
            || ! preg_match('/^[1-9]\d+$/', $normalized)
            || preg_match('/^(\d)\1+$/', $normalized) === 1
        ) {
            throw new InvalidArgumentException('WhatsApp recipient must contain a valid phone number.');
        }

        return $normalized;
    }

    public function normalizeRecipientForTransport(string $recipient): string
    {
        if ($this->resolvedConfig('driver', 'log') === 'ycloud') {
            return $this->normalizeRecipientForYCloud($recipient);
        }

        return $this->normalizeRecipient($recipient);
    }

    private function normalizeRecipientForYCloud(string $recipient): string
    {
        $normalized = $this->normalizeRecipient($recipient);

        return '+'.$normalized;
    }

    private function providerErrorMessage(RequestException $exception): string
    {
        return Arr::get($exception->response?->json(), 'error.message')
            ?? Arr::get($exception->response?->json(), 'message')
            ?? $exception->getMessage();
    }

    private function resolvedConfig(string $key, ?string $default = null): string
    {
        $settings = FinanceSetting::current();

        $mappedValue = match ($key) {
            'driver' => $settings->whatsapp_driver,
            'base_url' => $settings->whatsapp_base_url,
            'version' => $settings->whatsapp_api_version,
            'phone_number_id' => $settings->whatsapp_phone_number_id,
            'token' => $settings->whatsapp_access_token,
            'webhook_verify_token' => $settings->whatsapp_webhook_verify_token,
            'default_language_code' => $settings->whatsapp_default_language_code,
            'due_service_template_name' => $settings->whatsapp_due_service_template_name,
            'public_booking_template_name' => $settings->whatsapp_public_booking_template_name,
            default => null,
        };

        if ($key === 'driver') {
            $baseUrl = (string) ($settings->whatsapp_base_url ?: config('services.whatsapp.base_url', ''));

            if ($this->isYCloudBaseUrl($baseUrl)) {
                return 'ycloud';
            }
        }

        if (filled($mappedValue)) {
            return (string) $mappedValue;
        }

        return (string) config("services.whatsapp.{$key}", $default ?? '');
    }

    private function isYCloudBaseUrl(string $baseUrl): bool
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);

        return is_string($host) && str_ends_with(strtolower($host), 'ycloud.com');
    }
}
