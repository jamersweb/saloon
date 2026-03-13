<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_logs', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('status');
            $table->string('provider_message_id')->nullable()->after('provider');
            $table->text('error_message')->nullable()->after('provider_message_id');
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('communication_logs', function (Blueprint $table) {
            $table->dropColumn([
                'provider',
                'provider_message_id',
                'error_message',
                'delivered_at',
            ]);
        });
    }
};
