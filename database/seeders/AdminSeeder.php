<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Default back-office admin (owner role). Change password after first login in production.
     */
    public function run(): void
    {
        $ownerRoleId = Role::query()->where('name', 'owner')->value('id');

        if (! $ownerRoleId) {
            return;
        }

        User::updateOrCreate(
            ['email' => 'admin@vina.local'],
            [
                'name' => 'Vina Admin',
                'password' => Hash::make('Password@123'),
                'role_id' => (int) $ownerRoleId,
                'email_verified_at' => now(),
            ],
        );
    }
}
