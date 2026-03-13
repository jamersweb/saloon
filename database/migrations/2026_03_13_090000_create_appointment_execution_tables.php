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
        Schema::create('appointment_service_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('staff_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->text('intake_notes')->nullable();
            $table->text('service_notes')->nullable();
            $table->text('completion_notes')->nullable();
            $table->text('materials_used')->nullable();
            $table->timestamps();
        });

        Schema::create('appointment_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['before', 'after']);
            $table->string('path');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['appointment_id', 'type']);
        });

        Schema::create('appointment_product_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->unsignedInteger('quantity');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_product_usages');
        Schema::dropIfExists('appointment_photos');
        Schema::dropIfExists('appointment_service_logs');
    }
};
