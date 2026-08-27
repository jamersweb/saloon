<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_templates', function (Blueprint $table) {
            $table->string('whatsapp_header_type', 16)->nullable()->after('whatsapp_template_language_code');
            $table->string('whatsapp_header_media_url')->nullable()->after('whatsapp_header_type');
            $table->string('whatsapp_header_media_filename')->nullable()->after('whatsapp_header_media_url');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_templates', function (Blueprint $table) {
            $table->dropColumn([
                'whatsapp_header_type',
                'whatsapp_header_media_url',
                'whatsapp_header_media_filename',
            ]);
        });
    }
};
