<?php

namespace Tests\Feature;

use App\Models\FinanceSetting;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppMessageTemplate;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppTemplateAutoRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        FinanceSetting::current()->update([
            'whatsapp_driver' => 'ycloud',
            'whatsapp_base_url' => 'https://api.ycloud.com',
            'whatsapp_business_account_id' => 'waba_123',
            'whatsapp_access_token' => 'test-token',
        ]);
    }

    private function loginAsRole(string $name): void
    {
        $role = Role::create(['name' => $name, 'label' => ucfirst($name), 'permissions' => Permissions::defaultsForRole($name)]);
        $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    }

    public function test_refresh_updates_pending_status_and_limits_provider_calls(): void
    {
        $this->loginAsRole('manager');
        $template = WhatsAppMessageTemplate::create(['name' => 'due_service', 'language' => 'en_US', 'status' => 'PENDING']);
        Http::fake(['api.ycloud.com/*' => Http::response(['items' => [['name' => 'due_service', 'language' => 'en_US', 'status' => 'APPROVED']]])]);

        $this->postJson(route('customers.automation.whatsapp-templates.refresh'))->assertOk();
        $this->assertSame('APPROVED', $template->fresh()->status);
        $this->postJson(route('customers.automation.whatsapp-templates.refresh'))->assertOk();
        Http::assertSentCount(1);
        $this->travel(61)->seconds();
        $this->postJson(route('customers.automation.whatsapp-templates.refresh'))->assertOk();
        Http::assertSentCount(2);
    }

    public function test_provider_failure_keeps_saved_status_and_allows_retry(): void
    {
        $this->loginAsRole('manager');
        $template = WhatsAppMessageTemplate::create(['name' => 'due_service', 'language' => 'en_US', 'status' => 'PENDING']);
        Http::fake(['api.ycloud.com/*' => Http::sequence()->push(['error' => ['message' => 'Unavailable']], 503)->push(['items' => [['name' => 'due_service', 'language' => 'en_US', 'status' => 'APPROVED']]])]);
        $this->postJson(route('customers.automation.whatsapp-templates.refresh'))->assertStatus(503);
        $this->assertSame('PENDING', $template->fresh()->status);
        $this->postJson(route('customers.automation.whatsapp-templates.refresh'))->assertOk();
        $this->assertSame('APPROVED', $template->fresh()->status);
    }

    public function test_staff_cannot_trigger_refresh(): void
    {
        $this->loginAsRole('staff');
        Http::fake();
        $this->postJson(route('customers.automation.whatsapp-templates.refresh'))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_scheduled_command_refreshes_templates(): void
    {
        Http::fake(['api.ycloud.com/*' => Http::response(['items' => [['name' => 'due_service', 'language' => 'en_US', 'status' => 'APPROVED']]])]);
        $this->artisan('whatsapp:sync-templates')->assertSuccessful();
        $this->assertDatabaseHas('whatsapp_message_templates', ['name' => 'due_service', 'status' => 'APPROVED']);
    }
}
