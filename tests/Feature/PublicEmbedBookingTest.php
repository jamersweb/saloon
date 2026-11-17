<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicEmbedBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_privacy_policy_page_renders(): void
    {
        $this->get(route('public.privacy-policy'))->assertOk()->assertSee('Privacy Policy')->assertSee('WhatsApp Business API');
    }

    public function test_public_terms_of_service_page_renders(): void
    {
        $this->get(route('public.terms-of-service'))->assertOk()->assertSee('Terms of Service')->assertSee('WhatsApp Business');
    }

    public function test_embed_uses_new_checkout_and_external_tab_fallback(): void
    {
        $this->get(route('embed.booking'))->assertOk()->assertSee('Book your appointment')
            ->assertSee('Deposit today (15%)')->assertSee('Open booking in a new tab')->assertSee('csrf-token');
    }

    public function test_embed_legacy_submission_cannot_bypass_deposit(): void
    {
        $this->postJson(route('embed.booking.store'), ['customer_name' => 'Guest', 'service_id' => 1, 'scheduled_start' => '2026-11-13 13:00'])
            ->assertUnprocessable()->assertJsonValidationErrors(['service_ids', 'start', 'staff_id']);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('public_reservations', 0);
    }
}
