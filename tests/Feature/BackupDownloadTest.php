<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_without_backup_permission_cannot_download(): void
    {
        $staffRole = Role::create([
            'name' => 'staff',
            'label' => 'Staff',
            'permissions' => Permissions::defaultsForRole('staff'),
        ]);

        $user = User::factory()->create(['role_id' => $staffRole->id]);

        $this->actingAs($user)
            ->get(route('backup.daily'))
            ->assertForbidden();
    }

    public function test_reception_can_request_backup_route(): void
    {
        $receptionRole = Role::query()->firstWhere('name', 'reception')
            ?? Role::create([
                'name' => 'reception',
                'label' => 'Reception',
                'permissions' => Permissions::defaultsForRole('reception'),
            ]);

        $user = User::factory()->create(['role_id' => $receptionRole->id]);

        $response = $this->actingAs($user)->get(route('backup.daily'));

        $this->assertContains($response->status(), [200, 503]);
    }
}
