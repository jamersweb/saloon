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
        Schema::create('communication_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('channel', ['sms', 'email', 'whatsapp'])->default('sms');
            $table->string('context')->nullable();
            $table->string('recipient')->nullable();
            $table->text('message')->nullable();
            $table->enum('status', ['queued', 'sent', 'failed'])->default('sent');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['channel', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('communication_logs');
    }
};
