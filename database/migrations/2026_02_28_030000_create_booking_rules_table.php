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
        Schema::create('booking_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('slot_interval_minutes')->default(15);
            $table->unsignedSmallInteger('min_advance_minutes')->default(30);
            $table->unsignedSmallInteger('max_advance_days')->default(60);
            $table->boolean('public_requires_approval')->default(true);
            $table->boolean('allow_customer_cancellation')->default(true);
            $table->unsignedSmallInteger('cancellation_cutoff_hours')->default(12);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_rules');
    }
};

