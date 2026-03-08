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
        Schema::create('customer_loyalty_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loyalty_tier_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('current_points')->default(0);
            $table->dateTime('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique('customer_id');
            $table->index(['loyalty_tier_id', 'current_points']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_loyalty_accounts');
    }
};
