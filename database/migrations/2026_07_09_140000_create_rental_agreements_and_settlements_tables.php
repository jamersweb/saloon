<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_agreements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('partner_name');
            $table->string('agreement_type', 32);
            $table->string('cost_center', 64);
            $table->string('rental_model', 32);
            $table->decimal('fixed_rent_amount', 12, 2)->default(0);
            $table->decimal('commission_percent', 5, 2)->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('rental_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rental_agreement_id')->constrained()->cascadeOnDelete();
            $table->date('settlement_date');
            $table->decimal('gross_sales_amount', 12, 2)->nullable();
            $table->decimal('fixed_rent_amount', 12, 2)->default(0);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->foreignId('tax_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_settlements');
        Schema::dropIfExists('rental_agreements');
    }
};
