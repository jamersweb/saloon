<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_uid')->nullable()->unique();
            $table->string('name');
            $table->string('language', 16);
            $table->string('category', 32)->nullable();
            $table->string('status', 32)->nullable();
            $table->string('sub_category', 64)->nullable();
            $table->string('quality_score', 32)->nullable();
            $table->text('rejection_reason')->nullable();
            $table->json('components')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['name', 'language'], 'whatsapp_templates_name_language_unique');
            $table->index(['status', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_message_templates');
    }
};
