<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('booking_rules')->update([
            'slot_interval_minutes' => 30,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Intentionally no-op: previous interval values are unknown.
    }
};
