<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('loyalty_tiers', function (Blueprint $table) {
            $table->decimal('earn_multiplier', 4, 2)->default(1)->after('discount_percent');
        });

        Schema::table('loyalty_program_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('points_per_visit')->default(0)->after('points_per_currency');
            $table->unsignedSmallInteger('birthday_bonus_points')->default(0)->after('points_per_visit');
            $table->unsignedSmallInteger('referral_bonus_points')->default(0)->after('birthday_bonus_points');
            $table->unsignedSmallInteger('review_bonus_points')->default(0)->after('referral_bonus_points');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loyalty_program_settings', function (Blueprint $table) {
            $table->dropColumn(['points_per_visit', 'birthday_bonus_points', 'referral_bonus_points', 'review_bonus_points']);
        });

        Schema::table('loyalty_tiers', function (Blueprint $table) {
            $table->dropColumn('earn_multiplier');
        });
    }
};

