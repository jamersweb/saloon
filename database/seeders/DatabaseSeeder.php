<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        $this->call(MembershipCardTypesSeeder::class);
        $this->call(VinaMembershipSeriesSeeder::class);
        $this->call(LoyaltyProgramPdfSeeder::class);
        $this->call(AdminSeeder::class);

        $ownerRole = Role::query()->where('name', 'owner')->firstOrFail();

        User::updateOrCreate(
            ['email' => 'owner@saloon.local'],
            [
                'name' => 'Vina Owner',
                'password' => Hash::make('Password@123'),
                'role_id' => $ownerRole->id,
                'email_verified_at' => now(),
            ],
        );

        $this->call(RoleUserDemoSeeder::class);
        $this->call(VinaStaffRosterSeeder::class);
        $this->call(VinaMembershipRosterSeeder::class);
        $this->call(CatalogImportSeeder::class);
        $this->call(LakmePriceListPdfSeeder::class);
        $this->call(AppointmentDemoSeeder::class);
        $this->call(InventoryLoyaltyDemoSeeder::class);
    }
}
