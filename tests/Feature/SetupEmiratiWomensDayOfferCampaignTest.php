<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\FinanceSetting;
use App\Models\WhatsAppMessageTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SetupEmiratiWomensDayOfferCampaignTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_ycloud_template_and_local_campaign_records(): void
    {
        Http::fake([
            'https://api.ycloud.com/v2/whatsapp/templates?page=1&limit=100&filter.wabaId=2585564098538924' => Http::sequence()
                ->push(['data' => []], 200)
                ->push([
                    'data' => [
                        [
                            'id' => 'ycloud_tmpl_offer',
                            'name' => 'vina_emirati_womens_day_2026_offer',
                            'language' => 'en_US',
                            'category' => 'MARKETING',
                            'status' => 'PENDING',
                            'components' => [],
                        ],
                    ],
                ], 200),
            'https://api.ycloud.com/v2/whatsapp/templates' => Http::response([
                'id' => 'ycloud_tmpl_offer',
                'status' => 'PENDING',
                'category' => 'MARKETING',
            ], 200),
        ]);

        FinanceSetting::current()->update([
            'whatsapp_access_token' => 'ycloud-token',
            'whatsapp_business_account_id' => '4263327080555368',
        ]);

        $this->artisan('app:setup-emirati-womens-day-offer', [
            '--document-url' => 'https://example.com/vina-luxury-beauty-offer.pdf',
            '--waba-id' => '2585564098538924',
        ])->assertSuccessful();

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || $request->url() !== 'https://api.ycloud.com/v2/whatsapp/templates') {
                return false;
            }

            $components = $request['components'] ?? [];
            $header = collect($components)->firstWhere('type', 'HEADER');
            $body = collect($components)->firstWhere('type', 'BODY');

            return $request->hasHeader('X-API-Key', 'ycloud-token')
                && $request['wabaId'] === '2585564098538924'
                && $request['name'] === 'vina_emirati_womens_day_2026_offer'
                && $request['category'] === 'MARKETING'
                && ($header['format'] ?? null) === 'DOCUMENT'
                && ($header['example']['header_url'][0] ?? null) === 'https://example.com/vina-luxury-beauty-offer.pdf'
                && str_contains((string) ($body['text'] ?? ''), 'Dear {{1}},');
        });

        $this->assertDatabaseHas('finance_settings', [
            'id' => 1,
            'whatsapp_driver' => 'ycloud',
            'whatsapp_base_url' => 'https://api.ycloud.com',
            'whatsapp_business_account_id' => '2585564098538924',
        ]);

        $this->assertDatabaseHas('whatsapp_message_templates', [
            'template_uid' => 'ycloud_tmpl_offer',
            'name' => 'vina_emirati_womens_day_2026_offer',
            'language' => 'en_US',
        ]);

        $campaignTemplate = CampaignTemplate::query()->where('name', 'Emirati Women\'s Day 2026 WhatsApp PDF Offer')->first();

        $this->assertNotNull($campaignTemplate);
        $this->assertSame('template', $campaignTemplate->whatsapp_message_type);
        $this->assertSame('document', $campaignTemplate->whatsapp_header_type);
        $this->assertSame('https://example.com/vina-luxury-beauty-offer.pdf', $campaignTemplate->whatsapp_header_media_url);

        $campaign = Campaign::query()->where('name', 'Emirati Women\'s Day 2026 Offer')->first();

        $this->assertNotNull($campaign);
        $this->assertSame($campaignTemplate->id, $campaign->campaign_template_id);
        $this->assertSame('all', $campaign->audience_type);
        $this->assertSame('draft', $campaign->status);
    }

    public function test_command_requires_public_pdf_url(): void
    {
        $this->artisan('app:setup-emirati-womens-day-offer', [
            '--document-url' => 'C:\\Users\\Hp\\OneDrive\\Desktop\\offer.pdf',
        ])->assertFailed();

        $this->assertDatabaseCount(WhatsAppMessageTemplate::class, 0);
    }

    public function test_command_rejects_invalid_waba_id(): void
    {
        $this->artisan('app:setup-emirati-womens-day-offer', [
            '--document-url' => 'https://example.com/vina-luxury-beauty-offer.pdf',
            '--waba-id' => 'waba_123',
        ])->assertFailed();

        $this->assertDatabaseCount(WhatsAppMessageTemplate::class, 0);
    }
}
