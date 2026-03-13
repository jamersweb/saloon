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
        Schema::create('service_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->decimal('initial_value', 10, 2)->nullable();
            $table->unsignedSmallInteger('validity_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('customer_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_package_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('remaining_sessions')->nullable();
            $table->decimal('remaining_value', 10, 2)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->enum('status', ['active', 'completed', 'expired', 'inactive'])->default('active');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });

        Schema::create('customer_package_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('salon_service_id')->nullable()->constrained('salon_services')->nullOnDelete();
            $table->unsignedInteger('sessions_used')->default(0);
            $table->decimal('value_used', 10, 2)->default(0);
            $table->unsignedInteger('remaining_sessions_after')->nullable();
            $table->decimal('remaining_value_after', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('assigned_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->decimal('initial_value', 10, 2);
            $table->decimal('remaining_value', 10, 2);
            $table->dateTime('expires_at')->nullable();
            $table->enum('status', ['active', 'redeemed', 'expired', 'inactive'])->default('active');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('gift_card_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gift_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount_change', 10, 2);
            $table->decimal('balance_after', 10, 2);
            $table->string('reason');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gift_card_transactions');
        Schema::dropIfExists('gift_cards');
        Schema::dropIfExists('customer_package_usages');
        Schema::dropIfExists('customer_packages');
        Schema::dropIfExists('service_packages');
    }
};
