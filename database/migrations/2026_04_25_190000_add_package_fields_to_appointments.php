<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('customer_package_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->boolean('package_session_applied')->default(false)->after('exclude_loyalty_earn');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_package_id');
            $table->dropColumn('package_session_applied');
        });
    }
};
