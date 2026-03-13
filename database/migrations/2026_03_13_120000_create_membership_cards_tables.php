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
        Schema::create('membership_card_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('kind', ['physical', 'virtual', 'gift'])->default('physical');
            $table->unsignedInteger('min_points')->default(0);
            $table->decimal('direct_purchase_price', 10, 2)->nullable();
            $table->unsignedSmallInteger('validity_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_transferable')->default(false);
            $table->timestamps();
        });

        Schema::create('customer_membership_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('membership_card_type_id')->constrained()->cascadeOnDelete();
            $table->string('card_number')->nullable()->unique();
            $table->string('nfc_uid')->nullable()->unique();
            $table->enum('status', ['pending', 'active', 'inactive', 'expired'])->default('active');
            $table->dateTime('issued_at')->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_membership_cards');
        Schema::dropIfExists('membership_card_types');
    }
};
