<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_invoice_items', function (Blueprint $table) {
            $table->foreignId('staff_profile_id')
                ->nullable()
                ->after('salon_service_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tax_invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('staff_profile_id');
        });
    }
};
