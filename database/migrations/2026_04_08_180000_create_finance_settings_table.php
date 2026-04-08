<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_settings', function (Blueprint $table) {
            $table->id();
            $table->string('business_name')->default('Vina Luxury Beauty Salon');
            $table->string('address_line')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('tax_registration_number')->nullable();
            $table->decimal('vat_rate_percent', 5, 2)->default(5);
            $table->string('invoice_prefix', 16)->default('RCT');
            $table->unsignedInteger('next_invoice_number')->default(1);
            $table->string('currency_code', 3)->default('AED');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_settings');
    }
};
