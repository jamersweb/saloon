<?php

namespace Tests\Feature;

use App\Models\FinanceSetting;
use App\Models\WhatsAppMessageTemplate;
use App\Services\WhatsAppTemplateManagerService;
use Database\Seeders\DueServiceReminderTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DueServiceReminderTemplatesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_is_repeatable_and_preserves_existing_approval(): void
    {
        Http::preventStrayRequests();
        $existing = WhatsAppMessageTemplate::create(['name' => 'due_root_color', 'language' => 'en_US', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'Existing content']], 'template_uid' => 'existing-id']);
        $this->seed(DueServiceReminderTemplatesSeeder::class);
        $this->seed(DueServiceReminderTemplatesSeeder::class);
        $this->assertDatabaseCount('whatsapp_message_templates', 4);
        $this->assertSame('APPROVED', $existing->fresh()->status);
        $this->assertSame('Existing content', $existing->fresh()->components[0]['text']);
        foreach (['threading_waxing', 'eyelash_refill', 'nail_refill'] as $key) {
            $preset = config('due_service_reminders.'.$key);
            $draft = WhatsAppMessageTemplate::where('name', $preset['name'])->firstOrFail();
            $this->assertSame('DRAFT', $draft->status);
            $this->assertSame($preset['body'], $draft->components[0]['text']);
            $this->assertNull($draft->last_synced_at);
        }
    }

    public function test_provider_sync_preserves_drafts_and_promotes_matching_template(): void
    {
        $this->seed(DueServiceReminderTemplatesSeeder::class);
        FinanceSetting::current()->update(['whatsapp_driver' => 'ycloud', 'whatsapp_base_url' => 'https://api.ycloud.com', 'whatsapp_access_token' => 'test', 'whatsapp_business_account_id' => 'test']);
        Http::fake(['api.ycloud.com/*' => Http::response(['items' => [['name' => 'due_root_color', 'language' => 'en_US', 'status' => 'APPROVED']]])]);
        app(WhatsAppTemplateManagerService::class)->syncTemplates();
        $this->assertDatabaseCount('whatsapp_message_templates', 4);
        $this->assertDatabaseHas('whatsapp_message_templates', ['name' => 'due_root_color', 'status' => 'APPROVED']);
        $this->assertSame(3, WhatsAppMessageTemplate::where('status', 'DRAFT')->count());
    }

    public function test_deleting_local_draft_does_not_call_provider(): void
    {
        Http::preventStrayRequests();
        $this->seed(DueServiceReminderTemplatesSeeder::class);
        $draft = WhatsAppMessageTemplate::where('name', 'due_nail_refill')->firstOrFail();
        app(WhatsAppTemplateManagerService::class)->deleteTemplate($draft);
        $this->assertDatabaseMissing('whatsapp_message_templates', ['id' => $draft->id]);
    }
}
