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
        Schema::create('customer_segment_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('customer_tag_id')->constrained('customer_tags')->cascadeOnDelete();
            $table->enum('criteria', ['inactivity_days', 'min_spend', 'min_visits']);
            $table->decimal('threshold_value', 12, 2);
            $table->unsignedInteger('lookback_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'criteria']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_segment_rules');
    }
};
