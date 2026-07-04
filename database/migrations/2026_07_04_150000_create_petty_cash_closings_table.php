<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_profile_id')->nullable()->constrained()->nullOnDelete();
            $table->date('closing_date');
            $table->decimal('opening_balance', 12, 2)->default(0);
            $table->decimal('issued_total', 12, 2)->default(0);
            $table->decimal('spent_total', 12, 2)->default(0);
            $table->decimal('expected_closing_balance', 12, 2)->default(0);
            $table->decimal('counted_closing_balance', 12, 2)->default(0);
            $table->decimal('variance_amount', 12, 2)->default(0);
            $table->string('signed_off_name')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('variance_entry_id')->nullable()->constrained('petty_cash_entries')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_closings');
    }
};
