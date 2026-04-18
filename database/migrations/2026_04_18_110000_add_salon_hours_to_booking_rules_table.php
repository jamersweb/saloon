<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_rules', function (Blueprint $table) {
            $table->string('opening_time', 5)->default('09:00')->after('slot_interval_minutes');
            $table->string('closing_time', 5)->default('22:00')->after('opening_time');
        });

        DB::table('booking_rules')->update([
            'opening_time' => '09:00',
            'closing_time' => '22:00',
        ]);
    }

    public function down(): void
    {
        Schema::table('booking_rules', function (Blueprint $table) {
            $table->dropColumn(['opening_time', 'closing_time']);
        });
    }
};
