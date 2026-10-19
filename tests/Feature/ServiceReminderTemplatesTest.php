<?php

namespace Tests\Feature;

use App\Models\{Customer, CustomerDueService, SalonService, WhatsAppMessageTemplate, FinanceSetting, Role, User, CommunicationLog};
use App\Services\DueServiceReminderTemplate;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ServiceReminderTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private function due(?string $key): CustomerDueService
    {
        $customer = Customer::create(['customer_code' => uniqid('DUE'), 'name' => 'Sara', 'phone' => '+971501234567', 'is_active' => true]);
        $service = SalonService::create(['name' => 'Treatment', 'price' => 100, 'duration_minutes' => 30, 'due_reminder_key' => $key]);
        return CustomerDueService::create(['customer_id' => $customer->id, 'salon_service_id' => $service->id, 'due_date' => today(), 'status' => 'pending']);
    }

    private function manager(): void
    {
        $role = Role::create(['name' => 'manager', 'label' => 'Manager', 'permissions' => Permissions::defaultsForRole('manager')]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    }

    public function test_all_four_messages_use_correct_template_and_one_customer_variable(): void
    {
        foreach (config('due_service_reminders') as $key => $preset) {
            WhatsAppMessageTemplate::create(['name' => $preset['name'], 'language' => 'en_US', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => $preset['body']]]]);
            $due = $this->due($key);
            $options = app(DueServiceReminderTemplate::class)->options($due);
            $this->assertSame($preset['name'], $options['template_name']);
            $this->assertSame([['type' => 'text', 'text' => 'Sara']], $options['components'][0]['parameters']);
            $this->assertStringContainsString('Good day, Sara', app(DueServiceReminderTemplate::class)->message($due));
        }
    }

    public function test_default_template_supports_one_or_three_variables(): void
    {
        FinanceSetting::current()->update(['whatsapp_due_service_template_name' => 'due_service', 'whatsapp_default_language_code' => 'en_US']);
        $template = WhatsAppMessageTemplate::create(['name' => 'due_service', 'language' => 'en_US', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Hello {{1}}']]]);
        $due = $this->due(null);
        $this->assertCount(1, app(DueServiceReminderTemplate::class)->options($due)['components'][0]['parameters']);
        $template->update(['components' => [['type' => 'BODY', 'text' => 'Hello {{1}}, {{2}} is due {{3}}']]]);
        $this->assertCount(3, app(DueServiceReminderTemplate::class)->options($due)['components'][0]['parameters']);
    }

    public function test_pending_mapped_template_does_not_send_or_fall_back(): void
    {
        Queue::fake();
        $due = $this->due('root_color');
        WhatsAppMessageTemplate::create(['name' => 'due_root_color', 'language' => 'en_US', 'status' => 'PENDING']);
        $this->artisan('app:send-due-service-reminders')->assertSuccessful();
        $this->assertDatabaseCount('communication_logs', 0);
        Queue::assertNothingPushed();
        $this->assertNull($due->fresh()->reminder_sent_at);
    }

    public function test_manual_and_scheduled_reminders_share_mapping_and_do_not_duplicate(): void
    {
        Queue::fake();
        $this->manager();
        $preset = config('due_service_reminders.nail_refill');
        WhatsAppMessageTemplate::create(['name' => $preset['name'], 'language' => 'en_US', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => $preset['body']]]]);
        $due = $this->due('nail_refill');
        $this->artisan('app:send-due-service-reminders')->assertSuccessful();
        $log = CommunicationLog::firstOrFail();
        $this->assertSame('queued', $log->status);
        $this->assertStringContainsString('nail refill', $log->message);
        $this->post(route('customers.automation.due-services.remind', $due), ['channel' => 'whatsapp'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('communication_logs', 1);
    }

    public function test_service_mapping_is_saved_and_invalid_mapping_rejected(): void
    {
        $this->manager();
        $due = $this->due(null);
        $data = ['name' => 'Root Color', 'duration_minutes' => 30, 'price' => 100, 'repeat_after_days' => 30, 'due_reminder_key' => 'root_color'];
        $this->put(route('services.update', $due->salon_service_id), $data)->assertSessionHasNoErrors();
        $this->assertSame('root_color', $due->service()->first()->due_reminder_key);
        $this->put(route('services.update', $due->salon_service_id), [...$data, 'due_reminder_key' => 'unknown'])->assertSessionHasErrors('due_reminder_key');
        $this->getJson(route('customers.automation.whatsapp-templates.preset', 'root_color'))->assertOk()->assertJson(['template' => null]);
    }
}
