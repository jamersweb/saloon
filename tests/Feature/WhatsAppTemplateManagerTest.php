<?php

namespace Tests\Feature;

use App\Models\FinanceSetting;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsAppMessageTemplate;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WhatsAppTemplateManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_sync_meta_templates_into_local_cache(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'data' => [
                    [
                        'id' => 'tmpl_1',
                        'name' => 'hello_world',
                        'language' => 'en_US',
                        'category' => 'UTILITY',
                        'status' => 'APPROVED',
                        'components' => [
                            ['type' => 'BODY', 'text' => 'Hello {{1}}'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        FinanceSetting::current()->update([
            'whatsapp_base_url' => 'https://graph.facebook.com',
            'whatsapp_api_version' => 'v25.0',
            'whatsapp_business_account_id' => 'waba_123',
            'whatsapp_access_token' => 'meta-token',
        ]);

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);

        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $this->actingAs($user)
            ->post(route('customers.automation.whatsapp-templates.sync'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('whatsapp_message_templates', [
            'template_uid' => 'tmpl_1',
            'name' => 'hello_world',
            'language' => 'en_US',
            'status' => 'APPROVED',
        ]);
    }

    public function test_manager_can_sync_ycloud_templates_into_local_cache(): void
    {
        Http::fake([
            'https://api.ycloud.com/*' => Http::response([
                'data' => [
                    [
                        'id' => 'ycloud_tmpl_1',
                        'name' => 'ycloud_welcome',
                        'language' => 'en_US',
                        'category' => 'UTILITY',
                        'status' => 'APPROVED',
                        'components' => [
                            ['type' => 'BODY', 'text' => 'Hello {{1}}'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        FinanceSetting::current()->update([
            'whatsapp_driver' => 'ycloud',
            'whatsapp_base_url' => 'https://graph.facebook.com',
            'whatsapp_business_account_id' => 'waba_123',
            'whatsapp_access_token' => 'ycloud-token',
        ]);

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);

        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $this->actingAs($user)
            ->post(route('customers.automation.whatsapp-templates.sync'))
            ->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => $request->url() === 'https://api.ycloud.com/v2/whatsapp/templates?page=1&limit=100&filter.wabaId=waba_123'
            && $request->hasHeader('X-API-Key', 'ycloud-token'));

        $this->assertDatabaseHas('whatsapp_message_templates', [
            'template_uid' => 'ycloud_tmpl_1',
            'name' => 'ycloud_welcome',
            'language' => 'en_US',
            'status' => 'APPROVED',
        ]);
    }

    public function test_manager_uses_ycloud_auth_when_base_url_is_ycloud_even_if_driver_is_meta(): void
    {
        Http::fake([
            'https://api.ycloud.com/*' => Http::response([
                'data' => [
                    [
                        'id' => 'ycloud_tmpl_mixed_config',
                        'name' => 'mixed_config_welcome',
                        'language' => 'en_US',
                        'category' => 'UTILITY',
                        'status' => 'APPROVED',
                        'components' => [
                            ['type' => 'BODY', 'text' => 'Hello {{1}}'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        FinanceSetting::current()->update([
            'whatsapp_driver' => 'meta',
            'whatsapp_base_url' => 'https://api.ycloud.com',
            'whatsapp_business_account_id' => 'waba_123',
            'whatsapp_access_token' => 'ycloud-token',
        ]);

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);

        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $this->actingAs($user)
            ->post(route('customers.automation.whatsapp-templates.sync'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'YCloud templates synced.');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.ycloud.com/v2/whatsapp/templates?page=1&limit=100&filter.wabaId=waba_123'
            && $request->hasHeader('X-API-Key', 'ycloud-token'));

        $this->assertDatabaseHas('whatsapp_message_templates', [
            'template_uid' => 'ycloud_tmpl_mixed_config',
            'name' => 'mixed_config_welcome',
        ]);
    }

    public function test_manager_can_create_meta_template_and_persist_it_locally(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'data' => [
                        [
                            'id' => 'tmpl_2',
                            'name' => 'due_service_notice',
                            'language' => 'en_US',
                            'category' => 'UTILITY',
                            'status' => 'APPROVED',
                            'components' => [
                                ['type' => 'BODY', 'text' => 'Hello {{1}}, your {{2}} is due on {{3}}.'],
                            ],
                        ],
                    ],
                ], 200);
            }

            return Http::response([
                'id' => 'tmpl_2',
                'status' => 'PENDING',
                'category' => 'UTILITY',
            ], 200);
        });

        FinanceSetting::current()->update([
            'whatsapp_base_url' => 'https://graph.facebook.com',
            'whatsapp_api_version' => 'v25.0',
            'whatsapp_business_account_id' => 'waba_123',
            'whatsapp_access_token' => 'meta-token',
        ]);

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);

        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $this->actingAs($user)
            ->post(route('customers.automation.whatsapp-templates.store'), [
                'name' => 'due_service_notice',
                'language' => 'en_US',
                'category' => 'UTILITY',
                'header_type' => 'text',
                'header_text' => 'Reminder',
                'header_example' => 'June',
                'body_text' => 'Hello {{1}}, your {{2}} is due on {{3}}.',
                'footer_text' => 'Reply to book',
                'example_values' => 'Sara,Hair Spa,2026-06-01',
                'buttons' => [
                    ['type' => 'QUICK_REPLY', 'text' => 'Book now'],
                    ['type' => 'URL', 'text' => 'Open site', 'url' => 'https://example.com/book'],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Meta template submitted and synced.');

        $template = WhatsAppMessageTemplate::query()->where('name', 'due_service_notice')->first();

        $this->assertNotNull($template);
        $this->assertSame('APPROVED', $template->status);
        $this->assertSame('en_US', $template->language);
        $this->assertSame('UTILITY', $template->category);
        $this->assertNotEmpty($template->components);

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://graph.facebook.com/v25.0/waba_123/message_templates?limit=100');
    }

    public function test_manager_can_create_ycloud_template_and_persist_it_locally(): void
    {
        Http::fake([
            'https://api.ycloud.com/*' => Http::response([
                'id' => 'ycloud_tmpl_2',
                'status' => 'PENDING',
                'category' => 'MARKETING',
            ], 200),
        ]);

        FinanceSetting::current()->update([
            'whatsapp_driver' => 'ycloud',
            'whatsapp_base_url' => 'https://graph.facebook.com',
            'whatsapp_business_account_id' => 'waba_123',
            'whatsapp_access_token' => 'ycloud-token',
        ]);

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);

        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $this->actingAs($user)
            ->post(route('customers.automation.whatsapp-templates.store'), [
                'name' => 'ycloud_promo',
                'language' => 'en_US',
                'category' => 'MARKETING',
                'header_type' => 'image',
                'header_media_handle' => 'https://example.com/banner.jpg',
                'body_text' => 'Hello {{1}}, view our latest offer.',
                'example_values' => 'Sara',
                'buttons' => [
                    ['type' => 'QUICK_REPLY', 'text' => 'Interested'],
                ],
            ])
            ->assertSessionHasNoErrors();

        Http::assertSent(function ($request) {
            $components = $request['components'] ?? [];
            $header = collect($components)->firstWhere('type', 'HEADER');

            return $request->method() === 'POST'
                && $request->url() === 'https://api.ycloud.com/v2/whatsapp/templates'
                && $request->hasHeader('X-API-Key', 'ycloud-token')
                && $request['wabaId'] === 'waba_123'
                && $request['name'] === 'ycloud_promo'
                && $request['category'] === 'MARKETING'
                && ($header['example']['header_url'][0] ?? null) === 'https://example.com/banner.jpg';
        });

        $this->assertDatabaseHas('whatsapp_message_templates', [
            'template_uid' => 'ycloud_tmpl_2',
            'name' => 'ycloud_promo',
            'language' => 'en_US',
            'category' => 'MARKETING',
        ]);
    }

    public function test_manager_can_upload_ycloud_header_media_to_public_storage(): void
    {
        Storage::fake('public');

        FinanceSetting::current()->update([
            'whatsapp_driver' => 'ycloud',
            'whatsapp_base_url' => 'https://api.ycloud.com',
        ]);

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);

        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $response = $this->actingAs($user)
            ->post(route('customers.automation.whatsapp-templates.header-media'), [
                'header_type' => 'document',
                'header_media_file' => UploadedFile::fake()->create('offer.pdf', 100, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('whatsapp_header_media_handle')
            ->assertSessionHas('whatsapp_header_media_filename', 'offer.pdf');

        $url = (string) $response->baseResponse->getSession()->get('whatsapp_header_media_handle');
        $path = str($url)->after('/storage/')->toString();

        $this->assertStringStartsWith('http://localhost/storage/crm-campaign-media/', $url);
        Storage::disk('public')->assertExists($path);
    }

    public function test_manager_can_upload_media_header_sample_to_meta(): void
    {
        Http::fake([
            'https://graph.facebook.com/v25.0/app/uploads' => Http::response([
                'id' => 'upload:session_123',
            ], 200),
            'https://graph.facebook.com/v25.0/upload:session_123' => Http::response([
                'h' => '4:sample-media-handle',
            ], 200),
        ]);

        FinanceSetting::current()->update([
            'whatsapp_base_url' => 'https://graph.facebook.com',
            'whatsapp_api_version' => 'v25.0',
            'whatsapp_business_account_id' => 'waba_123',
            'whatsapp_access_token' => 'meta-token',
        ]);

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);

        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $this->actingAs($user)
            ->post(route('customers.automation.whatsapp-templates.header-media'), [
                'header_type' => 'image',
                'header_media_file' => UploadedFile::fake()->image('banner.jpg'),
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('whatsapp_header_media_handle', '4:sample-media-handle');

        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v25.0/app/uploads'
            && $request['file_name'] === 'banner.jpg'
            && $request['file_type'] === 'image/jpeg');

        Http::assertSent(fn ($request) => $request->url() === 'https://graph.facebook.com/v25.0/upload:session_123'
            && $request->hasHeader('file_offset', '0'));
    }

    public function test_manager_can_create_meta_template_with_media_header_handle(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'id' => 'tmpl_media',
                'status' => 'PENDING',
                'category' => 'MARKETING',
            ], 200),
        ]);

        FinanceSetting::current()->update([
            'whatsapp_base_url' => 'https://graph.facebook.com',
            'whatsapp_api_version' => 'v25.0',
            'whatsapp_business_account_id' => 'waba_123',
            'whatsapp_access_token' => 'meta-token',
        ]);

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);

        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $this->actingAs($user)
            ->post(route('customers.automation.whatsapp-templates.store'), [
                'name' => 'promo_banner_template',
                'language' => 'en_US',
                'category' => 'MARKETING',
                'header_type' => 'image',
                'header_media_handle' => '4:sample-media-handle',
                'body_text' => 'Hello {{1}}, view our latest offer.',
                'example_values' => 'Sara',
                'buttons' => [],
            ])
            ->assertSessionHasNoErrors();

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || $request->url() !== 'https://graph.facebook.com/v25.0/waba_123/message_templates') {
                return false;
            }

            $components = $request['components'] ?? [];
            $header = collect($components)->firstWhere('type', 'HEADER');

            return ($header['format'] ?? null) === 'IMAGE'
                && ($header['example']['header_handle'][0] ?? null) === '4:sample-media-handle';
        });

        $this->assertDatabaseHas('whatsapp_message_templates', [
            'name' => 'promo_banner_template',
            'language' => 'en_US',
            'category' => 'MARKETING',
        ]);
    }

    public function test_manager_can_replace_existing_meta_template(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::sequence()
                ->push(['success' => true], 200)
                ->push([
                    'id' => 'tmpl_replaced',
                    'status' => 'PENDING',
                    'category' => 'MARKETING',
                ], 200)
                ->push([
                    'data' => [
                        [
                            'id' => 'tmpl_replaced',
                            'name' => 'promo_template',
                            'language' => 'en_US',
                            'category' => 'MARKETING',
                            'status' => 'APPROVED',
                            'components' => [
                                ['type' => 'BODY', 'text' => 'New promo for {{1}}'],
                            ],
                        ],
                    ],
                ], 200),
        ]);

        FinanceSetting::current()->update([
            'whatsapp_base_url' => 'https://graph.facebook.com',
            'whatsapp_api_version' => 'v25.0',
            'whatsapp_business_account_id' => 'waba_123',
            'whatsapp_access_token' => 'meta-token',
        ]);

        $existing = WhatsAppMessageTemplate::query()->create([
            'template_uid' => 'tmpl_old',
            'name' => 'promo_template',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Old body']],
        ]);

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);

        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $this->actingAs($user)
            ->put(route('customers.automation.whatsapp-templates.update', $existing), [
                'name' => 'promo_template',
                'language' => 'en_US',
                'category' => 'MARKETING',
                'header_type' => 'none',
                'body_text' => 'New promo for {{1}}',
                'example_values' => 'Sara',
                'buttons' => [],
            ])
            ->assertSessionHasNoErrors();

        $existing->refresh();
        $this->assertSame('tmpl_replaced', $existing->template_uid);
        $this->assertSame('MARKETING', $existing->category);
    }

    public function test_manager_can_edit_existing_ycloud_template_with_patch(): void
    {
        Http::fake([
            'https://api.ycloud.com/*' => Http::response([
                'id' => 'ycloud_existing',
                'status' => 'APPROVED',
                'category' => 'UTILITY',
            ], 200),
        ]);

        FinanceSetting::current()->update([
            'whatsapp_driver' => 'ycloud',
            'whatsapp_base_url' => 'https://graph.facebook.com',
            'whatsapp_business_account_id' => 'waba_123',
            'whatsapp_access_token' => 'ycloud-token',
        ]);

        $existing = WhatsAppMessageTemplate::query()->create([
            'template_uid' => 'ycloud_existing',
            'name' => 'booking_reminder',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Old body']],
        ]);

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);

        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $this->actingAs($user)
            ->put(route('customers.automation.whatsapp-templates.update', $existing), [
                'name' => 'booking_reminder',
                'language' => 'en_US',
                'category' => 'UTILITY',
                'header_type' => 'none',
                'body_text' => 'Hello {{1}}, your booking is tomorrow.',
                'example_values' => 'Sara',
                'buttons' => [],
            ])
            ->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && $request->url() === 'https://api.ycloud.com/v2/whatsapp/templates/waba_123/booking_reminder/en_US'
            && $request->hasHeader('X-API-Key', 'ycloud-token')
            && ($request['components'][0]['text'] ?? null) === 'Hello {{1}}, your booking is tomorrow.');

        $this->assertDatabaseHas('whatsapp_message_templates', [
            'id' => $existing->id,
            'name' => 'booking_reminder',
            'language' => 'en_US',
            'status' => 'APPROVED',
        ]);
    }

    public function test_manager_can_delete_meta_template_and_remove_local_cache(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => Http::response(['success' => true], 200),
        ]);

        FinanceSetting::current()->update([
            'whatsapp_base_url' => 'https://graph.facebook.com',
            'whatsapp_api_version' => 'v25.0',
            'whatsapp_business_account_id' => 'waba_123',
            'whatsapp_access_token' => 'meta-token',
        ]);

        $template = WhatsAppMessageTemplate::query()->create([
            'template_uid' => 'tmpl_delete',
            'name' => 'delete_me',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Delete me']],
        ]);

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);

        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $this->actingAs($user)
            ->delete(route('customers.automation.whatsapp-templates.destroy', $template))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('whatsapp_message_templates', [
            'id' => $template->id,
        ]);
    }

    public function test_manager_can_delete_ycloud_template_and_remove_local_cache(): void
    {
        Http::fake([
            'https://api.ycloud.com/*' => Http::response(['success' => true], 200),
        ]);

        FinanceSetting::current()->update([
            'whatsapp_driver' => 'ycloud',
            'whatsapp_base_url' => 'https://graph.facebook.com',
            'whatsapp_business_account_id' => 'waba_123',
            'whatsapp_access_token' => 'ycloud-token',
        ]);

        $template = WhatsAppMessageTemplate::query()->create([
            'template_uid' => 'ycloud_delete',
            'name' => 'delete_ycloud',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'status' => 'APPROVED',
            'components' => [['type' => 'BODY', 'text' => 'Delete me']],
        ]);

        $managerRole = Role::create([
            'name' => 'manager',
            'label' => 'Manager',
            'permissions' => Permissions::defaultsForRole('manager'),
        ]);

        $user = User::factory()->create(['role_id' => $managerRole->id]);

        $this->actingAs($user)
            ->delete(route('customers.automation.whatsapp-templates.destroy', $template))
            ->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://api.ycloud.com/v2/whatsapp/templates/waba_123/delete_ycloud/en_US'
            && $request->hasHeader('X-API-Key', 'ycloud-token'));

        $this->assertDatabaseMissing('whatsapp_message_templates', [
            'id' => $template->id,
        ]);
    }
}
