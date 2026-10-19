<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salon_services', function (Blueprint $table) {
            $table->string('due_reminder_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('salon_services', fn (Blueprint $table) => $table->dropColumn('due_reminder_key'));
    }
};
