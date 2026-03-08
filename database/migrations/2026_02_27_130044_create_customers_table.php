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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_code')->nullable()->unique();
            $table->string('name');
            $table->string('phone')->index();
            $table->string('email')->nullable();
            $table->date('birthday')->nullable();
            $table->text('allergies')->nullable();
            $table->text('notes')->nullable();
            $table->string('acquisition_source')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
