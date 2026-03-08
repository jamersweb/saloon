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
        Schema::table('salon_services', function (Blueprint $table) {
            $table->unsignedInteger('repeat_after_days')->nullable()->after('buffer_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salon_services', function (Blueprint $table) {
            $table->dropColumn('repeat_after_days');
        });
    }
};
