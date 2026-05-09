<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_logs', function (Blueprint $table) {
            $table->string('provider_status')->nullable()->after('provider');
            $table->string('message_type', 32)->nullable()->after('provider_status');
            $table->unsignedSmallInteger('attempt_count')->default(0)->after('message_type');
            $table->timestamp('queued_at')->nullable()->after('attempt_count');
            $table->timestamp('accepted_at')->nullable()->after('queued_at');
            $table->timestamp('read_at')->nullable()->after('delivered_at');
            $table->timestamp('failed_at')->nullable()->after('read_at');
            $table->timestamp('last_provider_event_at')->nullable()->after('failed_at');
            $table->json('provider_payload')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('communication_logs', function (Blueprint $table) {
            $table->dropColumn([
                'provider_status',
                'message_type',
                'attempt_count',
                'queued_at',
                'accepted_at',
                'read_at',
                'failed_at',
                'last_provider_event_at',
                'provider_payload',
            ]);
        });
    }
};
