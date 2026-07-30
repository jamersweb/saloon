<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gift_card_transactions', function (Blueprint $table) {
            $table->foreignId('tax_invoice_id')
                ->nullable()
                ->after('appointment_id')
                ->constrained('tax_invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gift_card_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tax_invoice_id');
        });
    }
};
