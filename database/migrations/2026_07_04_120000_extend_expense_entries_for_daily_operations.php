<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_entries', function (Blueprint $table) {
            $table->string('expense_type', 64)->default('operational')->after('category');
            $table->string('expense_subcategory', 64)->nullable()->after('expense_type');
            $table->string('payment_method', 32)->default('cash')->after('payment_status');
            $table->string('receipt_number')->nullable()->after('payment_method');
            $table->string('receipt_image_path')->nullable()->after('receipt_number');
            $table->foreignId('staff_profile_id')->nullable()->after('purchase_order_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expense_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staff_profile_id');
            $table->dropColumn([
                'expense_type',
                'expense_subcategory',
                'payment_method',
                'receipt_number',
                'receipt_image_path',
            ]);
        });
    }
};
