<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\CampaignTemplate;
use App\Models\CommunicationLog;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_can_delete_campaigns_without_deleting_templates_or_delivery_history(): void
    {
        $role = Role::create(['name' => 'manager', 'label' => 'Manager', 'permissions' => Permissions::defaultsForRole('manager')]);
        $user = User::factory()->create(['role_id' => $role->id]);
        $template = CampaignTemplate::create(['name' => 'Welcome', 'channel' => 'whatsapp', 'content' => 'Welcome']);

        foreach (['draft', 'scheduled', 'sent', 'cancelled'] as $status) {
            $campaign = Campaign::create(['name' => 'Campaign', 'campaign_template_id' => $template->id, 'channel' => 'whatsapp', 'status' => $status]);
            $log = CommunicationLog::create(['channel' => 'whatsapp', 'context' => 'campaign:'.$campaign->id, 'status' => 'sent']);

            $this->actingAs($user)->from(route('customers.automation.index'))
                ->delete(route('customers.automation.campaigns.destroy', $campaign))
                ->assertRedirect(route('customers.automation.index'))->assertSessionHas('status', 'Campaign deleted.');

            $this->assertDatabaseMissing('campaigns', ['id' => $campaign->id]);
            $this->assertDatabaseHas('campaign_templates', ['id' => $template->id]);
            $this->assertDatabaseHas('communication_logs', ['id' => $log->id]);
        }
    }

    public function test_staff_cannot_delete_campaigns(): void
    {
        $role = Role::create(['name' => 'staff', 'label' => 'Staff', 'permissions' => Permissions::defaultsForRole('staff')]);
        $user = User::factory()->create(['role_id' => $role->id]);
        $template = CampaignTemplate::create(['name' => 'Welcome', 'channel' => 'whatsapp', 'content' => 'Welcome']);
        $campaign = Campaign::create(['name' => 'Campaign', 'campaign_template_id' => $template->id, 'channel' => 'whatsapp']);

        $this->actingAs($user)->delete(route('customers.automation.campaigns.destroy', $campaign))->assertForbidden();
        $this->assertDatabaseHas('campaigns', ['id' => $campaign->id]);
    }
}
