<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_invoices', function (Blueprint $table) {
            $table->foreignId('related_invoice_id')->nullable()->after('appointment_id')->constrained('tax_invoices')->nullOnDelete();
            $table->string('adjustment_type', 64)->nullable()->after('related_invoice_id');
            $table->text('adjustment_reason')->nullable()->after('adjustment_type');
        });
    }

    public function down(): void
    {
        Schema::table('tax_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('related_invoice_id');
            $table->dropColumn(['adjustment_type', 'adjustment_reason']);
        });
    }
};
