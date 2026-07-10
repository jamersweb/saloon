<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_entries', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('purchase_order_id')->constrained('campaigns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expense_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
        });
    }
};
