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
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_id')->constrained('salon_services')->restrictOnDelete();
            $table->foreignId('staff_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('booked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('source', ['public', 'admin'])->default('admin');
            $table->enum('status', ['pending', 'confirmed', 'in_progress', 'completed', 'cancelled', 'no_show'])->default('confirmed');
            $table->dateTime('scheduled_start');
            $table->dateTime('scheduled_end');
            $table->dateTime('arrival_time')->nullable();
            $table->dateTime('service_start_time')->nullable();
            $table->string('customer_name');
            $table->string('customer_phone');
            $table->string('customer_email')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['staff_profile_id', 'scheduled_start']);
            $table->index(['status', 'scheduled_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
