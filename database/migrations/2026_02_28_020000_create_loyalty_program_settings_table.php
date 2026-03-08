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
        Schema::create('loyalty_program_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('auto_earn_enabled')->default(true);
            $table->decimal('points_per_currency', 10, 2)->default(1);
            $table->decimal('minimum_spend', 10, 2)->default(0);
            $table->enum('rounding_mode', ['floor', 'round', 'ceil'])->default('floor');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_program_settings');
    }
};

