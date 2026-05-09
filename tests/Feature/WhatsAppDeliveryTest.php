<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppDeliveryJob;
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
use Illuminate\Support\Facades\Queue;
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
}
