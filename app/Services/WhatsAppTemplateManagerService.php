<?php

namespace App\Services;

use App\Models\FinanceSetting;
use App\Models\WhatsAppMessageTemplate;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class WhatsAppTemplateManagerService
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function syncTemplates(): array
    {
        return $this->resolvedDriver() === 'ycloud'
            ? $this->syncFromYCloud()
            : $this->syncFromMeta();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function syncFromMeta(): array
    {
        [$endpoint, $token] = $this->managementConfiguration();

        $templates = [];
        $nextUrl = $endpoint.'?limit=100';

        while ($nextUrl) {
            try {
                $response = $this->http
                    ->asJson()
                    ->withToken($token)
                    ->get($nextUrl)
                    ->throw()
                    ->json();
            } catch (RequestException $exception) {
                throw new InvalidArgumentException(
                    Arr::get($exception->response?->json(), 'error.message')
                    ?? $exception->getMessage()
                );
            }

            foreach ($response['data'] ?? [] as $template) {
                if (! is_array($template)) {
                    continue;
                }

                $record = WhatsAppMessageTemplate::query()->updateOrCreate(
                    [
                        'name' => (string) ($template['name'] ?? ''),
                        'language' => (string) ($template['language'] ?? 'en_US'),
                    ],
                    [
                        'template_uid' => (string) ($template['id'] ?? '') ?: null,
                        'category' => $template['category'] ?? null,
                        'status' => $template['status'] ?? null,
                        'sub_category' => $template['sub_category'] ?? null,
                        'quality_score' => Arr::get($template, 'quality_score.score'),
                        'rejection_reason' => Arr::get($template, 'rejected_reason'),
                        'components' => $template['components'] ?? [],
                        'raw_payload' => $template,
                        'last_synced_at' => now(),
                    ],
                );

                $templates[] = $record->fresh()->toArray();
            }

            $nextUrl = Arr::get($response, 'paging.next');
        }

        return $templates;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function syncFromYCloud(): array
    {
        [$baseUrl, $apiKey, $wabaId] = $this->ycloudManagementConfiguration();

        $templates = [];
        $page = 1;
        $limit = 100;

        do {
            try {
                $response = $this->http
                    ->asJson()
                    ->withHeaders(['X-API-Key' => $apiKey])
                    ->get("{$baseUrl}/v2/whatsapp/templates", [
                        'page' => $page,
                        'limit' => $limit,
                        'filter.wabaId' => $wabaId,
                    ])
                    ->throw()
                    ->json();
            } catch (RequestException $exception) {
                throw new InvalidArgumentException($this->providerErrorMessage($exception));
            }

            $items = $this->extractPaginatedItems($response);

            foreach ($items as $template) {
                if (! is_array($template)) {
                    continue;
                }

                $record = WhatsAppMessageTemplate::query()->updateOrCreate(
                    [
                        'name' => (string) ($template['name'] ?? ''),
                        'language' => (string) ($template['language'] ?? 'en_US'),
                    ],
                    [
                        'template_uid' => (string) ($template['id'] ?? $template['templateId'] ?? '') ?: null,
                        'category' => $template['category'] ?? null,
                        'status' => $template['status'] ?? null,
                        'sub_category' => $template['subCategory'] ?? $template['sub_category'] ?? null,
                        'quality_score' => $template['qualityRating'] ?? $template['quality_score'] ?? Arr::get($template, 'qualityScore.score'),
                        'rejection_reason' => $template['rejectionReason'] ?? $template['rejectedReason'] ?? null,
                        'components' => $template['components'] ?? [],
                        'raw_payload' => $template,
                        'last_synced_at' => now(),
                    ],
                );

                $templates[] = $record->fresh()->toArray();
            }

            $page++;
        } while (count($items) === $limit && $page <= 100);

        return $templates;
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    public function createTemplate(string $name, string $language, string $category, array $components): array
    {
        return $this->resolvedDriver() === 'ycloud'
            ? $this->createYCloudTemplate($name, $language, $category, $components)
            : $this->createMetaTemplate($name, $language, $category, $components);
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    private function createMetaTemplate(string $name, string $language, string $category, array $components): array
    {
        [$endpoint, $token] = $this->managementConfiguration();

        $payload = [
            'name' => $name,
            'language' => $language,
            'category' => strtoupper($category),
            'components' => $components,
            'allow_category_change' => true,
        ];

        try {
            $response = $this->http
                ->asJson()
                ->withToken($token)
                ->post($endpoint, $payload)
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            throw new InvalidArgumentException(
                Arr::get($exception->response?->json(), 'error.message')
                ?? $exception->getMessage()
            );
        }

        $record = WhatsAppMessageTemplate::query()->updateOrCreate(
            [
                'name' => $name,
                'language' => $language,
            ],
            [
                'template_uid' => (string) ($response['id'] ?? '') ?: null,
                'category' => strtoupper($category),
                'status' => $response['status'] ?? 'PENDING',
                'components' => $components,
                'raw_payload' => $response,
                'last_synced_at' => now(),
            ],
        );

        return $record->fresh()->toArray();
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    private function createYCloudTemplate(string $name, string $language, string $category, array $components): array
    {
        [$baseUrl, $apiKey, $wabaId] = $this->ycloudManagementConfiguration();

        $payload = [
            'wabaId' => $wabaId,
            'name' => $name,
            'language' => $language,
            'category' => strtoupper($category),
            'components' => $components,
        ];

        try {
            $response = $this->http
                ->asJson()
                ->withHeaders(['X-API-Key' => $apiKey])
                ->post("{$baseUrl}/v2/whatsapp/templates", $payload)
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            throw new InvalidArgumentException($this->providerErrorMessage($exception));
        }

        $record = WhatsAppMessageTemplate::query()->updateOrCreate(
            [
                'name' => $name,
                'language' => $language,
            ],
            [
                'template_uid' => (string) ($response['id'] ?? $response['templateId'] ?? '') ?: null,
                'category' => $response['category'] ?? strtoupper($category),
                'status' => $response['status'] ?? 'PENDING',
                'components' => $response['components'] ?? $components,
                'raw_payload' => $response ?: $payload,
                'last_synced_at' => now(),
            ],
        );

        return $record->fresh()->toArray();
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    public function replaceTemplate(WhatsAppMessageTemplate $template, string $name, string $language, string $category, array $components): array
    {
        if ($this->resolvedDriver() === 'ycloud') {
            return $this->replaceYCloudTemplate($template, $name, $language, $category, $components);
        }

        $this->deleteTemplate($template, preserveLocalRecord: true);

        return $this->createTemplate($name, $language, $category, $components);
    }

    /**
     * @param  array<int, array<string, mixed>>  $components
     * @return array<string, mixed>
     */
    private function replaceYCloudTemplate(WhatsAppMessageTemplate $template, string $name, string $language, string $category, array $components): array
    {
        if ($template->name !== $name || $template->language !== $language) {
            $this->deleteYCloudTemplate($template, preserveLocalRecord: true);

            return $this->createYCloudTemplate($name, $language, $category, $components);
        }

        [$baseUrl, $apiKey, $wabaId] = $this->ycloudManagementConfiguration();

        try {
            $response = $this->http
                ->asJson()
                ->withHeaders(['X-API-Key' => $apiKey])
                ->patch($this->ycloudTemplateEndpoint($baseUrl, $wabaId, $template->name, $template->language), [
                    'components' => $components,
                ])
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            throw new InvalidArgumentException($this->providerErrorMessage($exception));
        }

        $template->update([
            'template_uid' => (string) ($response['id'] ?? $response['templateId'] ?? $template->template_uid ?? '') ?: null,
            'category' => $response['category'] ?? strtoupper($category),
            'status' => $response['status'] ?? $template->status,
            'components' => $response['components'] ?? $components,
            'raw_payload' => $response,
            'last_synced_at' => now(),
        ]);

        return $template->fresh()->toArray();
    }

    public function deleteTemplate(WhatsAppMessageTemplate $template, bool $preserveLocalRecord = false): void
    {
        if ($this->resolvedDriver() === 'ycloud') {
            $this->deleteYCloudTemplate($template, $preserveLocalRecord);

            return;
        }

        $this->deleteMetaTemplate($template, $preserveLocalRecord);
    }

    private function deleteMetaTemplate(WhatsAppMessageTemplate $template, bool $preserveLocalRecord = false): void
    {
        [$endpoint, $token] = $this->managementConfiguration();

        $query = [];
        if (filled($template->template_uid)) {
            $query['hsm_id'] = $template->template_uid;
        } else {
            $query['name'] = $template->name;
            $query['language'] = $template->language;
        }

        try {
            $this->http
                ->asJson()
                ->withToken($token)
                ->delete($endpoint.'?'.http_build_query($query))
                ->throw();
        } catch (RequestException $exception) {
            throw new InvalidArgumentException(
                Arr::get($exception->response?->json(), 'error.message')
                ?? $exception->getMessage()
            );
        }

        if (! $preserveLocalRecord) {
            $template->delete();
        }
    }

    private function deleteYCloudTemplate(WhatsAppMessageTemplate $template, bool $preserveLocalRecord = false): void
    {
        [$baseUrl, $apiKey, $wabaId] = $this->ycloudManagementConfiguration();

        try {
            $this->http
                ->asJson()
                ->withHeaders(['X-API-Key' => $apiKey])
                ->delete($this->ycloudTemplateEndpoint($baseUrl, $wabaId, $template->name, $template->language))
                ->throw();
        } catch (RequestException $exception) {
            throw new InvalidArgumentException($this->providerErrorMessage($exception));
        }

        if (! $preserveLocalRecord) {
            $template->delete();
        }
    }

    public function uploadHeaderSample(UploadedFile $file): string
    {
        if ($this->resolvedDriver() === 'ycloud') {
            throw new InvalidArgumentException('YCloud template media samples must use a public HTTPS sample URL.');
        }

        [$baseUrl, $version, $token] = $this->uploadConfiguration();

        try {
            $session = $this->http
                ->asJson()
                ->withToken($token)
                ->post("{$baseUrl}/{$version}/app/uploads", [
                    'file_name' => $file->getClientOriginalName(),
                    'file_length' => $file->getSize(),
                    'file_type' => $file->getMimeType(),
                ])
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            throw new InvalidArgumentException(
                Arr::get($exception->response?->json(), 'error.message')
                ?? $exception->getMessage()
            );
        }

        $uploadId = (string) ($session['id'] ?? '');

        if ($uploadId === '') {
            throw new InvalidArgumentException('Meta upload session did not return an upload ID.');
        }

        $stream = fopen($file->getRealPath(), 'rb');

        if ($stream === false) {
            throw new InvalidArgumentException('Unable to read the uploaded media file.');
        }

        try {
            $response = $this->http
                ->withToken($token)
                ->withHeaders([
                    'file_offset' => '0',
                    'Content-Type' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
                ])
                ->withBody($stream, (string) ($file->getMimeType() ?: 'application/octet-stream'))
                ->post("{$baseUrl}/{$version}/{$uploadId}")
                ->throw()
                ->json();
        } catch (RequestException $exception) {
            throw new InvalidArgumentException(
                Arr::get($exception->response?->json(), 'error.message')
                ?? $exception->getMessage()
            );
        } finally {
            fclose($stream);
        }

        $handle = (string) ($response['h'] ?? $response['handle'] ?? '');

        if ($handle === '') {
            throw new InvalidArgumentException('Meta upload did not return a media handle.');
        }

        return $handle;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function managementConfiguration(): array
    {
        $settings = FinanceSetting::current();
        $baseUrl = rtrim((string) ($settings->whatsapp_base_url ?: config('services.whatsapp.base_url', 'https://graph.facebook.com')), '/');
        $version = (string) ($settings->whatsapp_api_version ?: config('services.whatsapp.version', 'v25.0'));
        $businessAccountId = (string) ($settings->whatsapp_business_account_id ?: config('services.whatsapp.business_account_id'));
        $token = (string) ($settings->whatsapp_access_token ?: config('services.whatsapp.token'));

        if ($businessAccountId === '' || $token === '') {
            throw new InvalidArgumentException('WhatsApp Business Account ID or access token is missing.');
        }

        return ["{$baseUrl}/{$version}/{$businessAccountId}/message_templates", $token];
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function ycloudManagementConfiguration(): array
    {
        $settings = FinanceSetting::current();
        $apiKey = trim((string) ($settings->whatsapp_access_token ?: config('services.whatsapp.ycloud_api_key') ?: config('services.whatsapp.token')));
        $wabaId = trim((string) ($settings->whatsapp_business_account_id ?: config('services.whatsapp.business_account_id')));
        $configuredBaseUrl = (string) ($settings->whatsapp_base_url ?: config('services.whatsapp.base_url'));
        $baseUrl = str_contains($configuredBaseUrl, 'graph.facebook.com')
            ? (string) config('services.whatsapp.ycloud_base_url', 'https://api.ycloud.com')
            : $configuredBaseUrl;
        $baseUrl = rtrim($baseUrl ?: 'https://api.ycloud.com', '/');

        if ($wabaId === '' || $apiKey === '') {
            throw new InvalidArgumentException('YCloud WABA ID or API key is missing.');
        }

        return [$baseUrl, $apiKey, $wabaId];
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function uploadConfiguration(): array
    {
        $settings = FinanceSetting::current();
        $baseUrl = rtrim((string) ($settings->whatsapp_base_url ?: config('services.whatsapp.base_url', 'https://graph.facebook.com')), '/');
        $version = (string) ($settings->whatsapp_api_version ?: config('services.whatsapp.version', 'v25.0'));
        $token = (string) ($settings->whatsapp_access_token ?: config('services.whatsapp.token'));

        if ($token === '') {
            throw new InvalidArgumentException('WhatsApp access token is missing.');
        }

        return [$baseUrl, $version, $token];
    }

    private function resolvedDriver(): string
    {
        $settings = FinanceSetting::current();
        $driver = (string) ($settings->whatsapp_driver ?: config('services.whatsapp.driver', 'log'));
        $baseUrl = (string) ($settings->whatsapp_base_url ?: config('services.whatsapp.base_url', ''));

        return $this->isYCloudBaseUrl($baseUrl) ? 'ycloud' : $driver;
    }

    private function isYCloudBaseUrl(string $baseUrl): bool
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);

        return is_string($host) && str_ends_with(strtolower($host), 'ycloud.com');
    }

    /**
     * @param  array<string, mixed>|null  $response
     * @return array<int, mixed>
     */
    private function extractPaginatedItems(?array $response): array
    {
        if (! $response) {
            return [];
        }

        foreach (['data', 'items', 'list', 'results'] as $key) {
            $items = $response[$key] ?? null;
            if (is_array($items) && array_is_list($items)) {
                return $items;
            }
        }

        $nestedData = Arr::get($response, 'data.data');
        if (is_array($nestedData) && array_is_list($nestedData)) {
            return $nestedData;
        }

        return array_is_list($response) ? $response : [];
    }

    private function ycloudTemplateEndpoint(string $baseUrl, string $wabaId, string $name, string $language): string
    {
        return sprintf(
            '%s/v2/whatsapp/templates/%s/%s/%s',
            $baseUrl,
            rawurlencode($wabaId),
            rawurlencode($name),
            rawurlencode($language),
        );
    }

    private function providerErrorMessage(RequestException $exception): string
    {
        return Arr::get($exception->response?->json(), 'error.message')
            ?? Arr::get($exception->response?->json(), 'message')
            ?? $exception->getMessage();
    }
}
