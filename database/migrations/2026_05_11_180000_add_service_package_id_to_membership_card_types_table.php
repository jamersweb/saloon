<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('membership_card_types', function (Blueprint $table) {
            $table->foreignId('service_package_id')->nullable()->after('validity_days')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('membership_card_types', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_package_id');
        });
    }
};
