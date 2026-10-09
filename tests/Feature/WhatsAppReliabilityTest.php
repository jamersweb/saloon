<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppDeliveryJob;
use App\Models\CommunicationLog;
use App\Models\Customer;
use App\Models\CustomerDueService;
use App\Models\FinanceSetting;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\User;
use App\Models\WhatsAppMessageTemplate;
use App\Services\CommunicationDeliveryService;
use App\Services\WhatsAppService;
use App\Services\WhatsAppTemplateValidator;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class WhatsAppReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        FinanceSetting::current()->update([
            'whatsapp_driver' => 'ycloud', 'whatsapp_base_url' => 'https://api.ycloud.com',
            'whatsapp_phone_number_id' => '+971501111111', 'whatsapp_access_token' => 'test-key',
        ]);
    }

    private function template(array $components = []): WhatsAppMessageTemplate
    {
        return WhatsAppMessageTemplate::create([
            'name' => 'reliable_notice', 'language' => 'en_US', 'status' => 'APPROVED',
            'components' => $components ?: [['type' => 'BODY', 'text' => 'Hello from VINA.']],
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create(['customer_code' => 'RELIABLE-1', 'name' => 'Customer', 'phone' => '0544550498', 'is_active' => true]);
    }

    private function log(string $context = 'single_message:1'): CommunicationLog
    {
        return CommunicationLog::create([
            'channel' => 'whatsapp', 'context' => $context, 'recipient' => '+971544550498',
            'message' => 'Hello', 'message_type' => 'template', 'status' => 'queued', 'provider_status' => 'queued',
        ]);
    }

    private function job(CommunicationLog $log): SendWhatsAppDeliveryJob
    {
        return new SendWhatsAppDeliveryJob($log->id, [
            'recipient' => $log->recipient, 'message_type' => 'template', 'template_name' => 'reliable_notice',
            'language_code' => 'en_US', 'components' => [],
        ]);
    }

    public function test_sms_cannot_report_success_or_queue_a_job(): void
    {
        Queue::fake();
        Http::fake();
        $log = app(CommunicationDeliveryService::class)->deliver($this->customer(), 'sms', '0544550498', 'Hello', 'test');
        $this->assertSame('failed', $log->status);
        $this->assertSame('disabled', $log->provider_status);
        $this->assertNull($log->sent_at);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_historical_sms_success_cannot_bypass_disabled_channel(): void
    {
        $customer = $this->customer();
        CommunicationLog::create(['customer_id' => $customer->id, 'channel' => 'sms', 'context' => 'due_service_reminder:1', 'status' => 'sent']);
        $log = app(CommunicationDeliveryService::class)->deliver($customer, 'sms', $customer->phone, 'Hello', 'due_service_reminder:1');
        $this->assertSame('failed', $log->status);
        $this->assertSame('disabled', $log->provider_status);
    }

    public function test_reply_from_another_sender_does_not_open_the_window(): void
    {
        DB::table('whatsapp_reply_windows')->insert(['sender' => '971509999999', 'recipient' => '971544550498', 'last_inbound_at' => now()]);
        $this->expectException(InvalidArgumentException::class);
        app(WhatsAppService::class)->assertReplyWindow('0544550498');
    }

    public function test_campaign_with_mismatched_template_is_blocked_before_fanout(): void
    {
        Queue::fake();
        $this->template([['type' => 'BODY', 'text' => 'Hello {{1}}, your {{2}} is ready.']]);
        $this->customer();
        $template = \App\Models\CampaignTemplate::create([
            'name' => 'Bad mapping', 'channel' => 'whatsapp', 'content' => 'Hello', 'is_active' => true,
            'whatsapp_message_type' => 'template', 'whatsapp_template_name' => 'reliable_notice', 'whatsapp_template_language_code' => 'en_US',
        ]);
        $campaign = \App\Models\Campaign::create(['name' => 'Campaign', 'channel' => 'whatsapp', 'campaign_template_id' => $template->id, 'audience_type' => 'all', 'status' => 'draft']);
        $result = app(\App\Services\CampaignDispatchService::class)->dispatch($campaign);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('draft', $campaign->fresh()->status);
        $this->assertDatabaseCount('communication_logs', 0);
        Queue::assertNothingPushed();
    }

    public function test_sync_marks_removed_templates_unavailable(): void
    {
        $template = $this->template();
        FinanceSetting::current()->update(['whatsapp_business_account_id' => 'waba-test']);
        Http::fake(['api.ycloud.com/*' => Http::response(['items' => []])]);
        app(\App\Services\WhatsAppTemplateManagerService::class)->syncTemplates();
        $this->assertSame('UNAVAILABLE', $template->fresh()->status);
    }

    public function test_phone_normalization_preserves_international_numbers_and_rejects_multiple_numbers(): void
    {
        $service = app(WhatsAppService::class);
        $this->assertSame('+971544550498', $service->normalizeRecipientForTransport('0544550498'));
        $this->assertSame('+966509800563', $service->normalizeRecipientForTransport('+966 50 980 0563'));
        $this->assertSame('+971544550498', $service->normalizeRecipientForTransport('00971544550498'));
        foreach (['0569085616/0551517321', '+971501111111,+971502222222', '12345678 ext 9'] as $number) {
            try {
                $service->normalizeRecipientForTransport($number);
                $this->fail('Multiple numbers/extensions must be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('one phone number', $exception->getMessage());
            }
        }
    }

    public function test_template_name_language_count_and_header_are_checked_before_http(): void
    {
        Http::fake();
        $this->template([
            ['type' => 'HEADER', 'format' => 'IMAGE'],
            ['type' => 'BODY', 'text' => 'Hello {{1}}, welcome to VINA.'],
        ]);
        $valid = [
            ['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => 'https://example.com/image.jpg']]]],
            ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Customer']]],
        ];
        foreach ([['test template', 'en_US', $valid], ['reliable_notice', 'ar', $valid], ['reliable_notice', 'en_US', []], ['reliable_notice', 'en_US', [$valid[0]]]] as [$name, $language, $components]) {
            try {
                app(WhatsAppService::class)->sendTemplate('+971544550498', $name, $language, $components);
                $this->fail('Invalid template should be rejected.');
            } catch (InvalidArgumentException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        app(WhatsAppTemplateValidator::class)->validate('reliable_notice', 'en_US', $valid);
        Http::assertNothingSent();
    }

    public function test_acceptance_does_not_mark_reminder_delivered_and_job_cannot_send_twice(): void
    {
        $this->template();
        Http::fake(['api.ycloud.com/*' => Http::response(['id' => 'yc-accepted', 'status' => 'accepted'])]);
        $log = $this->log('due_service_reminder:1');
        $job = $this->job($log);
        $job->handle(app(WhatsAppService::class));
        $job->handle(app(WhatsAppService::class));
        $this->assertSame('queued', $log->fresh()->status);
        $this->assertSame('accepted', $log->fresh()->provider_status);
        $this->assertNull($log->fresh()->sent_at);
        $this->assertNull($log->fresh()->delivered_at);
        Http::assertSentCount(1);
    }

    public function test_permanent_provider_error_is_not_retried_but_server_error_is(): void
    {
        $this->template();
        Http::fake(['api.ycloud.com/*' => Http::sequence()
            ->push(['error' => ['code' => 'WHATSAPP_TEMPLATE_UNAVAILABLE', 'message' => 'Template not found']], 400)
            ->push(['error' => ['code' => 'INTERNAL_SERVER_ERROR', 'message' => 'Please retry']], 503)]);
        $permanent = $this->log();
        $this->job($permanent)->handle(app(WhatsAppService::class));
        $this->job($permanent)->handle(app(WhatsAppService::class));
        $this->assertSame('failed', $permanent->fresh()->status);
        $transient = $this->log();
        try {
            $this->job($transient)->handle(app(WhatsAppService::class));
            $this->fail('Transient failure should retry.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Please retry', $exception->getMessage());
        }
        $this->assertSame('retrying', $transient->fresh()->provider_status);
        Http::assertSentCount(2);
    }

    public function test_invalid_legacy_job_is_terminal_without_provider_request(): void
    {
        Http::fake();
        $log = $this->log();
        $this->job($log)->handle(app(WhatsAppService::class));
        $this->assertSame('failed', $log->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_inbound_webhook_opens_only_the_matching_sender_reply_window_and_expires(): void
    {
        $this->postJson(route('whatsapp.webhook.receive'), [
            'type' => 'whatsapp.inbound_message.received',
            'whatsappInboundMessage' => ['to' => '+971501111111', 'from' => '+971544550498', 'sendTime' => now()->subMinute()->toIso8601String()],
        ])->assertOk();
        Http::fake(['api.ycloud.com/*' => Http::response(['id' => 'yc-reply'])]);
        $this->assertTrue(app(WhatsAppService::class)->sendText('0544550498', 'Reply')['successful']);
        $this->travel(24)->hours();
        try {
            app(WhatsAppService::class)->sendText('0544550498', 'Too late');
            $this->fail('Expired session must be blocked.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('131047', $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_reminder_receipt_is_idempotent_and_cannot_regress_to_accepted(): void
    {
        $customer = $this->customer();
        $service = SalonService::create(['name' => 'Hair', 'duration_minutes' => 30, 'buffer_minutes' => 0, 'price' => 10, 'is_active' => true]);
        $due = CustomerDueService::create(['customer_id' => $customer->id, 'salon_service_id' => $service->id, 'due_date' => today(), 'status' => 'pending']);
        $log = $this->log('due_service_reminder:'.$due->id);
        $log->update(['provider_message_id' => 'receipt-1', 'provider_status' => 'accepted']);
        foreach (['sent', 'delivered', 'delivered', 'accepted', 'failed'] as $status) {
            $this->postJson(route('whatsapp.webhook.receive'), [
                'type' => 'whatsapp.message.updated', 'createTime' => now()->toIso8601String(),
                'whatsappMessage' => ['id' => 'receipt-1', 'status' => $status],
            ])->assertOk();
            if ($status === 'sent') {
                $this->assertNull($due->fresh()->reminder_sent_at);
            }
        }
        $this->assertNotNull($due->fresh()->reminder_sent_at);
        $this->assertSame('delivered', $log->fresh()->provider_status);
        $this->assertNull($log->fresh()->failed_at);
    }

    public function test_reminder_enqueue_is_deduplicated_without_claiming_delivery(): void
    {
        Queue::fake();
        $this->template();
        $customer = $this->customer();
        $service = SalonService::create(['name' => 'Hair', 'duration_minutes' => 30, 'buffer_minutes' => 0, 'price' => 10, 'is_active' => true]);
        $due = CustomerDueService::create(['customer_id' => $customer->id, 'salon_service_id' => $service->id, 'due_date' => today(), 'status' => 'pending']);
        $options = ['async' => true, 'message_type' => 'template', 'template_name' => 'reliable_notice', 'language_code' => 'en_US'];
        $delivery = app(CommunicationDeliveryService::class);
        $first = $delivery->deliver($customer, 'whatsapp', $customer->phone, 'Reminder', 'due_service_reminder:'.$due->id, $options);
        $second = $delivery->deliver($customer, 'whatsapp', $customer->phone, 'Reminder', 'due_service_reminder_auto:'.$due->id, $options);
        $this->assertSame($first->id, $second->id);
        $this->assertNull($due->fresh()->reminder_sent_at);
        Queue::assertPushed(SendWhatsAppDeliveryJob::class, 1);
    }

    public function test_template_creation_requires_examples_and_enough_fixed_words(): void
    {
        Http::fake();
        $role = Role::create(['name' => 'manager', 'label' => 'Manager', 'permissions' => Permissions::defaultsForRole('manager')]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));
        $base = ['name' => 'new_notice', 'language' => 'en_US', 'category' => 'UTILITY', 'header_type' => 'none'];
        $this->post(route('customers.automation.whatsapp-templates.store'), $base + [
            'body_text' => 'Hello {{1}}, thank you for choosing VINA Luxury Beauty Salon.',
        ])->assertSessionHasErrors('example_values');
        $this->post(route('customers.automation.whatsapp-templates.store'), $base + [
            'body_text' => 'Hi {{1}} {{2}}', 'example_values' => 'Customer,Hair',
        ])->assertSessionHasErrors('body_text');
        Http::assertNothingSent();
    }
}
