<?php

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\FinanceSetting;
use App\Models\WhatsAppMessageTemplate;
use App\Services\WhatsAppTemplateManagerService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class SetupEmiratiWomensDayOfferCampaign extends Command
{
    protected $signature = 'app:setup-emirati-womens-day-offer
        {--document-url= : Public HTTPS URL for the PDF offer file}
        {--filename=vina-luxury-beauty-offer.pdf : Filename shown in WhatsApp}
        {--template-name=vina_emirati_womens_day_2026_offer : WhatsApp template name}
        {--language=en_US : WhatsApp template language code}
        {--replace : Replace the existing YCloud template if it is already synced locally}
        {--without-campaign : Only create/sync the WhatsApp template and CRM campaign template}';

    protected $description = 'Create and sync the Emirati Women\'s Day WhatsApp PDF offer template through YCloud.';

    public function handle(WhatsAppTemplateManagerService $templateManagerService): int
    {
        $documentUrl = trim((string) $this->option('document-url'));
        $filename = trim((string) $this->option('filename'));
        $templateName = strtolower(trim((string) $this->option('template-name')));
        $language = trim((string) $this->option('language'));

        if (! $this->isValidTemplateName($templateName)) {
            $this->error('Template name must contain only lowercase letters, numbers, and underscores.');

            return self::FAILURE;
        }

        if (! $this->isPublicPdfUrl($documentUrl)) {
            $this->error('Provide a public HTTPS PDF URL with --document-url=, ending in .pdf.');

            return self::FAILURE;
        }

        $this->configureYCloudDriver();

        try {
            $this->line('Syncing existing YCloud templates...');
            $templateManagerService->syncTemplates();

            $existingTemplate = WhatsAppMessageTemplate::query()
                ->where('name', $templateName)
                ->where('language', $language)
                ->first();

            $components = $this->templateComponents($documentUrl);

            if ($existingTemplate && $this->option('replace')) {
                $this->line("Replacing YCloud template [{$templateName}]...");
                $template = $templateManagerService->replaceTemplate(
                    $existingTemplate,
                    $templateName,
                    $language,
                    'MARKETING',
                    $components,
                );
            } elseif ($existingTemplate) {
                $this->line("YCloud template [{$templateName}] already exists locally; skipping create.");
                $template = $existingTemplate->toArray();
            } else {
                $this->line("Creating YCloud template [{$templateName}]...");
                $template = $templateManagerService->createTemplate(
                    $templateName,
                    $language,
                    'MARKETING',
                    $components,
                );
            }

            $this->line('Syncing YCloud templates after setup...');
            $templateManagerService->syncTemplates();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $campaignTemplate = CampaignTemplate::query()->updateOrCreate(
            ['name' => 'Emirati Women\'s Day 2026 WhatsApp PDF Offer'],
            [
                'channel' => 'whatsapp',
                'content' => $this->campaignContent(),
                'whatsapp_message_type' => 'template',
                'whatsapp_template_name' => $templateName,
                'whatsapp_template_language_code' => $language,
                'whatsapp_header_type' => 'document',
                'whatsapp_header_media_url' => $documentUrl,
                'whatsapp_header_media_filename' => $filename !== '' ? $filename : 'vina-luxury-beauty-offer.pdf',
                'is_active' => true,
            ],
        );

        $campaign = null;

        if (! $this->option('without-campaign')) {
            $campaign = Campaign::query()->updateOrCreate(
                ['name' => 'Emirati Women\'s Day 2026 Offer'],
                [
                    'campaign_template_id' => $campaignTemplate->id,
                    'channel' => 'whatsapp',
                    'audience_type' => 'all',
                    'customer_tag_id' => null,
                    'inactivity_days' => null,
                    'scheduled_at' => null,
                    'status' => 'draft',
                ],
            );
        }

        $status = (string) ($template['status'] ?? 'PENDING');

        $this->info('Offer setup complete.');
        $this->line("YCloud template: {$templateName} ({$status})");
        $this->line("CRM campaign template ID: {$campaignTemplate->id}");

        if ($campaign) {
            $this->line("Draft campaign ID: {$campaign->id}");
        }

        if (! in_array(strtoupper($status), ['APPROVED', 'ACTIVE', 'ACTIVE - QUALITY PENDING', 'ACTIVE - HIGH QUALITY', 'ACTIVE - MEDIUM QUALITY'], true)) {
            $this->warn('Wait until YCloud/WhatsApp approves the template before dispatching the campaign.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function templateComponents(string $documentUrl): array
    {
        return [
            [
                'type' => 'HEADER',
                'format' => 'DOCUMENT',
                'example' => [
                    'header_url' => [$documentUrl],
                ],
            ],
            [
                'type' => 'BODY',
                'text' => $this->templateBody(),
                'example' => [
                    'body_text' => [
                        ['Alya'],
                    ],
                ],
            ],
            [
                'type' => 'FOOTER',
                'text' => 'VINA Luxury Beauty Salon',
            ],
        ];
    }

    private function templateBody(): string
    {
        return "Dear {{1}},\n\nOn the occasion of Emirati Women's Day, VINA extends sincere congratulations and best wishes to the women of the UAE.\n\nVINA is pleased to welcome you on this special occasion with 20% off all services from 28-30 August 2026.\n\nWe look forward to welcoming you.";
    }

    private function campaignContent(): string
    {
        return str_replace('{{1}}', '{name}', $this->templateBody());
    }

    private function configureYCloudDriver(): void
    {
        $settings = FinanceSetting::current();

        $settings->forceFill([
            'whatsapp_driver' => 'ycloud',
            'whatsapp_base_url' => 'https://api.ycloud.com',
        ])->save();
    }

    private function isValidTemplateName(string $templateName): bool
    {
        return preg_match('/^[a-z0-9_]+$/', $templateName) === 1;
    }

    private function isPublicPdfUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $path = parse_url($url, PHP_URL_PATH);

        return $scheme === 'https'
            && is_string($path)
            && str_ends_with(strtolower($path), '.pdf');
    }
}
