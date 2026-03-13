<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\CommunicationLog;
use App\Models\Customer;
use App\Models\CustomerDueService;
use App\Models\Role;
use App\Models\SalonService;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_service_whatsapp_reminder_delivers_through_provider_and_logs_metadata(): void
    {
        config()->set('services.whatsapp.driver', 'meta');
        config()->set('services.whatsapp.phone_number_id', '123456789');
        config()->set('services.whatsapp.token', 'secret-token');
        config()->set('services.whatsapp.version', 'v23.0');

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'messages' => [
                    ['id' => 'wamid.test-123'],
                ],
            ], 200),
        ]);

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

        Http::assertSentCount(1);

        $this->assertDatabaseHas('communication_logs', [
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => 'sent',
            'provider' => 'whatsapp-meta',
            'provider_message_id' => 'wamid.test-123',
        ]);
    }

    public function test_campaign_dispatch_marks_failed_whatsapp_delivery_when_provider_rejects_message(): void
    {
        config()->set('services.whatsapp.driver', 'meta');
        config()->set('services.whatsapp.phone_number_id', '123456789');
        config()->set('services.whatsapp.token', 'secret-token');
        config()->set('services.whatsapp.version', 'v23.0');

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Template not approved.',
                ],
            ], 422),
        ]);

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
        $this->assertSame(1, $campaign->failed_count);

        $log = CommunicationLog::query()->latest()->first();

        $this->assertNotNull($log);
        $this->assertSame('failed', $log->status);
        $this->assertSame('whatsapp-meta', $log->provider);
        $this->assertSame('Template not approved.', $log->error_message);
    }
}
