<?php

namespace Tests\Feature;

use App\Models\CommunicationLog;
use App\Models\Customer;
use App\Models\FinanceSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_whatsapp_webhook_verification_returns_challenge_for_valid_token(): void
    {
        FinanceSetting::current()->update([
            'whatsapp_webhook_verify_token' => 'verify-me',
        ]);

        $this->get('/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=verify-me&hub_challenge=12345')
            ->assertOk()
            ->assertSee('12345');
    }

    public function test_whatsapp_webhook_updates_delivery_lifecycle_fields(): void
    {
        $customer = Customer::create([
            'customer_code' => 'CUST-WA-WEBHOOK',
            'name' => 'Webhook Customer',
            'phone' => '923473639710',
            'is_active' => true,
        ]);

        $log = CommunicationLog::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'context' => 'campaign:12',
            'recipient' => '923473639710',
            'message' => 'Hello',
            'status' => 'sent',
            'provider' => 'whatsapp-meta',
            'provider_status' => 'accepted',
            'message_type' => 'text',
            'provider_message_id' => 'wamid.test-webhook-1',
            'accepted_at' => now(),
        ]);

        $this->postJson(route('whatsapp.webhook.receive'), [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'statuses' => [
                                    [
                                        'id' => 'wamid.test-webhook-1',
                                        'status' => 'delivered',
                                        'timestamp' => (string) now()->timestamp,
                                    ],
                                    [
                                        'id' => 'wamid.test-webhook-1',
                                        'status' => 'read',
                                        'timestamp' => (string) now()->addMinute()->timestamp,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])->assertOk();

        $log->refresh();

        $this->assertSame('read', $log->provider_status);
        $this->assertNotNull($log->delivered_at);
        $this->assertNotNull($log->read_at);
        $this->assertSame('sent', $log->status);
    }

    public function test_whatsapp_webhook_updates_delivery_from_ycloud_payload(): void
    {
        $customer = Customer::create([
            'customer_code' => 'CUST-WA-YCLOUD',
            'name' => 'YCloud Webhook Customer',
            'phone' => '+971502222222',
            'is_active' => true,
        ]);

        $log = CommunicationLog::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'context' => 'campaign:15',
            'recipient' => '+971502222222',
            'message' => 'Hello',
            'status' => 'sent',
            'provider' => 'whatsapp-ycloud',
            'provider_status' => 'accepted',
            'message_type' => 'text',
            'provider_message_id' => 'ycloud-message-1',
            'accepted_at' => now(),
        ]);

        $this->postJson(route('whatsapp.webhook.receive'), [
            'id' => 'evt_ycloud_read',
            'type' => 'whatsapp.message.updated',
            'apiVersion' => 'v2',
            'createTime' => '2026-07-30T10:00:00.000Z',
            'whatsappMessage' => [
                'id' => 'ycloud-message-1',
                'wamid' => 'wamid.example',
                'status' => 'read',
                'createTime' => '2026-07-30T09:59:55.000Z',
                'sendTime' => '2026-07-30T10:00:01.000Z',
                'deliverTime' => '2026-07-30T10:00:02.000Z',
                'readTime' => '2026-07-30T10:00:03.000Z',
            ],
        ])->assertOk();

        $log->refresh();

        $this->assertSame('read', $log->provider_status);
        $this->assertNotNull($log->read_at);
        $this->assertSame('sent', $log->status);
        $this->assertSame('evt_ycloud_read', $log->provider_payload['webhook']['id']);
    }

    public function test_whatsapp_webhook_records_ycloud_failure_message(): void
    {
        $customer = Customer::create([
            'customer_code' => 'CUST-WA-YCLOUD-FAIL',
            'name' => 'YCloud Failed Customer',
            'phone' => '+971502222222',
            'is_active' => true,
        ]);

        $log = CommunicationLog::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'context' => 'campaign:16',
            'recipient' => '+971502222222',
            'message' => 'Hello',
            'status' => 'sent',
            'provider' => 'whatsapp-ycloud',
            'provider_status' => 'accepted',
            'message_type' => 'text',
            'provider_message_id' => 'ycloud-message-failed',
            'accepted_at' => now(),
        ]);

        $this->postJson(route('whatsapp.webhook.receive'), [
            'id' => 'evt_ycloud_failed',
            'type' => 'whatsapp.message.updated',
            'apiVersion' => 'v2',
            'createTime' => '2026-07-30T10:00:00.000Z',
            'whatsappMessage' => [
                'id' => 'ycloud-message-failed',
                'status' => 'failed',
                'errorCode' => '100',
                'errorMessage' => 'Parameter Invalid',
            ],
        ])->assertOk();

        $log->refresh();

        $this->assertSame('failed', $log->provider_status);
        $this->assertSame('failed', $log->status);
        $this->assertSame('100 Parameter Invalid', $log->error_message);
        $this->assertNotNull($log->failed_at);
    }

    public function test_whatsapp_webhook_records_meta_error_code_and_details(): void
    {
        $customer = Customer::create([
            'customer_code' => 'CUST-WA-META-FAIL',
            'name' => 'Meta Failed Customer',
            'phone' => '923473639710',
            'is_active' => true,
        ]);

        $log = CommunicationLog::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'context' => 'single_message:25',
            'recipient' => '923473639710',
            'message' => 'Hello',
            'status' => 'sent',
            'provider' => 'whatsapp-meta',
            'provider_status' => 'accepted',
            'message_type' => 'text',
            'provider_message_id' => 'wamid.meta-failed',
            'accepted_at' => now(),
        ]);

        $this->postJson(route('whatsapp.webhook.receive'), [
            'entry' => [
                [
                    'changes' => [
                        [
                            'value' => [
                                'statuses' => [
                                    [
                                        'id' => 'wamid.meta-failed',
                                        'status' => 'failed',
                                        'timestamp' => (string) now()->timestamp,
                                        'errors' => [
                                            [
                                                'code' => 131047,
                                                'title' => 'Re-engagement message',
                                                'message' => 'Re-engagement message',
                                                'error_data' => [
                                                    'details' => 'Message failed to send because more than 24 hours have passed since the customer last replied to this number.',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ])->assertOk();

        $log->refresh();

        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('131047', $log->error_message);
        $this->assertStringContainsString('24 hours', $log->error_message);
        $this->assertNotNull($log->failed_at);
    }
}
