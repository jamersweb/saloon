<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppDeliveryJob;
use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\CommunicationLog;
use App\Models\Customer;
use App\Models\CustomerDueService;
use App\Models\CustomerTag;
use App\Models\FinanceSetting;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\User;
use App\Models\WhatsAppMessageTemplate;
use App\Services\WhatsAppService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class WhatsAppDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_service_whatsapp_reminder_queues_delivery_and_logs_metadata(): void
    {
        Queue::fake();

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $customer = Customer::create([
            'customer_code' => 'CUST-WA-001',
            'name' => 'WhatsApp Customer',
            'phone' => '+1 (555) 222-3333',
            'is_active' => true,
        ]);

        $service = SalonService::create([
            'name' => 'Color Refresh',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'price' => 100,
            'repeat_after_days' => 30,
            'is_active' => true,
        ]);

        $dueService = CustomerDueService::create([
            'customer_id' => $customer->id,
            'salon_service_id' => $service->id,
            'due_date' => now()->toDateString(),
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->post(route('customers.automation.due-services.remind', $dueService), [
                'channel' => 'whatsapp',
            ])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(SendWhatsAppDeliveryJob::class, 1);

        $this->assertDatabaseHas('communication_logs', [
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'queued',
            'provider_status' => 'queued',
            'message_type' => 'text',
        ]);
        $this->assertNotNull($dueService->fresh()->reminder_sent_at);
    }

    public function test_campaign_dispatch_queues_whatsapp_delivery_jobs_in_batches(): void
    {
        Queue::fake();

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $customer = Customer::create([
            'customer_code' => 'CUST-WA-002',
            'name' => 'Campaign Customer',
            'phone' => '5554447777',
            'is_active' => true,
        ]);

        $template = CampaignTemplate::create([
            'name' => 'WhatsApp Blast',
            'channel' => 'whatsapp',
            'content' => 'Hi {name}, this is a test campaign.',
            'is_active' => true,
        ]);

        $campaign = Campaign::create([
            'name' => 'Weekend Push',
            'campaign_template_id' => $template->id,
            'channel' => 'whatsapp',
            'audience_type' => 'all',
            'status' => 'draft',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->post(route('customers.automation.campaigns.dispatch', $campaign))
            ->assertSessionHasNoErrors();

        $campaign->refresh();

        $this->assertSame(0, $campaign->sent_count);
        $this->assertSame(0, $campaign->failed_count);
        Queue::assertPushed(SendWhatsAppDeliveryJob::class, 1);

        $log = CommunicationLog::query()->latest()->first();

        $this->assertNotNull($log);
        $this->assertSame('queued', $log->status);
        $this->assertSame('queued', $log->provider_status);
    }

    public function test_campaign_dispatch_includes_whatsapp_document_header_for_template_campaigns(): void
    {
        Queue::fake();

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        Customer::create([
            'customer_code' => 'CUST-WA-DOC',
            'name' => 'Document Customer',
            'phone' => '5554448888',
            'is_active' => true,
        ]);

        $template = CampaignTemplate::create([
            'name' => 'WhatsApp PDF Blast',
            'channel' => 'whatsapp',
            'content' => 'Dear {name}, view our latest offer.',
            'whatsapp_message_type' => 'template',
            'whatsapp_template_name' => 'vina_emirati_womens_day_2026_offer',
            'whatsapp_template_language_code' => 'en_US',
            'whatsapp_header_type' => 'document',
            'whatsapp_header_media_url' => 'https://example.com/vina-luxury-beauty-offer.pdf',
            'whatsapp_header_media_filename' => 'vina-luxury-beauty-offer.pdf',
            'is_active' => true,
        ]);

        $campaign = Campaign::create([
            'name' => 'PDF Push',
            'campaign_template_id' => $template->id,
            'channel' => 'whatsapp',
            'audience_type' => 'all',
            'status' => 'draft',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->post(route('customers.automation.campaigns.dispatch', $campaign))
            ->assertSessionHasNoErrors();

        Queue::assertPushed(SendWhatsAppDeliveryJob::class, function (SendWhatsAppDeliveryJob $job) {
            $header = collect($job->payload['components'] ?? [])->firstWhere('type', 'header');

            return ($job->payload['message_type'] ?? null) === 'template'
                && ($job->payload['template_name'] ?? null) === 'vina_emirati_womens_day_2026_offer'
                && ($header['parameters'][0]['type'] ?? null) === 'document'
                && ($header['parameters'][0]['document']['link'] ?? null) === 'https://example.com/vina-luxury-beauty-offer.pdf'
                && ($header['parameters'][0]['document']['filename'] ?? null) === 'vina-luxury-beauty-offer.pdf';
        });
    }

    public function test_campaign_dispatch_queues_tagged_whatsapp_audience(): void
    {
        Queue::fake();

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $tag = CustomerTag::create([
            'name' => 'Vip',
            'color' => '#b85c64',
            'is_active' => true,
        ]);

        $matchingCustomer = Customer::create([
            'customer_code' => 'CUST-WA-TAG-1',
            'name' => 'Tagged Customer',
            'phone' => '971501111111',
            'is_active' => true,
        ]);
        $matchingCustomer->tags()->attach($tag->id);

        Customer::create([
            'customer_code' => 'CUST-WA-TAG-2',
            'name' => 'Untagged Customer',
            'phone' => '971502222222',
            'is_active' => true,
        ]);

        $template = CampaignTemplate::create([
            'name' => 'Tagged WhatsApp Blast',
            'channel' => 'whatsapp',
            'content' => 'Hi {name}, this is a tagged campaign.',
            'is_active' => true,
        ]);

        $campaign = Campaign::create([
            'name' => 'Tagged Push',
            'campaign_template_id' => $template->id,
            'channel' => 'whatsapp',
            'audience_type' => 'tag',
            'customer_tag_id' => $tag->id,
            'status' => 'draft',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->post(route('customers.automation.campaigns.dispatch', $campaign))
            ->assertSessionHasNoErrors();

        Queue::assertPushed(SendWhatsAppDeliveryJob::class, 1);

        $this->assertDatabaseHas('communication_logs', [
            'customer_id' => $matchingCustomer->id,
            'channel' => 'whatsapp',
            'context' => 'campaign:'.$campaign->id,
            'status' => 'queued',
        ]);
        $this->assertDatabaseMissing('communication_logs', [
            'recipient' => '+971502222222',
            'context' => 'campaign:'.$campaign->id,
        ]);
    }

    public function test_campaign_dispatch_skips_already_queued_or_sent_customers_on_retry(): void
    {
        Queue::fake();

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $alreadyQueued = Customer::create([
            'customer_code' => 'CUST-WA-RETRY-1',
            'name' => 'Already Queued',
            'phone' => '971503333333',
            'is_active' => true,
        ]);

        $newCustomer = Customer::create([
            'customer_code' => 'CUST-WA-RETRY-2',
            'name' => 'New Customer',
            'phone' => '971504444444',
            'is_active' => true,
        ]);

        $template = CampaignTemplate::create([
            'name' => 'Retry WhatsApp Blast',
            'channel' => 'whatsapp',
            'content' => 'Hi {name}, this is a retry-safe campaign.',
            'is_active' => true,
        ]);

        $campaign = Campaign::create([
            'name' => 'Retry Push',
            'campaign_template_id' => $template->id,
            'channel' => 'whatsapp',
            'audience_type' => 'all',
            'status' => 'draft',
            'created_by' => $user->id,
        ]);

        CommunicationLog::create([
            'customer_id' => $alreadyQueued->id,
            'channel' => 'whatsapp',
            'context' => 'campaign:'.$campaign->id,
            'recipient' => '+971503333333',
            'message' => 'Existing queued message',
            'status' => 'queued',
            'provider' => 'whatsapp',
            'provider_status' => 'queued',
            'message_type' => 'text',
            'queued_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('customers.automation.campaigns.dispatch', $campaign))
            ->assertSessionHasNoErrors();

        Queue::assertPushed(SendWhatsAppDeliveryJob::class, 1);

        $this->assertSame(
            1,
            CommunicationLog::query()
                ->where('customer_id', $alreadyQueued->id)
                ->where('context', 'campaign:'.$campaign->id)
                ->count()
        );
        $this->assertDatabaseHas('communication_logs', [
            'customer_id' => $newCustomer->id,
            'channel' => 'whatsapp',
            'context' => 'campaign:'.$campaign->id,
            'status' => 'queued',
        ]);
    }

    public function test_whatsapp_template_command_posts_template_payload_to_meta(): void
    {
        config()->set('services.whatsapp.driver', 'meta');
        config()->set('services.whatsapp.phone_number_id', '1023883817485941');
        config()->set('services.whatsapp.token', 'secret-token');
        config()->set('services.whatsapp.version', 'v25.0');

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [
                    ['id' => 'wamid.template-123'],
                ],
            ], 200),
        ]);

        $this->artisan('app:send-whatsapp-template', [
            'recipient' => '923473639710',
            'template' => 'hello_world',
            '--language' => 'en_US',
        ])
            ->expectsOutput('WhatsApp template sent successfully.')
            ->assertExitCode(0);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v25.0/1023883817485941/messages'
                && $request->hasHeader('Authorization', 'Bearer secret-token')
                && $request['messaging_product'] === 'whatsapp'
                && $request['to'] === '923473639710'
                && $request['type'] === 'template'
                && $request['template']['name'] === 'hello_world'
                && $request['template']['language']['code'] === 'en_US';
        });
    }

    public function test_whatsapp_template_command_posts_template_payload_to_ycloud(): void
    {
        FinanceSetting::current()->update([
            'whatsapp_driver' => 'ycloud',
            'whatsapp_base_url' => 'https://graph.facebook.com',
            'whatsapp_phone_number_id' => '+971501111111',
            'whatsapp_access_token' => 'ycloud-secret-key',
            'whatsapp_default_language_code' => 'en',
        ]);

        Http::fake([
            'https://api.ycloud.com/*' => Http::response([
                'id' => 'ycloud-template-123',
                'status' => 'accepted',
            ], 200),
        ]);

        $this->artisan('app:send-whatsapp-template', [
            'recipient' => '971502222222',
            'template' => 'hello_world',
            '--language' => 'en',
        ])
            ->expectsOutput('WhatsApp template sent successfully.')
            ->assertExitCode(0);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.ycloud.com/v2/whatsapp/messages'
                && $request->hasHeader('X-API-Key', 'ycloud-secret-key')
                && $request['from'] === '+971501111111'
                && $request['to'] === '+971502222222'
                && $request['type'] === 'template'
                && $request['template']['name'] === 'hello_world'
                && $request['template']['language']['code'] === 'en';
        });
    }

    public function test_ycloud_text_delivery_uses_e164_numbers_and_api_key_header(): void
    {
        FinanceSetting::current()->update([
            'whatsapp_driver' => 'ycloud',
            'whatsapp_phone_number_id' => '+971501111111',
            'whatsapp_access_token' => 'ycloud-secret-key',
        ]);

        Http::fake([
            'https://api.ycloud.com/*' => Http::response([
                'id' => 'ycloud-text-123',
                'status' => 'accepted',
            ], 200),
        ]);

        $result = app(WhatsAppService::class)->sendText('+971 50 222 2222', 'Hello from Vina');

        $this->assertTrue($result['successful']);
        $this->assertSame('whatsapp-ycloud', $result['provider']);
        $this->assertSame('ycloud-text-123', $result['provider_message_id']);
        $this->assertSame('+971502222222', $result['recipient']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.ycloud.com/v2/whatsapp/messages'
                && $request->hasHeader('X-API-Key', 'ycloud-secret-key')
                && $request['from'] === '+971501111111'
                && $request['to'] === '+971502222222'
                && $request['type'] === 'text'
                && $request['text']['body'] === 'Hello from Vina'
                && $request['text']['preview_url'] === false;
        });
    }

    public function test_whatsapp_normalizes_uae_local_mobile_numbers_for_transport(): void
    {
        FinanceSetting::current()->update([
            'whatsapp_driver' => 'ycloud',
        ]);

        $service = app(WhatsAppService::class);

        $this->assertSame('+971544550498', $service->normalizeRecipientForTransport('0544550498'));
        $this->assertSame('+971544550498', $service->normalizeRecipientForTransport('+0544550498'));
        $this->assertSame('+971588174848', $service->normalizeRecipientForTransport('588174848'));
    }

    public function test_whatsapp_rejects_placeholder_and_invalid_international_numbers(): void
    {
        $service = app(WhatsAppService::class);

        foreach (['000000', '+0000000000', '+07403765451'] as $recipient) {
            try {
                $service->normalizeRecipientForTransport($recipient);
                $this->fail("Expected [{$recipient}] to be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('WhatsApp recipient must contain a valid phone number.', $exception->getMessage());
            }
        }
    }

    public function test_ycloud_base_url_uses_api_key_header_even_when_driver_is_meta(): void
    {
        FinanceSetting::current()->update([
            'whatsapp_driver' => 'meta',
            'whatsapp_base_url' => 'https://api.ycloud.com',
            'whatsapp_phone_number_id' => '+971501111111',
            'whatsapp_access_token' => 'ycloud-secret-key',
        ]);

        Http::fake([
            'https://api.ycloud.com/*' => Http::response([
                'id' => 'ycloud-text-mixed-config',
                'status' => 'accepted',
            ], 200),
        ]);

        $result = app(WhatsAppService::class)->sendText('+971 50 222 2222', 'Hello from Vina');

        $this->assertTrue($result['successful']);
        $this->assertSame('whatsapp-ycloud', $result['provider']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.ycloud.com/v2/whatsapp/messages'
                && $request->hasHeader('X-API-Key', 'ycloud-secret-key')
                && $request['from'] === '+971501111111'
                && $request['to'] === '+971502222222'
                && $request['type'] === 'text';
        });
    }

    public function test_ycloud_configuration_errors_return_failed_result_without_throwing(): void
    {
        config()->set('services.whatsapp.phone_number_id', null);
        config()->set('services.whatsapp.token', null);
        config()->set('services.whatsapp.ycloud_sender', null);
        config()->set('services.whatsapp.ycloud_api_key', null);

        FinanceSetting::current()->update([
            'whatsapp_driver' => 'ycloud',
            'whatsapp_phone_number_id' => null,
            'whatsapp_access_token' => null,
        ]);

        $result = app(WhatsAppService::class)->sendText('+971502222222', 'Hello');

        $this->assertFalse($result['successful']);
        $this->assertSame('whatsapp-ycloud', $result['provider']);
        $this->assertSame('WhatsApp YCloud configuration is incomplete.', $result['error_message']);
    }

    public function test_invalid_whatsapp_recipient_creates_failed_log_without_throwing(): void
    {
        Queue::fake();

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $customer = Customer::create([
            'customer_code' => 'CUST-WA-003',
            'name' => 'Broken Phone Customer',
            'phone' => '123',
            'is_active' => true,
        ]);

        $service = SalonService::create([
            'name' => 'Repair Service',
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'price' => 80,
            'repeat_after_days' => 14,
            'is_active' => true,
        ]);

        $dueService = CustomerDueService::create([
            'customer_id' => $customer->id,
            'salon_service_id' => $service->id,
            'due_date' => now()->toDateString(),
            'status' => 'pending',
        ]);

        $this->actingAs($user)
            ->from(route('customers.automation.index'))
            ->post(route('customers.automation.due-services.remind', $dueService), [
                'channel' => 'whatsapp',
            ])
            ->assertSessionHasErrors('channel');

        Queue::assertNothingPushed();

        $this->assertDatabaseHas('communication_logs', [
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'failed',
            'provider_status' => 'invalid-recipient',
        ]);
    }

    public function test_whatsapp_delivery_job_defines_retry_backoff_and_rate_limit_middleware(): void
    {
        $job = new SendWhatsAppDeliveryJob(1, ['message_type' => 'text', 'recipient' => '923473639710', 'message' => 'Hi']);

        $this->assertSame([60, 300, 900, 1800], $job->backoff());
        $this->assertCount(1, $job->middleware());
        $this->assertSame(\Illuminate\Queue\Middleware\RateLimited::class, $job->middleware()[0]::class);
    }

    public function test_whatsapp_delivery_job_does_not_retry_ecosystem_engagement_failures(): void
    {
        FinanceSetting::current()->update([
            'whatsapp_driver' => 'ycloud',
            'whatsapp_phone_number_id' => '+971501111111',
            'whatsapp_access_token' => 'ycloud-secret-key',
        ]);

        $template = CampaignTemplate::create([
            'name' => 'Engagement Limited Template',
            'channel' => 'whatsapp',
            'content' => 'Campaign message',
            'is_active' => true,
        ]);

        $campaign = Campaign::create([
            'name' => 'Engagement Limited Campaign',
            'campaign_template_id' => $template->id,
            'channel' => 'whatsapp',
            'audience_type' => 'all',
            'status' => 'draft',
        ]);

        $log = CommunicationLog::create([
            'channel' => 'whatsapp',
            'context' => 'campaign:'.$campaign->id,
            'recipient' => '+971556354004',
            'message' => 'Campaign message',
            'status' => 'queued',
            'provider' => 'whatsapp',
            'provider_status' => 'queued',
            'message_type' => 'text',
            'queued_at' => now(),
        ]);

        Http::fake([
            'https://api.ycloud.com/*' => Http::response([
                'error' => [
                    'message' => '131049 In order to maintain a healthy ecosystem engagement, the message failed to be delivered.',
                ],
            ], 400),
        ]);

        $job = new SendWhatsAppDeliveryJob($log->id, [
            'message_type' => 'text',
            'recipient' => '+971556354004',
            'message' => 'Campaign message',
        ]);

        $job->handle(app(WhatsAppService::class));

        $log->refresh();

        $this->assertSame('failed', $log->status);
        $this->assertSame('failed', $log->provider_status);
        $this->assertSame(1, $log->attempt_count);
        $this->assertStringContainsString('131049', $log->error_message);
        $this->assertNotNull($log->failed_at);
        $this->assertSame(1, $campaign->fresh()->failed_count);
    }

    public function test_single_whatsapp_message_can_be_queued_for_one_customer(): void
    {
        Queue::fake();

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $customer = Customer::create([
            'customer_code' => 'CUST-WA-004',
            'name' => 'Single Message Customer',
            'phone' => '971505555555',
            'email' => 'single@example.com',
            'is_active' => true,
        ]);

        $template = WhatsAppMessageTemplate::create([
            'template_uid' => 'meta-template-1',
            'name' => 'welcome_whatsapp',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}']],
            'last_synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('customers.automation.messages.single'), [
                'customer_id' => $customer->id,
                'channel' => 'whatsapp',
                'whatsapp_message_type' => 'template',
                'whatsapp_template_id' => $template->id,
                'whatsapp_template_variables' => 'Alya',
            ])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(SendWhatsAppDeliveryJob::class, 1);

        $this->assertDatabaseHas('communication_logs', [
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'queued',
            'provider_status' => 'queued',
            'message_type' => 'template',
            'context' => 'single_message:'.$customer->id,
        ]);
    }

    public function test_single_whatsapp_template_requires_matching_body_variables(): void
    {
        Queue::fake();

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);
        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $customer = Customer::create([
            'customer_code' => 'CUST-WA-005',
            'name' => 'Missing Variable Customer',
            'phone' => '923473639710',
            'is_active' => true,
        ]);

        $template = WhatsAppMessageTemplate::create([
            'template_uid' => 'meta-template-2',
            'name' => 'needs_name',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}']],
            'last_synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->from(route('customers.automation.index'))
            ->post(route('customers.automation.messages.single'), [
                'customer_id' => $customer->id,
                'channel' => 'whatsapp',
                'whatsapp_message_type' => 'template',
                'whatsapp_template_id' => $template->id,
                'whatsapp_template_variables' => '',
            ])
            ->assertSessionHasErrors('whatsapp_template_variables');

        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('communication_logs', [
            'customer_id' => $customer->id,
            'context' => 'single_message:'.$customer->id,
        ]);
    }
}
