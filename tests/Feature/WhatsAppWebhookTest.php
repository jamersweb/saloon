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
}
