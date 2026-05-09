<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_settings', function (Blueprint $table) {
            $table->string('whatsapp_business_account_id')->nullable()->after('whatsapp_phone_number_id');
        });
    }

    public function down(): void
    {
        Schema::table('finance_settings', function (Blueprint $table) {
            $table->dropColumn('whatsapp_business_account_id');
        });
    }
};
