<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_settings', function (Blueprint $table) {
            $table->string('whatsapp_driver', 16)->nullable()->after('currency_code');
            $table->string('whatsapp_base_url')->nullable()->after('whatsapp_driver');
            $table->string('whatsapp_api_version', 16)->nullable()->after('whatsapp_base_url');
            $table->string('whatsapp_phone_number_id')->nullable()->after('whatsapp_api_version');
            $table->text('whatsapp_access_token')->nullable()->after('whatsapp_phone_number_id');
            $table->string('whatsapp_webhook_verify_token')->nullable()->after('whatsapp_access_token');
            $table->string('whatsapp_default_language_code', 16)->nullable()->after('whatsapp_webhook_verify_token');
            $table->string('whatsapp_due_service_template_name')->nullable()->after('whatsapp_default_language_code');
            $table->string('whatsapp_public_booking_template_name')->nullable()->after('whatsapp_due_service_template_name');
            $table->unsignedSmallInteger('whatsapp_rate_limit_per_minute')->nullable()->after('whatsapp_public_booking_template_name');
        });
    }

    public function down(): void
    {
        Schema::table('finance_settings', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_driver',
                'whatsapp_base_url',
                'whatsapp_api_version',
                'whatsapp_phone_number_id',
                'whatsapp_access_token',
                'whatsapp_webhook_verify_token',
                'whatsapp_default_language_code',
                'whatsapp_due_service_template_name',
                'whatsapp_public_booking_template_name',
                'whatsapp_rate_limit_per_minute',
            ]);
        });
    }
};
