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
        Schema::create('customer_due_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('salon_service_id')->constrained('salon_services')->cascadeOnDelete();
            $table->foreignId('last_appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->date('due_date');
            $table->enum('status', ['pending', 'booked', 'dismissed'])->default('pending');
            $table->timestamp('reminder_sent_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['due_date', 'status']);
            $table->unique(['customer_id', 'salon_service_id', 'due_date'], 'customer_due_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_due_services');
    }
};
