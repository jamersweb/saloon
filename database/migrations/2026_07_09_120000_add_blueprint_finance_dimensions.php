<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_entries', function (Blueprint $table) {
            $table->string('cost_center', 64)->default('general_salon')->after('category');
        });

        Schema::table('tax_invoice_items', function (Blueprint $table) {
            $table->string('revenue_category', 64)->default('service_income')->after('salon_service_id');
            $table->string('cost_center', 64)->default('general_salon')->after('revenue_category');
        });

        DB::table('expense_entries')
            ->whereNull('cost_center')
            ->update(['cost_center' => 'general_salon']);

        DB::table('tax_invoice_items')
            ->whereNull('revenue_category')
            ->update(['revenue_category' => 'service_income']);

        DB::table('tax_invoice_items')
            ->whereNull('cost_center')
            ->update(['cost_center' => 'general_salon']);
    }

    public function down(): void
    {
        Schema::table('tax_invoice_items', function (Blueprint $table) {
            $table->dropColumn(['revenue_category', 'cost_center']);
        });

        Schema::table('expense_entries', function (Blueprint $table) {
            $table->dropColumn('cost_center');
        });
    }
};
