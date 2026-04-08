<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_units_per_redemption')->nullable()->after('stock_quantity');
            $table->unsignedSmallInteger('max_redemptions_per_calendar_month')->nullable()->after('max_units_per_redemption');
            $table->unsignedSmallInteger('min_days_between_redemptions')->nullable()->after('max_redemptions_per_calendar_month');
            $table->boolean('requires_appointment_id')->default(false)->after('min_days_between_redemptions');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_rewards', function (Blueprint $table) {
            $table->dropColumn([
                'max_units_per_redemption',
                'max_redemptions_per_calendar_month',
                'min_days_between_redemptions',
                'requires_appointment_id',
            ]);
        });
    }
};
