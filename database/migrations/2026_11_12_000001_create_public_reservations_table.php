<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_reservations', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->foreignId('staff_profile_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('awaiting_payment');
            $table->json('services');
            $table->unsignedInteger('total_minor');
            $table->unsignedInteger('deposit_minor');
            $table->unsignedInteger('duration_minutes');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('original_starts_at');
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_phone', 30);
            $table->string('checkout_id')->nullable()->unique();
            $table->text('checkout_url')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('requested_start')->nullable();
            $table->foreignId('requested_staff_id')->nullable()->constrained('staff_profiles')->restrictOnDelete();
            $table->timestamps();
            $table->index(['staff_profile_id', 'status', 'starts_at', 'ends_at'], 'public_reservations_availability');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_reservations');
    }
};
