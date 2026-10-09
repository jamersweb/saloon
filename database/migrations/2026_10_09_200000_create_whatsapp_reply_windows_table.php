<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_reply_windows', function (Blueprint $table) {
            $table->string('sender', 32);
            $table->string('recipient', 32);
            $table->timestamp('last_inbound_at');
            $table->primary(['sender', 'recipient']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_reply_windows');
    }
};
