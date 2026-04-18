<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            ['name' => 'owner', 'label' => 'Owner'],
            ['name' => 'manager', 'label' => 'Manager'],
            ['name' => 'staff', 'label' => 'Staff'],
            ['name' => 'reception', 'label' => 'Reception'],
            ['name' => 'customer', 'label' => 'Customer'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['name' => $role['name']],
                [
                    ...$role,
                    'permissions' => Permissions::defaultsForRole($role['name']),
                ]
            );
        }
    }
}
