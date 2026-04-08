<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_reward_salon_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loyalty_reward_id')->constrained('loyalty_rewards')->cascadeOnDelete();
            $table->foreignId('salon_service_id')->constrained('salon_services')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['loyalty_reward_id', 'salon_service_id'], 'lr_ss_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_reward_salon_service');
    }
};
