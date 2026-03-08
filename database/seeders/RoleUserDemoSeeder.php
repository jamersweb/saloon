<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RoleUserDemoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $managerRoleId = Role::query()->where('name', 'manager')->value('id');
        $staffRoleId = Role::query()->where('name', 'staff')->value('id');
        $customerRoleId = Role::query()->where('name', 'customer')->value('id');

        if (! $managerRoleId || ! $staffRoleId || ! $customerRoleId) {
            return;
        }

        $this->seedManagers((int) $managerRoleId);
        $this->seedStaff((int) $staffRoleId);
        $this->seedCustomers((int) $customerRoleId);
    }

    private function seedManagers(int $roleId): void
    {
        $managers = [
            ['name' => 'Amina Tariq', 'email' => 'manager1@vina.local'],
            ['name' => 'Hassan Ali', 'email' => 'manager2@vina.local'],
            ['name' => 'Noor Fatima', 'email' => 'manager3@vina.local'],
        ];

        foreach ($managers as $manager) {
            User::updateOrCreate(
                ['email' => $manager['email']],
                [
                    'name' => $manager['name'],
                    'password' => Hash::make('Password@123'),
                    'role_id' => $roleId,
                    'email_verified_at' => now(),
                ]
            );
        }
    }

    private function seedStaff(int $roleId): void
    {
        $staffMembers = [
            ['name' => 'Sara Khan', 'email' => 'staff1@vina.local', 'employee_code' => 'EMP-101', 'phone' => '5558101001', 'skills' => ['Haircut', 'Styling']],
            ['name' => 'Bilal Ahmed', 'email' => 'staff2@vina.local', 'employee_code' => 'EMP-102', 'phone' => '5558101002', 'skills' => ['Color', 'Treatment']],
            ['name' => 'Mariam Yousaf', 'email' => 'staff3@vina.local', 'employee_code' => 'EMP-103', 'phone' => '5558101003', 'skills' => ['Facial', 'Makeup']],
            ['name' => 'Usman Raza', 'email' => 'staff4@vina.local', 'employee_code' => 'EMP-104', 'phone' => '5558101004', 'skills' => ['Nail Art', 'Pedicure']],
            ['name' => 'Areeba Saleem', 'email' => 'staff5@vina.local', 'employee_code' => 'EMP-105', 'phone' => '5558101005', 'skills' => ['Massage', 'Spa']],
            ['name' => 'Hamza Shah', 'email' => 'staff6@vina.local', 'employee_code' => 'EMP-106', 'phone' => '5558101006', 'skills' => ['Haircut', 'Shave']],
        ];

        foreach ($staffMembers as $staff) {
            $user = User::updateOrCreate(
                ['email' => $staff['email']],
                [
                    'name' => $staff['name'],
                    'password' => Hash::make('Password@123'),
                    'role_id' => $roleId,
                    'email_verified_at' => now(),
                ]
            );

            StaffProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'employee_code' => $staff['employee_code'],
                    'phone' => $staff['phone'],
                    'skills' => $staff['skills'],
                    'is_active' => true,
                ]
            );
        }
    }

    private function seedCustomers(int $roleId): void
    {
        $customers = [
            ['name' => 'Aliya Noor', 'email' => 'customer1@vina.local', 'phone' => '5559102001'],
            ['name' => 'Farhan Akhtar', 'email' => 'customer2@vina.local', 'phone' => '5559102002'],
            ['name' => 'Rida Aslam', 'email' => 'customer3@vina.local', 'phone' => '5559102003'],
            ['name' => 'Imran Bashir', 'email' => 'customer4@vina.local', 'phone' => '5559102004'],
            ['name' => 'Sana Javed', 'email' => 'customer5@vina.local', 'phone' => '5559102005'],
            ['name' => 'Omar Hamid', 'email' => 'customer6@vina.local', 'phone' => '5559102006'],
        ];

        foreach ($customers as $index => $customer) {
            User::updateOrCreate(
                ['email' => $customer['email']],
                [
                    'name' => $customer['name'],
                    'password' => Hash::make('Password@123'),
                    'role_id' => $roleId,
                    'email_verified_at' => now(),
                ]
            );

            Customer::updateOrCreate(
                ['phone' => $customer['phone']],
                [
                    'customer_code' => 'CUST-ROLE-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
                    'name' => $customer['name'],
                    'email' => $customer['email'],
                    'acquisition_source' => 'role_user_seeder',
                    'is_active' => true,
                ]
            );
        }
    }
}

