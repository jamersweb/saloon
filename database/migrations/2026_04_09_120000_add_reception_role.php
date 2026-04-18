<?php

use App\Models\Role;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Role::query()->updateOrCreate(
            ['name' => 'reception'],
            [
                'label' => 'Reception',
                'permissions' => Permissions::defaultsForRole('reception'),
            ]
        );
    }

    public function down(): void
    {
        Role::query()->where('name', 'reception')->delete();
    }
};
