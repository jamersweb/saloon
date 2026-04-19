<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Salon team from the staff roster (names + positions). Idempotent via email.
 */
class VinaStaffRosterSeeder extends Seeder
{
    public function run(): void
    {
        $managerRoleId = Role::query()->where('name', 'manager')->value('id');
        $staffRoleId = Role::query()->where('name', 'staff')->value('id');
        $receptionRoleId = Role::query()->where('name', 'reception')->value('id');

        if (! $managerRoleId || ! $staffRoleId || ! $receptionRoleId) {
            $this->command?->warn('Skipping Vina staff roster: roles not found (run RoleSeeder).');

            return;
        }

        $rows = [
            ['Mona Bassagh', 'mona.bassagh@vina.local', 'VINA-01', 'manager', 'Manager / Eyelash technician'],
            ['Sahar Shams', 'sahar.shams@vina.local', 'VINA-02', 'staff', 'Makeup artist / Hair stylist'],
            ['Majd Alabaza', 'majd.alabaza@vina.local', 'VINA-03', 'staff', 'Hair dresser'],
            ['Hengameh Dortaj', 'hengameh.dortaj@vina.local', 'VINA-04', 'staff', 'Nail technician'],
            ['Dulce Aguilar', 'dulce.aguilar@vina.local', 'VINA-05', 'staff', 'Nail technician'],
            ['Jocelyn Caburnay Caquista', 'jocelyn.caquista@vina.local', 'VINA-06', 'staff', 'Nail technician'],
            ['Analisa Rabanal Domenden', 'analisa.domenden@vina.local', 'VINA-07', 'reception', 'Receptionist'],
            ['Jenifer Palisoc Jazmin', 'jenifer.jazmin@vina.local', 'VINA-08', 'staff', 'Hair dresser'],
        ];

        foreach ($rows as $row) {
            $roleId = match ($row[3]) {
                'manager' => (int) $managerRoleId,
                'reception' => (int) $receptionRoleId,
                default => (int) $staffRoleId,
            };

            $user = User::updateOrCreate(
                ['email' => $row[1]],
                [
                    'name' => $row[0],
                    'password' => Hash::make('Password@123'),
                    'role_id' => $roleId,
                    'email_verified_at' => now(),
                ],
            );

            StaffProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'employee_code' => $row[2],
                    'phone' => null,
                    'skills' => [$row[4]],
                    'is_active' => true,
                ],
            );
        }
    }
}
