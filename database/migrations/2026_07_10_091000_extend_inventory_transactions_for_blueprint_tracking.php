<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->string('classification', 64)->default('manual_adjustment')->after('type');
            $table->string('source_type', 64)->nullable()->after('classification');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            $table->index(['classification', 'created_at']);
            $table->index(['source_type', 'source_id']);
        });

        DB::table('inventory_transactions')
            ->whereNull('classification')
            ->update(['classification' => 'manual_adjustment']);
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropIndex(['classification', 'created_at']);
            $table->dropIndex(['source_type', 'source_id']);
            $table->dropColumn(['classification', 'source_type', 'source_id']);
        });
    }
};
