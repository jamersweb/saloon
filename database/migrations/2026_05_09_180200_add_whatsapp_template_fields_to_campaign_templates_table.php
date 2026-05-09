<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_templates', function (Blueprint $table) {
            $table->string('whatsapp_message_type', 16)->nullable()->after('content');
            $table->string('whatsapp_template_name')->nullable()->after('whatsapp_message_type');
            $table->string('whatsapp_template_language_code', 16)->nullable()->after('whatsapp_template_name');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_templates', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_message_type',
                'whatsapp_template_name',
                'whatsapp_template_language_code',
            ]);
        });
    }
};
