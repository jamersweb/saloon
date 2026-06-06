<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->decimal('service_unit_price', 12, 2)->nullable()->after('service_quantity');
            $table->decimal('service_discount_amount', 12, 2)->default(0)->after('service_unit_price');
            $table->unsignedInteger('service_duration_minutes')->nullable()->after('service_discount_amount');
            $table->unsignedInteger('service_extra_minutes')->default(0)->after('service_duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn([
                'service_unit_price',
                'service_discount_amount',
                'service_duration_minutes',
                'service_extra_minutes',
            ]);
        });
    }
};
