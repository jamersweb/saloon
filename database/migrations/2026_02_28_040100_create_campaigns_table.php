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
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('campaign_template_id')->constrained('campaign_templates')->restrictOnDelete();
            $table->enum('channel', ['sms', 'email', 'whatsapp'])->default('sms');
            $table->enum('audience_type', ['all', 'tag', 'due_service', 'inactivity_days'])->default('all');
            $table->foreignId('customer_tag_id')->nullable()->constrained('customer_tags')->nullOnDelete();
            $table->unsignedSmallInteger('inactivity_days')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->enum('status', ['draft', 'scheduled', 'sent', 'cancelled'])->default('draft');
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};

