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
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function syncFromMeta(): array
    {
        [$endpoint, $token] = $this->managementConfiguration();

        $templates = [];
        $nextUrl = $endpoint . '?limit=100';

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
     * @param array<int, array<string, mixed>> $components
     * @return array<string, mixed>
     */
    public function createTemplate(string $name, string $language, string $category, array $components): array
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
     * @param array<int, array<string, mixed>> $components
     * @return array<string, mixed>
     */
    public function replaceTemplate(WhatsAppMessageTemplate $template, string $name, string $language, string $category, array $components): array
    {
        $this->deleteTemplate($template, preserveLocalRecord: true);

        return $this->createTemplate($name, $language, $category, $components);
    }

    public function deleteTemplate(WhatsAppMessageTemplate $template, bool $preserveLocalRecord = false): void
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
                ->delete($endpoint . '?' . http_build_query($query))
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

    public function uploadHeaderSample(UploadedFile $file): string
    {
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
}
