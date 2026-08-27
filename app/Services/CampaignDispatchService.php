<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\CommunicationLog;
use App\Models\Customer;
use App\Models\CustomerDueService;
use Illuminate\Database\Eloquent\Builder;

class CampaignDispatchService
{
    public function __construct(
        private readonly CommunicationDeliveryService $communicationDeliveryService,
    ) {}

    public function dispatch(Campaign $campaign): array
    {
        $campaign->loadMissing('template');

        $queued = 0;

        $this->resolveAudience($campaign)
            ->orderBy('customers.id')
            ->chunkById(100, function ($customers) use ($campaign, &$queued): void {
                foreach ($customers as $customer) {
                    if ($this->hasAlreadyQueuedOrSent($campaign, $customer)) {
                        continue;
                    }

                    $recipient = $campaign->channel === 'email' ? $customer->email : $customer->phone;
                    $message = str_replace('{name}', $customer->name, $campaign->template?->content ?? '');

                    $options = $this->deliveryOptionsForCampaign($campaign, $customer->name);

                    $log = $this->communicationDeliveryService->deliver(
                        $customer,
                        $campaign->channel,
                        $recipient,
                        $message,
                        'campaign:'.$campaign->id,
                        $options,
                    );

                    if (in_array($log->status, ['queued', 'sent'], true)) {
                        $queued++;
                    }
                }
            }, 'customers.id', 'id');

        $campaign->update([
            'status' => 'sent',
            'last_run_at' => now(),
        ]);

        return ['queued' => $queued, 'sent' => 0, 'failed' => 0];
    }

    /**
     * @return array<string, mixed>
     */
    private function deliveryOptionsForCampaign(Campaign $campaign, string $customerName): array
    {
        if ($campaign->channel !== 'whatsapp') {
            return [];
        }

        $template = $campaign->template;

        if (($template?->whatsapp_message_type ?? 'text') === 'template' && filled($template?->whatsapp_template_name)) {
            $components = $this->templateSendComponents($template, $customerName);

            return [
                'async' => true,
                'message_type' => 'template',
                'template_name' => $template->whatsapp_template_name,
                'language_code' => $template->whatsapp_template_language_code ?: config('services.whatsapp.default_language_code', 'en_US'),
                'components' => $components,
            ];
        }

        return [
            'async' => true,
            'message_type' => 'text',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function templateSendComponents(CampaignTemplate $template, string $customerName): array
    {
        $components = [];
        $headerType = (string) ($template->whatsapp_header_type ?? '');
        $headerUrl = (string) ($template->whatsapp_header_media_url ?? '');

        if (in_array($headerType, ['image', 'video', 'document'], true) && $headerUrl !== '') {
            $mediaPayload = ['link' => $headerUrl];

            if ($headerType === 'document') {
                $mediaPayload['filename'] = $this->documentFilename($template, $headerUrl);
            }

            $components[] = [
                'type' => 'header',
                'parameters' => [
                    [
                        'type' => $headerType,
                        $headerType => $mediaPayload,
                    ],
                ],
            ];
        }

        $components[] = [
            'type' => 'body',
            'parameters' => [
                [
                    'type' => 'text',
                    'text' => $customerName,
                ],
            ],
        ];

        return $components;
    }

    private function documentFilename(CampaignTemplate $template, string $headerUrl): string
    {
        $configuredFilename = trim((string) ($template->whatsapp_header_media_filename ?? ''));

        if ($configuredFilename !== '') {
            return $configuredFilename;
        }

        $path = parse_url($headerUrl, PHP_URL_PATH);
        $basename = is_string($path) ? basename($path) : '';

        return $basename !== '' ? $basename : 'offer.pdf';
    }

    private function resolveAudience(Campaign $campaign): Builder
    {
        $customerCreatedAtExpr = "'1970-01-01 00:00:00'";

        return match ($campaign->audience_type) {
            'tag' => Customer::query()
                ->where('is_active', true)
                ->whereHas('tags', fn ($q) => $q->where('customer_tags.id', $campaign->customer_tag_id)),
            'due_service' => Customer::query()
                ->where('is_active', true)
                ->whereIn('id', CustomerDueService::query()
                    ->where('status', 'pending')
                    ->whereDate('due_date', '<=', now()->toDateString())
                    ->pluck('customer_id')),
            'inactivity_days' => Customer::query()
                ->where('is_active', true)
                ->leftJoin('appointments', function ($join): void {
                    $join->on('customers.id', '=', 'appointments.customer_id')
                        ->where('appointments.status', Appointment::STATUS_COMPLETED);
                })
                ->select('customers.*')
                ->groupBy('customers.id')
                ->havingRaw("COALESCE(MAX(appointments.scheduled_start), {$customerCreatedAtExpr}) <= ?", [now()->subDays((int) ($campaign->inactivity_days ?? 30))->toDateTimeString()]),
            default => Customer::query()->where('is_active', true),
        };
    }

    private function hasAlreadyQueuedOrSent(Campaign $campaign, Customer $customer): bool
    {
        return CommunicationLog::query()
            ->where('customer_id', $customer->id)
            ->where('channel', $campaign->channel)
            ->where('context', 'campaign:'.$campaign->id)
            ->whereIn('status', ['queued', 'sent'])
            ->exists();
    }
}
